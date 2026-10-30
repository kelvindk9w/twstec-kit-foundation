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
 */
final class DatabaseRoles
{
    /**
     * O papel da aplicação enquanto um comando roda com a conexão de
     * migração: [conexão padrão anterior, usuário dela].
     *
     * @var list<array{string, string}>
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
     * CommandStarting: desvia para a conexão de migração os comandos da
     * lista que não escolheram a conexão.
     */
    public function commandStarting(CommandStarting $event): void
    {
        if (! $this->shouldRoute($event)) {
            return;
        }

        $previous = $this->applicationConnectionName();
        $role = $this->applicationRole();
        $migration = $this->migrationConnectionName();

        $this->routed[] = [$previous, $role];
        $this->db->setDefaultConnection($migration);
    }

    /**
     * CommandFinished: devolve a conexão padrão de antes do comando.
     */
    public function commandFinished(CommandFinished $event): void
    {
        if (! $this->isRoutable($event->command) || $this->routed === []) {
            return;
        }

        [$previous] = array_pop($this->routed);
        $this->db->setDefaultConnection($previous);
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

        app(ApplicationRolePrivileges::class)->sync(
            $this->db->connection($this->migrationConnectionName()),
            $this->applicationRole(),
            (string) ($this->resolvedConfig($applicationConnection)['password'] ?? ''),
        );
    }

    private function shouldRoute(CommandStarting $event): bool
    {
        if (! $this->isRoutable($event->command) || ! $this->separated()) {
            return false;
        }

        // `--database` explícito: quem chamou escolheu.
        return ! ($event->input->hasOption('database') && filled($event->input->getOption('database')));
    }

    private function isRoutable(?string $command): bool
    {
        $commands = (array) config('database_roles.migration_commands', []);

        return $command !== null && in_array($command, $commands, true);
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
