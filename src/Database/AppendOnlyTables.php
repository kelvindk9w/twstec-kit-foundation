<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use InvalidArgumentException;

/**
 * O registro das tabelas SÓ-ACRÉSCIMO: as do kit (fixas) e as que o projeto
 * declara em `database_roles.append_only_tables`.
 *
 * O papel da aplicação fica, em cada uma, com SELECT e INSERT — e UPDATE só
 * nas colunas listadas em `updatable_columns` (as transições controladas,
 * como o fim de ciclo de `request_logs`). UPDATE na tabela inteira, DELETE,
 * TRUNCATE e TRIGGER são proibidos: quem aplica é o
 * ApplicationRolePrivileges (no fim de todo `migrate`) e quem confere é o
 * `php artisan db:check-roles`.
 *
 * As podas por idade (audit:prune, outbound-http:prune) apagam com o papel
 * de migração (ver DatabaseRoles).
 */
final class AppendOnlyTables
{
    /**
     * As tabelas só-acréscimo do kit. O projeto não as tira.
     *
     * @var array<string, array{updatable_columns: list<string>}>
     */
    public const KIT = [
        'audit_events' => ['updatable_columns' => []],
        'outbound_http_logs' => ['updatable_columns' => []],
        // O ciclo de vida da requisição: RequestLog::markFinished() e
        // RequestLog::bindTenant() — nada além disso.
        'request_logs' => ['updatable_columns' => ['status', 'http_status_response', 'error_message', 'duration_ms', 'tenant_uuid']],
    ];

    /**
     * Privilégios que o papel da aplicação nunca tem numa tabela registrada
     * (UPDATE vale para a tabela inteira; coluna liberada é conferida à
     * parte).
     *
     * @var list<string>
     */
    public const FORBIDDEN_PRIVILEGES = ['UPDATE', 'DELETE', 'TRUNCATE', 'TRIGGER'];

    /**
     * Privilégios de que a aplicação precisa numa tabela registrada.
     *
     * @var list<string>
     */
    public const REQUIRED_PRIVILEGES = ['SELECT', 'INSERT'];

    /**
     * Kit + projeto, por nome (o do projeto soma colunas, nunca tira).
     *
     * @return array<string, array{updatable_columns: list<string>}>
     */
    public static function all(): array
    {
        $tables = self::KIT;

        foreach (self::fromConfig() as $table => $definition) {
            $columns = array_merge($tables[$table]['updatable_columns'] ?? [], $definition['updatable_columns']);
            $tables[$table] = ['updatable_columns' => array_values(array_unique($columns))];
        }

        ksort($tables);

        return $tables;
    }

    /**
     * @return array<string, array{updatable_columns: list<string>}>
     */
    private static function fromConfig(): array
    {
        $declared = config('database_roles.append_only_tables', []);

        if (! is_array($declared)) {
            throw new InvalidArgumentException('database_roles.append_only_tables precisa ser uma lista de tabelas.');
        }

        $tables = [];

        foreach ($declared as $key => $value) {
            [$table, $definition] = is_int($key) ? [$value, []] : [$key, $value];

            if (! is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $table) !== 1) {
                throw new InvalidArgumentException(sprintf('database_roles.append_only_tables: nome de tabela inválido (%s).', var_export($table, true)));
            }

            $columns = is_array($definition) ? ($definition['updatable_columns'] ?? []) : null;

            if (! is_array($columns) || array_filter($columns, fn (mixed $column): bool => ! is_string($column) || $column === '') !== []) {
                throw new InvalidArgumentException("database_roles.append_only_tables.{$table}: updatable_columns precisa ser uma lista de colunas.");
            }

            $tables[$table] = ['updatable_columns' => array_values(array_map(strval(...), $columns))];
        }

        return $tables;
    }
}
