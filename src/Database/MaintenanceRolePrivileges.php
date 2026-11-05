<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Os privilégios do PAPEL DE MANUTENÇÃO (DB_MAINTENANCE_USERNAME), aplicados
 * pelo papel de MIGRAÇÃO no fim de todo `migrate` com o papel de manutenção
 * definido (ver DatabaseRoles). Idempotente e EXATO: o que não está na lista
 * é revogado a cada `migrate`.
 *
 * 1. O papel existe, sem atributo especial (LoginRole).
 * 2. Conecta e usa o esquema, sem CREATE.
 * 3. Nenhum privilégio em tabela ou sequência do esquema...
 * 4. ...exceto nas tabelas de MaintenanceTables: SELECT e DELETE (e INSERT
 *    onde a poda grava o rastro, com o uso da sequência da chave).
 *
 * Nada de privilégio padrão para tabelas futuras: tabela nova só entra pela
 * lista. O SQL equivalente, para quem prefere aplicar à mão, está em
 * docs/producao.md ("Papel de manutenção").
 */
final class MaintenanceRolePrivileges
{
    public function sync(Connection $owner, string $role, string $password): void
    {
        if ($owner->getDriverName() !== 'pgsql') {
            return;
        }

        if ($role === '') {
            throw new RuntimeException('Papel de manutenção: DB_MAINTENANCE_USERNAME vazio.');
        }

        LoginRole::ensure($owner, $role, $password, 'o papel de manutenção (DB_MAINTENANCE_USERNAME)');

        $quotedRole = ApplicationRolePrivileges::identifier($role);
        $schema = (string) $owner->scalar('SELECT current_schema()');
        $database = (string) $owner->scalar('SELECT current_database()');
        $quotedSchema = ApplicationRolePrivileges::identifier($schema);

        $owner->transaction(function () use ($owner, $quotedRole, $quotedSchema, $schema, $database): void {
            $owner->statement('GRANT CONNECT ON DATABASE '.ApplicationRolePrivileges::identifier($database).' TO '.$quotedRole);
            $owner->statement("GRANT USAGE ON SCHEMA {$quotedSchema} TO {$quotedRole}");
            $owner->statement("REVOKE CREATE ON SCHEMA {$quotedSchema} FROM {$quotedRole}");
            $owner->statement("REVOKE ALL ON ALL TABLES IN SCHEMA {$quotedSchema} FROM {$quotedRole}");
            $owner->statement("REVOKE ALL ON ALL SEQUENCES IN SCHEMA {$quotedSchema} FROM {$quotedRole}");

            foreach (MaintenanceTables::all() as $table => $privileges) {
                if (! ApplicationRolePrivileges::tableExists($owner, $table, $schema)) {
                    continue;
                }

                $qualified = ApplicationRolePrivileges::qualified($table, $schema);
                $owner->statement('GRANT '.implode(', ', $privileges)." ON TABLE {$qualified} TO {$quotedRole}");

                if (in_array('INSERT', $privileges, true)) {
                    foreach ($this->ownedSequences($owner, $qualified) as $sequence) {
                        $owner->statement("GRANT USAGE ON SEQUENCE {$sequence} TO {$quotedRole}");
                    }
                }
            }
        });
    }

    /**
     * As sequências das colunas da tabela (serial/identity), já entre aspas.
     *
     * @return list<string>
     */
    private function ownedSequences(Connection $owner, string $qualified): array
    {
        $rows = $owner->select(<<<'SQL'
            SELECT quote_ident(n.nspname) || '.' || quote_ident(s.relname) AS name
            FROM pg_depend d
            JOIN pg_class s ON s.oid = d.objid AND s.relkind = 'S'
            JOIN pg_namespace n ON n.oid = s.relnamespace
            WHERE d.refobjid = to_regclass(?) AND d.deptype IN ('a', 'i')
            SQL, [$qualified]);

        return array_map(strval(...), array_column(array_map(fn (object $row): array => (array) $row, $rows), 'name'));
    }
}
