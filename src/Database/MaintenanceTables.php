<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use InvalidArgumentException;

/**
 * As tabelas que o PAPEL DE MANUTENÇÃO poda: as trilhas do kit com poda por
 * idade e as que o projeto declara em `database_roles.maintenance_tables`
 * (no esquema da conexão; sem prefixo de esquema).
 *
 * Em cada uma o papel fica com SELECT e DELETE — e INSERT só onde a poda
 * grava o próprio rastro (`audit_events`: `audit:prune` e
 * `outbound-http:prune` registram quantas linhas saíram). Em qualquer outra
 * tabela do esquema, nada: nem as do domínio do projeto, nem as só-acréscimo
 * sem poda (como `request_logs`). Quem aplica é o MaintenanceRolePrivileges
 * (no fim de todo `migrate`) e quem confere é o `php artisan db:check-roles`.
 */
final class MaintenanceTables
{
    /**
     * As trilhas do kit com poda. O projeto não as tira.
     *
     * @var array<string, list<string>>
     */
    public const KIT = [
        'audit_events' => ['SELECT', 'INSERT', 'DELETE'],
        'outbound_http_logs' => ['SELECT', 'DELETE'],
    ];

    /**
     * O que o projeto declara recebe só isto.
     *
     * @var list<string>
     */
    public const PROJECT_PRIVILEGES = ['SELECT', 'DELETE'];

    /**
     * Privilégios de tabela conferidos (os do PostgreSQL, menos MAINTAIN,
     * que só existe a partir da versão 17).
     *
     * @var list<string>
     */
    public const CHECKED_PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];

    /**
     * Kit + projeto: tabela => privilégios esperados.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        $tables = self::KIT;
        $declared = config('database_roles.maintenance_tables', []);

        if (! is_array($declared)) {
            throw new InvalidArgumentException('database_roles.maintenance_tables precisa ser uma lista de tabelas.');
        }

        foreach ($declared as $table) {
            if (! is_string($table) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table) !== 1) {
                throw new InvalidArgumentException(sprintf('database_roles.maintenance_tables: nome de tabela inválido (%s).', var_export($table, true)));
            }

            $tables[$table] ??= self::PROJECT_PRIVILEGES;
        }

        ksort($tables);

        return $tables;
    }
}
