<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Database\Connection;

/**
 * A conferência do papel com que uma conexão entra no PostgreSQL — a base do
 * `php artisan db:check-roles`. Cada problema volta como [código, detalhes]
 * (as mensagens ficam em lang/<idioma>/database_roles.php).
 *
 * Problema = o papel pode desfazer a proteção das tabelas só-acréscimo ou
 * mudar a estrutura do banco:
 *
 * - é superusuário, pode criar papéis ou ignora o Row-Level Security;
 * - é (ou é membro de) dono do banco, do esquema ou de uma tabela registrada
 *   — o dono desliga gatilho e apaga tabela;
 * - pode criar objetos no esquema;
 * - tem UPDATE (na tabela inteira ou em coluna não liberada), DELETE,
 *   TRUNCATE ou TRIGGER numa tabela registrada;
 * - não tem SELECT ou INSERT numa tabela registrada (a aplicação quebraria),
 *   ou a tabela registrada não existe.
 *
 * Os privilégios contam do jeito que o PostgreSQL decide: concedidos ao
 * papel, a PUBLIC ou a um papel de que ele herda.
 */
final class RoleInspection
{
    /**
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public function problems(Connection $connection): array
    {
        $role = $connection->selectOne(<<<'SQL'
            SELECT r.rolname, r.rolsuper, r.rolcreaterole, r.rolbypassrls,
                   pg_has_role(current_user, d.datdba, 'MEMBER') AS owns_database,
                   current_database() AS database_name,
                   current_schema() AS schema_name
            FROM pg_roles r, pg_database d
            WHERE r.rolname = current_user AND d.datname = current_database()
            SQL);

        $name = (string) $role->rolname;
        $schema = (string) $role->schema_name;
        $problems = [];

        foreach (['rolsuper' => 'superuser', 'rolcreaterole' => 'createrole', 'rolbypassrls' => 'bypassrls'] as $column => $code) {
            if ((bool) $role->{$column}) {
                $problems[] = [$code, ['role' => $name]];
            }
        }

        // Superusuário passa por cima de todo privilégio: a lista por tabela
        // só repetiria o mesmo problema.
        if ((bool) $role->rolsuper) {
            return $problems;
        }

        if ((bool) $role->owns_database) {
            $problems[] = ['owns_database', ['role' => $name, 'database' => (string) $role->database_name]];
        }

        $namespace = $connection->selectOne(<<<'SQL'
            SELECT pg_has_role(current_user, n.nspowner, 'MEMBER') AS owns,
                   has_schema_privilege(current_user, n.oid, 'CREATE') AS can_create
            FROM pg_namespace n
            WHERE n.nspname = ?
            SQL, [$schema]);

        if ($namespace !== null && (bool) $namespace->owns) {
            $problems[] = ['owns_schema', ['role' => $name, 'schema' => $schema]];
        } elseif ($namespace !== null && (bool) $namespace->can_create) {
            $problems[] = ['schema_create', ['role' => $name, 'schema' => $schema]];
        }

        foreach (AppendOnlyTables::all() as $table => $definition) {
            array_push($problems, ...$this->tableProblems($connection, $name, $schema, $table, $definition['updatable_columns']));
        }

        return $problems;
    }

    /**
     * @param  list<string>  $updatable
     * @return list<array{0: string, 1: array<string, string>}>
     */
    private function tableProblems(Connection $connection, string $role, string $schema, string $table, array $updatable): array
    {
        $qualified = ApplicationRolePrivileges::qualified($table, $schema);
        $relation = $connection->selectOne(<<<'SQL'
            SELECT c.oid, pg_has_role(current_user, c.relowner, 'MEMBER') AS owns
            FROM pg_class c
            WHERE c.oid = to_regclass(?)
            SQL, [$qualified]);

        if ($relation === null) {
            return [['table_missing', ['table' => $table]]];
        }

        $problems = [];

        if ((bool) $relation->owns) {
            $problems[] = ['owns_table', ['role' => $role, 'table' => $table]];
        }

        $tableUpdate = false;

        foreach (AppendOnlyTables::FORBIDDEN_PRIVILEGES as $privilege) {
            if ($connection->scalar('SELECT has_table_privilege(?::oid, ?)', [$relation->oid, $privilege]) === true) {
                $problems[] = ['forbidden_privilege', ['role' => $role, 'table' => $table, 'privilege' => $privilege]];
                $tableUpdate = $tableUpdate || $privilege === 'UPDATE';
            }
        }

        foreach (AppendOnlyTables::REQUIRED_PRIVILEGES as $privilege) {
            if ($connection->scalar('SELECT has_table_privilege(?::oid, ?)', [$relation->oid, $privilege]) !== true) {
                $problems[] = ['missing_privilege', ['role' => $role, 'table' => $table, 'privilege' => $privilege]];
            }
        }

        // UPDATE na tabela inteira já vale para toda coluna.
        if ($tableUpdate) {
            return $problems;
        }

        $columns = $connection->select(<<<'SQL'
            SELECT a.attname
            FROM pg_attribute a
            WHERE a.attrelid = ?::oid AND a.attnum > 0 AND NOT a.attisdropped
              AND has_column_privilege(a.attrelid, a.attnum, 'UPDATE')
            ORDER BY a.attnum
            SQL, [$relation->oid]);

        foreach ($columns as $column) {
            if (! in_array((string) $column->attname, $updatable, true)) {
                $problems[] = ['column_update', ['role' => $role, 'table' => $table, 'column' => (string) $column->attname]];
            }
        }

        return $problems;
    }
}
