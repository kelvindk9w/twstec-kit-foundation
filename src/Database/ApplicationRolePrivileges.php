<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Os privilégios do PAPEL DA APLICAÇÃO, aplicados pelo papel de MIGRAÇÃO
 * (dono do esquema) — no fim de todo `migrate` com separação de papéis (ver
 * DatabaseRoles). Idempotente.
 *
 * 1. O papel existe: se faltar e o dono puder criar papéis (superusuário ou
 *    CREATEROLE), é criado com a senha da conexão da aplicação, sem nenhum
 *    atributo especial; com o dono superusuário, os atributos e a senha são
 *    reafirmados a cada `migrate`. Sem poder criar, o `migrate` falha com a
 *    instrução (o administrador do banco cria antes — docs/producao.md).
 * 2. Uso do esquema, sem CREATE; SELECT, INSERT, UPDATE e DELETE em todas as
 *    tabelas e USAGE/SELECT nas sequências, e os mesmos privilégios padrão
 *    para as tabelas que o dono criar depois.
 * 3. Tabela de migrations: só leitura.
 * 4. Tabelas só-acréscimo (AppendOnlyTables): tudo revogado e devolvido só
 *    SELECT, INSERT e o UPDATE das colunas liberadas.
 *
 * O SQL equivalente, para quem prefere aplicar à mão, está em
 * docs/producao.md ("Papéis do PostgreSQL").
 */
final class ApplicationRolePrivileges
{
    public function sync(Connection $owner, string $role, string $password): void
    {
        if ($owner->getDriverName() !== 'pgsql') {
            return;
        }

        if ($role === '') {
            throw new RuntimeException('Separação de papéis: a conexão da aplicação não tem usuário (DB_USERNAME).');
        }

        LoginRole::ensure($owner, $role, $password, 'o papel da aplicação (DB_USERNAME)');

        $quotedRole = self::identifier($role);
        $schema = (string) $owner->scalar('SELECT current_schema()');
        $database = (string) $owner->scalar('SELECT current_database()');
        $quotedSchema = self::identifier($schema);

        $owner->transaction(function () use ($owner, $quotedRole, $quotedSchema, $schema, $database): void {
            $owner->statement('GRANT CONNECT, TEMPORARY ON DATABASE '.self::identifier($database).' TO '.$quotedRole);
            $owner->statement("GRANT USAGE ON SCHEMA {$quotedSchema} TO {$quotedRole}");
            $owner->statement("REVOKE CREATE ON SCHEMA {$quotedSchema} FROM {$quotedRole}");
            $owner->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$quotedSchema} TO {$quotedRole}");
            $owner->statement("GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA {$quotedSchema} TO {$quotedRole}");
            $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$quotedSchema} GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$quotedRole}");
            $owner->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA {$quotedSchema} GRANT USAGE, SELECT ON SEQUENCES TO {$quotedRole}");

            $migrations = (string) config('database.migrations.table', 'migrations');

            if (self::tableExists($owner, $migrations, $schema)) {
                $owner->statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE, TRIGGER ON TABLE '.self::qualified($migrations, $schema)." FROM {$quotedRole}");
            }

            foreach (AppendOnlyTables::all() as $table => $definition) {
                if (! self::tableExists($owner, $table, $schema)) {
                    continue;
                }

                $qualified = self::qualified($table, $schema);

                $owner->statement("REVOKE ALL ON TABLE {$qualified} FROM {$quotedRole}");
                $owner->statement('GRANT '.implode(', ', AppendOnlyTables::REQUIRED_PRIVILEGES)." ON TABLE {$qualified} TO {$quotedRole}");

                if ($definition['updatable_columns'] !== []) {
                    $columns = implode(', ', array_map(self::identifier(...), $definition['updatable_columns']));
                    $owner->statement("GRANT UPDATE ({$columns}) ON TABLE {$qualified} TO {$quotedRole}");
                }
            }
        });
    }

    /**
     * A tabela existe (no esquema dado, se o nome não trouxer outro)?
     */
    public static function tableExists(Connection $owner, string $table, string $schema): bool
    {
        return $owner->scalar('SELECT to_regclass(?) IS NOT NULL', [self::qualified($table, $schema)]) === true;
    }

    /**
     * Identificador entre aspas duplas (nome de papel, esquema, coluna).
     */
    public static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /**
     * `esquema.tabela` entre aspas; tabela sem esquema fica no esquema da
     * conexão.
     */
    public static function qualified(string $table, string $schema): string
    {
        $parts = str_contains($table, '.') ? explode('.', $table, 2) : [$schema, $table];

        return self::identifier($parts[0]).'.'.self::identifier($parts[1]);
    }
}
