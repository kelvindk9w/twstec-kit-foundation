<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Database\DestructiveCommands;

// =============================================================================
// EM PRODUÇÃO, NENHUM COMANDO APAGA O BANCO — numa aplicação limpa.
//
// `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` e
// `migrate:rollback` saem com código 1, mesmo com --force, sem tocar no
// banco: a tabela e as linhas continuam lá. A tentativa grava aviso no log e
// a saída diz como liberar. Só o opt-out explícito
// (DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION) libera, com aviso no log; e
// desligar as guardas de produção NÃO libera.
// =============================================================================

/**
 * Canal de log num arquivo próprio do teste, para ler o que o comando gravou.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function destructiveLogCapture(): array
{
    $file = sys_get_temp_dir().'/foundation-destructive-'.uniqid().'.log';

    return [[
        'logging.default' => 'destructive-capture',
        'logging.channels.destructive-capture' => ['driver' => 'single', 'path' => $file],
    ], $file];
}

function destructiveLogContents(string $file): string
{
    return is_file($file) ? (string) file_get_contents($file) : '';
}

/**
 * Uma tabela com duas linhas e uma migration registrada como rodada: o que o
 * comando destrutivo apagaria.
 */
function destructiveSentinel(): void
{
    Schema::create('sentinel_rows', function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });

    DB::table('sentinel_rows')->insert([['label' => 'primeira'], ['label' => 'segunda']]);

    if (! Schema::hasTable('migrations')) {
        Schema::create('migrations', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });
    }

    DB::table('migrations')->insert(['migration' => '2026_01_01_000000_create_sentinel_rows_table', 'batch' => 1]);
}

afterEach(function (): void {
    // A flag do Laravel é estática: nada de recusa vazando para o próximo teste.
    DB::prohibitDestructiveCommands(false);
});

it('recusa o comando destrutivo mesmo com --force, avisa no log e não toca no banco', function (string $command): void {
    [$logging, $file] = destructiveLogCapture();

    $this->bootProductionApp(['config' => $logging]);
    destructiveSentinel();

    $this->artisan($command, ['--force' => true])
        ->expectsOutputToContain('DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION=true')
        ->expectsOutputToContain('This command is prohibited from running in this environment.')
        ->assertExitCode(1);

    expect(Schema::hasTable('sentinel_rows'))->toBeTrue()
        ->and(DB::table('sentinel_rows')->orderBy('id')->pluck('label')->all())->toBe(['primeira', 'segunda'])
        ->and(DB::table('migrations')->count())->toBe(1)
        ->and(destructiveLogContents($file))->toContain("O comando destrutivo `{$command}` foi RECUSADO em APP_ENV=production");
})->with(DestructiveCommands::COMMANDS);

it('a lista recusada é a do Laravel: os cinco comandos que apagam tabelas', function (): void {
    expect(DestructiveCommands::COMMANDS)->toEqualCanonicalizing([
        'db:wipe', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback',
    ]);
});

it('o migrate de sempre (só acrescenta) continua rodando em produção', function (): void {
    $this->bootProductionApp([]);

    $this->artisan('migrate', ['--force' => true])
        ->doesntExpectOutputToContain('prohibited')
        ->assertExitCode(0);

    expect(Schema::hasTable('migrations'))->toBeTrue();
});

it('desligar as guardas de produção não libera apagar o banco', function (): void {
    $this->bootProductionApp(['config' => ['security.production_guards.enabled' => false]]);
    destructiveSentinel();

    $this->artisan('db:wipe', ['--force' => true])->assertExitCode(1);

    expect(DB::table('sentinel_rows')->count())->toBe(2);
});

it('o opt-out explícito libera o comando, com aviso no log de que ele vai rodar', function (): void {
    [$logging, $file] = destructiveLogCapture();

    $this->bootProductionApp([
        'config' => [...$logging, 'security.database.allow_destructive_commands_in_production' => true],
    ]);
    destructiveSentinel();

    $this->artisan('db:wipe', ['--force' => true])
        ->doesntExpectOutputToContain('prohibited')
        ->assertExitCode(0);

    expect(Schema::hasTable('sentinel_rows'))->toBeFalse()
        ->and(destructiveLogContents($file))->toContain('O comando destrutivo `db:wipe` vai rodar em APP_ENV=production porque DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION está ligado');
});

it('opt-out com valor que não é verdadeiro não libera', function (mixed $value): void {
    $this->bootProductionApp(['config' => ['security.database.allow_destructive_commands_in_production' => $value]]);
    destructiveSentinel();

    $this->artisan('db:wipe', ['--force' => true])->assertExitCode(1);

    expect(DB::table('sentinel_rows')->count())->toBe(2);
})->with([
    'falso' => [false],
    'nulo' => [null],
    'texto "true" sem conversão' => ['true'],
    'número 1' => [1],
]);
