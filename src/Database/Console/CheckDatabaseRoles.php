<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database\Console;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Throwable;
use Twstec\Kit\Foundation\Database\AppendOnlyTables;
use Twstec\Kit\Foundation\Database\DatabaseRoles;
use Twstec\Kit\Foundation\Database\MaintenanceRoleInspection;
use Twstec\Kit\Foundation\Database\MaintenanceTables;
use Twstec\Kit\Foundation\Database\RoleInspection;

/**
 * Confere o papel com que a APLICAÇÃO entra no PostgreSQL (RoleInspection):
 * sai com erro se ele for superusuário, dono do banco, do esquema ou de uma
 * tabela só-acréscimo, se puder criar objetos no esquema ou se tiver
 * privilégio proibido numa tabela registrada (AppendOnlyTables). Com o papel
 * de manutenção definido (DB_MAINTENANCE_USERNAME), confere também ele
 * (MaintenanceRoleInspection): existe, não é dono de nada e só tem os
 * privilégios das podas.
 *
 * Para o deploy (depois do migrate, com as credenciais da aplicação) e para
 * o monitoramento: código 0 = tudo certo; 1 = problema (a lista sai na
 * tela); 2 = não deu para conferir (conexão que não é PostgreSQL, banco fora
 * do ar).
 */
final class CheckDatabaseRoles extends Command
{
    protected $signature = 'db:check-roles {--connection= : Conexão conferida (padrão: a da aplicação)}';

    protected $description = 'Confere se o papel da aplicação no PostgreSQL não é dono do esquema nem tem privilégio proibido nas tabelas só-acréscimo';

    public function handle(DatabaseManager $db, RoleInspection $inspection, MaintenanceRoleInspection $maintenance, DatabaseRoles $roles): int
    {
        $name = (string) ($this->option('connection') ?: config('database.default'));

        // `--connection=migration` (conferir o dono): a conexão que o pacote
        // monta para os comandos de estrutura.
        if ($name === config('database_roles.migration_connection') && $roles->separated()) {
            $roles->migrationConnectionName();
        }

        try {
            $connection = $db->connection($name);

            if ($connection->getDriverName() !== 'pgsql') {
                $this->error(__('database_roles.not_pgsql', ['connection' => $name, 'driver' => $connection->getDriverName()]));

                return self::INVALID;
            }

            $problems = $inspection->problems($connection);
            $role = (string) $connection->scalar('SELECT current_user');
            $maintenanceRole = $roles->maintenanceConfigured() ? $roles->maintenanceUsername() : null;
            $maintenanceProblems = $maintenanceRole !== null ? $maintenance->problems($connection, $maintenanceRole) : [];
        } catch (Throwable $exception) {
            $this->error(__('database_roles.unreachable', ['connection' => $name, 'error' => $exception->getMessage()]));

            return self::INVALID;
        }

        if ($problems !== []) {
            $this->error(__('database_roles.failed', ['connection' => $name, 'role' => $role, 'count' => count($problems)]));

            foreach ($problems as [$code, $details]) {
                $this->line('  - '.__("database_roles.problems.{$code}", $details));
            }
        }

        if ($maintenanceProblems !== []) {
            $this->error(__('database_roles.maintenance.failed', ['role' => (string) $maintenanceRole, 'count' => count($maintenanceProblems)]));

            foreach ($maintenanceProblems as [$code, $details]) {
                $this->line('  - '.__("database_roles.maintenance.problems.{$code}", $details));
            }
        }

        if ($problems !== [] || $maintenanceProblems !== []) {
            $this->line(__('database_roles.how_to_fix'));

            return self::FAILURE;
        }

        $this->info(__('database_roles.passed', [
            'connection' => $name,
            'role' => $role,
            'tables' => implode(', ', array_keys(AppendOnlyTables::all())),
        ]));

        if ($maintenanceRole !== null) {
            $this->info(__('database_roles.maintenance.passed', [
                'role' => $maintenanceRole,
                'tables' => implode(', ', array_keys(MaintenanceTables::all())),
            ]));
        }

        return self::SUCCESS;
    }
}
