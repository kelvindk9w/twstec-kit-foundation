<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Twstec\Kit\Foundation\Database\AppendOnlyTables;
use Twstec\Kit\Foundation\Database\DatabaseRoles;

// Separação de papéis no PostgreSQL: o registro das tabelas só-acréscimo, o
// desvio dos comandos de estrutura para o papel de migração e o
// `db:check-roles` fora do PostgreSQL. O comportamento no banco de verdade
// (privilégios, conferência, migrate com dois papéis) é provado na suíte
// PostgreSQL dos starters (tests/Feature/Database/DatabaseRolesTest.php).

/**
 * Conexão PostgreSQL fingida como padrão (nada aqui conecta).
 */
function useFakePgsqlApplication(string $username = 'app_role', ?string $migrationUser = 'owner_role'): void
{
    config([
        'database.connections.fake_pgsql' => [
            'driver' => 'pgsql',
            'host' => 'db.invalid',
            'port' => '5432',
            'database' => 'app',
            'username' => $username,
            'password' => 'app-secret',
        ],
        'database_roles.migration.username' => $migrationUser,
        'database_roles.migration.password' => 'owner-secret',
    ]);

    DB::setDefaultConnection('fake_pgsql');
}

function commandEvent(string $class, string $command, ?string $database = null): CommandStarting|CommandFinished
{
    $input = new ArrayInput($database === null ? [] : ['--database' => $database], new InputDefinition([
        new InputOption('database', null, InputOption::VALUE_OPTIONAL),
    ]));

    return $class === CommandStarting::class
        ? new CommandStarting($command, $input, new NullOutput)
        : new CommandFinished($command, $input, new NullOutput, 0);
}

it('registra as tabelas só-acréscimo do kit, soma as do projeto e não deixa o projeto tirar nenhuma', function (): void {
    config(['database_roles.append_only_tables' => [
        'ledger_entries',
        'payment_events' => ['updatable_columns' => ['status']],
        'request_logs' => ['updatable_columns' => ['note']],
    ]]);

    $tables = AppendOnlyTables::all();

    expect(array_keys($tables))->toBe(['audit_events', 'ledger_entries', 'outbound_http_logs', 'payment_events', 'request_logs'])
        ->and($tables['ledger_entries']['updatable_columns'])->toBe([])
        ->and($tables['payment_events']['updatable_columns'])->toBe(['status'])
        ->and($tables['audit_events']['updatable_columns'])->toBe([])
        ->and($tables['request_logs']['updatable_columns'])->toBe(['status', 'http_status_response', 'error_message', 'duration_ms', 'tenant_uuid', 'note']);
});

it('recusa nome de tabela ou lista de colunas inválidos no registro', function (array $declared): void {
    config(['database_roles.append_only_tables' => $declared]);

    AppendOnlyTables::all();
})->with([
    'nome com espaço' => [['ledger entries']],
    'nome com aspas' => [['ledger"; DROP TABLE x; --']],
    'colunas que não são lista' => [['ledger' => ['updatable_columns' => 'status']]],
])->throws(InvalidArgumentException::class);

it('o pacote traz a configuração dos papéis com a conexão de migração e os comandos desviados', function (): void {
    expect(config('database_roles.migration_connection'))->toBe('migration')
        ->and(config('database_roles.migration_commands'))->toContain('migrate', 'migrate:fresh', 'db:wipe', 'audit:prune', 'outbound-http:prune')
        ->and(config('database_roles.append_only_tables'))->toBe([]);
});

it('só há separação no PostgreSQL, com papel de migração definido e diferente do da aplicação', function (): void {
    $roles = app(DatabaseRoles::class);

    expect($roles->separated())->toBeFalse(); // SQLite dos testes

    useFakePgsqlApplication();
    expect($roles->separated())->toBeTrue()
        ->and($roles->applicationRole())->toBe('app_role');

    useFakePgsqlApplication(migrationUser: '');
    expect($roles->separated())->toBeFalse();

    useFakePgsqlApplication(username: 'same', migrationUser: 'same');
    expect($roles->separated())->toBeFalse();
});

it('desvia migrate e as podas para a conexão de migração enquanto rodam e devolve a padrão no fim', function (string $command): void {
    useFakePgsqlApplication();
    $roles = app(DatabaseRoles::class);

    $roles->commandStarting(commandEvent(CommandStarting::class, $command));

    expect(DB::getDefaultConnection())->toBe('migration')
        ->and(config('database.connections.migration.username'))->toBe('owner_role')
        ->and(config('database.connections.migration.password'))->toBe('owner-secret')
        ->and(config('database.connections.migration.host'))->toBe('db.invalid')
        // Durante o comando, o papel da aplicação continua sendo o de antes.
        ->and($roles->applicationRole())->toBe('app_role');

    $roles->commandFinished(commandEvent(CommandFinished::class, $command));

    expect(DB::getDefaultConnection())->toBe('fake_pgsql');
})->with(['migrate', 'migrate:fresh', 'migrate:rollback', 'db:wipe', 'audit:prune', 'outbound-http:prune']);

it('não desvia comando fora da lista, comando com --database nem processo sem separação', function (): void {
    useFakePgsqlApplication();
    $roles = app(DatabaseRoles::class);

    $roles->commandStarting(commandEvent(CommandStarting::class, 'queue:work'));
    expect(DB::getDefaultConnection())->toBe('fake_pgsql');

    $roles->commandStarting(commandEvent(CommandStarting::class, 'migrate', 'fake_pgsql'));
    expect(DB::getDefaultConnection())->toBe('fake_pgsql');

    useFakePgsqlApplication(migrationUser: null);
    $roles->commandStarting(commandEvent(CommandStarting::class, 'migrate'));
    expect(DB::getDefaultConnection())->toBe('fake_pgsql');
});

it('usa a conexão de migração do config/database.php do aplicativo quando ela existe', function (): void {
    useFakePgsqlApplication();
    config(['database.connections.migration' => ['driver' => 'pgsql', 'host' => 'owner.invalid', 'username' => 'declared_owner']]);

    app(DatabaseRoles::class)->commandStarting(commandEvent(CommandStarting::class, 'migrate'));

    expect(DB::getDefaultConnection())->toBe('migration')
        ->and(config('database.connections.migration.username'))->toBe('declared_owner')
        ->and(config('database.connections.migration.host'))->toBe('owner.invalid');
});

it('db:check-roles não confere conexão que não é PostgreSQL (código 2, com o motivo)', function (): void {
    app()->setLocale('pt_BR');

    expect(Artisan::call('db:check-roles'))->toBe(2)
        ->and(Artisan::output())->toContain('é só para o PostgreSQL');
});

it('db:check-roles responde com o motivo quando o banco não responde', function (): void {
    app()->setLocale('pt_BR');
    useFakePgsqlApplication();
    config(['database.connections.fake_pgsql.host' => '127.0.0.1', 'database.connections.fake_pgsql.port' => '1']);

    expect(Artisan::call('db:check-roles', ['--connection' => 'fake_pgsql']))->toBe(2)
        ->and(Artisan::output())->toContain('Não foi possível conferir a conexão fake_pgsql');
});
