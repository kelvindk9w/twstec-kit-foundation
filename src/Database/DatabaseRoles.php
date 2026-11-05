<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Database;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\ConfigurationUrlParser;

/**
 * Papel de MIGRAÇÃO × papel da APLICAÇÃO no PostgreSQL (ver
 * config/database_roles.php e docs/producao.md).
 *
 * Há SEPARAÇÃO quando a conexão padrão é PostgreSQL e DB_MIGRATION_USERNAME
 * está definido e é diferente do usuário dela. Com separação:
 *
 * - os comandos de `database_roles.migration_commands` (migrate*, db:wipe,
 *   as podas das trilhas) sem `--database` rodam com a conexão de migração —
 *   a conexão padrão do processo passa a ser ela enquanto o comando roda;
 * - todo `migrate` (com ou sem migration pendente) termina acertando os
 *   privilégios do papel da aplicação (ApplicationRolePrivileges).
 *
 * Sem separação, nada muda: tudo roda com a conexão padrão.
 *
 * PAPEL DE MANUTENÇÃO (opcional, DB_MAINTENANCE_USERNAME): com ele definido
 * (PostgreSQL, diferente do papel da aplicação e do de migração), os
 * comandos de `database_roles.maintenance_commands` (as podas) rodam com a
 * conexão de manutenção — que só apaga nas trilhas com poda — e não com a do
 * dono; o processo que as agenda (o scheduler) não precisa da senha do dono.
 * Todo `migrate` com o papel definido também acerta os privilégios dele
 * (MaintenanceRolePrivileges). Sem ele, as podas continuam com o papel de
 * migração, como antes.
 */
final class DatabaseRoles
{
    /**
     * O papel da aplicação enquanto um comando roda com a conexão de
     * migração: [conexão padrão anterior, usuário dela].
     *
     * O comando que desviou vai junto, para o fim de um comando que NÃO foi
     * desviado (com `--database`) não devolver a conexão de outro.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private array $routed = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Há separação de papéis neste processo?
     */
    public function separated(): bool
    {
        $connection = $this->applicationConnectionName();

        if (config("database.connections.{$connection}.driver") !== 'pgsql') {
            return false;
        }

        $migrationUser = $this->migrationUsername();

        return $migrationUser !== '' && $migrationUser !== $this->applicationRole();
    }

    public function migrationUsername(): string
    {
        return trim((string) config('database_roles.migration.username', ''));
    }

    public function maintenanceUsername(): string
    {
        return trim((string) config('database_roles.maintenance.username', ''));
    }

    /**
     * O papel de manutenção está definido neste processo? (PostgreSQL, e
     * diferente do papel da aplicação e do de migração.)
     */
    public function maintenanceConfigured(): bool
    {
        if (config('database.connections.'.$this->applicationConnectionName().'.driver') !== 'pgsql') {
            return false;
        }

        $maintenance = $this->maintenanceUsername();

        return $maintenance !== ''
            && $maintenance !== $this->applicationRole()
            && $maintenance !== $this->migrationUsername();
    }

    /**
     * A conexão da aplicação: a padrão (ou, durante um comando desviado, a
     * que era a padrão antes dele).
     */
    public function applicationConnectionName(): string
    {
        $last = end($this->routed);

        return $last !== false ? $last[0] : (string) config('database.default');
    }

    /**
     * O papel (usuário) com que a aplicação conecta.
     */
    public function applicationRole(): string
    {
        $last = end($this->routed);

        if ($last !== false) {
            return $last[1];
        }

        return (string) ($this->resolvedConfig($this->applicationConnectionName())['username'] ?? '');
    }

    /**
     * A conexão de migração, montada a partir da da aplicação quando o
     * config/database.php não a define.
     */
    public function migrationConnectionName(): string
    {
        $name = (string) config('database_roles.migration_connection', 'migration');

        if (config("database.connections.{$name}") === null) {
            $config = $this->resolvedConfig($this->applicationConnectionName());
            $config['username'] = $this->migrationUsername();
            $config['password'] = (string) config('database_roles.migration.password', '');

            config(["database.connections.{$name}" => $config]);
        }

        return $name;
    }

    /**
     * A conexão de manutenção, montada como a de migração (a do aplicativo,
     * se o config/database.php a definir; senão, a da aplicação com o
     * usuário e a senha de manutenção).
     */
    public function maintenanceConnectionName(): string
    {
        $name = (string) config('database_roles.maintenance_connection', 'maintenance');

        if (config("database.connections.{$name}") === null) {
            $config = $this->resolvedConfig($this->applicationConnectionName());
            $config['username'] = $this->maintenanceUsername();
            $config['password'] = (string) config('database_roles.maintenance.password', '');

            config(["database.connections.{$name}" => $config]);
        }

        return $name;
    }

    /**
     * CommandStarting: desvia os comandos das listas que não escolheram a
     * conexão — as podas para a de manutenção (se definida), o resto para a
     * de migração.
     */
    public function commandStarting(CommandStarting $event): void
    {
        $target = $this->routeFor($event);

        if ($target === null) {
            return;
        }

        $this->routed[] = [$this->applicationConnectionName(), $this->applicationRole(), (string) $event->command];
        $this->db->setDefaultConnection($target);
    }

    /**
     * CommandFinished: devolve a conexão padrão de antes do comando.
     */
    public function commandFinished(CommandFinished $event): void
    {
        $last = end($this->routed);

        if ($last === false || $last[2] !== $event->command) {
            return;
        }

        array_pop($this->routed);
        $this->db->setDefaultConnection($last[0]);
    }

    /**
     * MigrationsEnded / NoPendingMigrations: os privilégios do papel da
     * aplicação no estado em que o banco ficou.
     */
    public function migrationsFinished(MigrationsEnded|NoPendingMigrations $event): void
    {
        if ($event instanceof MigrationsEnded && ($event->options['pretend'] ?? false) === true) {
            return;
        }

        if (! $this->separated()) {
            return;
        }

        $applicationConnection = $this->applicationConnectionName();
        $owner = $this->db->connection($this->migrationConnectionName());

        app(ApplicationRolePrivileges::class)->sync(
            $owner,
            $this->applicationRole(),
            (string) ($this->resolvedConfig($applicationConnection)['password'] ?? ''),
        );

        if ($this->maintenanceConfigured()) {
            app(MaintenanceRolePrivileges::class)->sync(
                $owner,
                $this->maintenanceUsername(),
                (string) config('database_roles.maintenance.password', ''),
            );
        }
    }

    /**
     * A conexão para onde o comando vai, ou null (fica na padrão).
     */
    private function routeFor(CommandStarting $event): ?string
    {
        $command = (string) $event->command;

        // `--database` explícito: quem chamou escolheu.
        if ($event->input->hasOption('database') && filled($event->input->getOption('database'))) {
            return null;
        }

        if ($this->inList('database_roles.maintenance_commands', $command) && $this->maintenanceConfigured()) {
            return $this->maintenanceConnectionName();
        }

        if ($this->inList('database_roles.migration_commands', $command) && $this->separated()) {
            return $this->migrationConnectionName();
        }

        return null;
    }

    private function inList(string $key, string $command): bool
    {
        return in_array($command, (array) config($key, []), true);
    }

    /**
     * A configuração da conexão com o DB_URL (se houver) já aplicado.
     *
     * @return array<string, mixed>
     */
    private function resolvedConfig(string $connection): array
    {
        $config = (array) config("database.connections.{$connection}", []);
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);
        unset($config['url']);

        return $config;
    }
}
