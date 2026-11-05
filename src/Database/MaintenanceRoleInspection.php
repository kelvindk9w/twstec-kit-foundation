<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Database\Connection;

/**
 * A conferência do PAPEL DE MANUTENÇÃO, feita pelo nome a partir de qualquer
 * conexão (as funções `has_*_privilege` e `pg_has_role` respondem por outro
 * papel) — sem precisar da senha dele. Cada problema volta como [código,
 * detalhes] (mensagens em lang/<idioma>/database_roles.php, `maintenance.*`).
 *
 * Problema = o papel pode mais do que podar:
 *
 * - não existe (o `migrate` com DB_MAINTENANCE_* o cria);
 * - é superusuário, pode criar papéis ou bancos, ignora o RLS;
 * - é (ou é membro de) dono do banco, do esquema ou de alguma tabela;
 * - pode criar objetos no esquema;
 * - tem, em qualquer tabela do esquema, privilégio fora de MaintenanceTables
 *   (inclusive UPDATE de coluna);
 * - não tem um privilégio da lista numa tabela de poda (a poda quebraria).
 */
final class MaintenanceRoleInspection
{
    /**
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public function problems(Connection $connection, string $role): array
    {
        $attributes = $connection->selectOne(
            'SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls FROM pg_roles WHERE rolname = ?',
            [$role],
        );

        if ($attributes === null) {
            return [['missing', ['role' => $role]]];
        }

        $problems = [];

        foreach (['rolsuper' => 'superuser', 'rolcreaterole' => 'createrole', 'rolcreatedb' => 'createdb', 'rolbypassrls' => 'bypassrls'] as $column => $code) {
            if ((bool) $attributes->{$column}) {
                $problems[] = [$code, ['role' => $role]];
            }
        }

        if ((bool) $attributes->rolsuper) {
            return $problems;
        }

        $context = $connection->selectOne(<<<'SQL'
            SELECT current_database() AS database_name, current_schema() AS schema_name,
                   pg_has_role(?, d.datdba, 'MEMBER') AS owns_database,
                   pg_has_role(?, n.nspowner, 'MEMBER') AS owns_schema,
                   has_schema_privilege(?, n.oid, 'CREATE') AS schema_create
            FROM pg_database d, pg_namespace n
            WHERE d.datname = current_database() AND n.nspname = current_schema()
            SQL, [$role, $role, $role]);

        $schema = (string) $context->schema_name;

        if ((bool) $context->owns_database) {
            $problems[] = ['owns_database', ['role' => $role, 'database' => (string) $context->database_name]];
        }

        if ((bool) $context->owns_schema) {
            $problems[] = ['owns_schema', ['role' => $role, 'schema' => $schema]];
        } elseif ((bool) $context->schema_create) {
            $problems[] = ['schema_create', ['role' => $role, 'schema' => $schema]];
        }

        $expected = [];

        foreach (MaintenanceTables::all() as $table => $privileges) {
            $expected[ApplicationRolePrivileges::qualified($table, $schema)] = [$table, $privileges];
        }

        $tables = $connection->select(<<<'SQL'
            SELECT c.oid, c.relname, pg_has_role(?, c.relowner, 'MEMBER') AS owns
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')
            ORDER BY c.relname
            SQL, [$role]);

        $present = [];

        foreach ($tables as $table) {
            $name = (string) $table->relname;
            $key = ApplicationRolePrivileges::qualified($name, $schema);
            $allowed = $expected[$key][1] ?? [];
            $present[$key] = true;

            if ((bool) $table->owns) {
                $problems[] = ['owns_table', ['role' => $role, 'table' => $name]];
            }

            foreach (MaintenanceTables::CHECKED_PRIVILEGES as $privilege) {
                $has = $connection->scalar('SELECT has_table_privilege(?, ?::oid, ?)', [$role, $table->oid, $privilege]) === true;

                if ($has && ! in_array($privilege, $allowed, true)) {
                    $problems[] = ['extra_privilege', ['role' => $role, 'table' => $name, 'privilege' => $privilege]];
                } elseif (! $has && in_array($privilege, $allowed, true)) {
                    $problems[] = ['missing_privilege', ['role' => $role, 'table' => $name, 'privilege' => $privilege]];
                }
            }

            if (! in_array('UPDATE', $allowed, true)
                && $connection->scalar('SELECT has_table_privilege(?, ?::oid, \'UPDATE\')', [$role, $table->oid]) !== true
                && $connection->scalar('SELECT has_any_column_privilege(?, ?::oid, \'UPDATE\')', [$role, $table->oid]) === true) {
                $problems[] = ['column_update', ['role' => $role, 'table' => $name]];
            }
        }

        foreach ($expected as $key => [$table]) {
            if (! isset($present[$key])) {
                $problems[] = ['table_missing', ['table' => $table]];
            }
        }

        return $problems;
    }
}
