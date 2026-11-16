<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Foundation\Database\DestructiveCommands;

// =============================================================================
// FORA DE PRODUÇÃO, NADA MUDA: o desenvolvimento e a suíte de testes
// (RefreshDatabase usa `migrate:fresh`) continuam apagando e recriando o
// banco. A recusa é só de APP_ENV=production (ver
// tests/Production/DestructiveCommandsTest.php).
// =============================================================================

afterEach(function (): void {
    DB::prohibitDestructiveCommands(false);
    unset($_SERVER['DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION'], $_ENV['DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION']);
});

it('fora de produção o db:wipe roda como sempre e apaga as tabelas', function (): void {
    Schema::create('sentinel_rows', function (Blueprint $table): void {
        $table->id();
    });
    DB::table('sentinel_rows')->insert([['id' => 1]]);

    expect(DestructiveCommands::prohibited($this->app))->toBeFalse();

    $this->artisan('db:wipe', ['--force' => true])
        ->doesntExpectOutputToContain('prohibited')
        ->doesntExpectOutputToContain('DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION')
        ->assertExitCode(0);

    expect(Schema::hasTable('sentinel_rows'))->toBeFalse();
});

it('um boot fora de produção desfaz a recusa que ficou de um boot anterior no mesmo processo', function (): void {
    DB::prohibitDestructiveCommands();

    $this->refreshApplication();

    $this->artisan('db:wipe', ['--force' => true])->assertExitCode(0);
});

it('o opt-out vem da variável DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION e é desligado por padrão', function (): void {
    expect(config('security.database.allow_destructive_commands_in_production'))->toBeFalse();

    $_SERVER['DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION'] = 'true';
    $_ENV['DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION'] = 'true';
    $this->refreshApplication();

    expect(config('security.database.allow_destructive_commands_in_production'))->toBeTrue();
});

it('o opt-out só liga com true: 1, yes, on ou um valor torto não liberam apagar o banco', function (string $value, bool $allowed): void {
    $_SERVER['DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION'] = $value;
    $_ENV['DB_ALLOW_DESTRUCTIVE_COMMANDS_IN_PRODUCTION'] = $value;
    $this->refreshApplication();

    expect(config('security.database.allow_destructive_commands_in_production'))->toBe($allowed);
})->with([
    'true' => ['true', true],
    'TRUE' => ['TRUE', true],
    '(true)' => ['(true)', true],
    '1' => ['1', false],
    'yes' => ['yes', false],
    'on' => ['on', false],
    'no' => ['no', false],
    'false' => ['false', false],
    'vazio' => ['', false],
    'torto' => ['ture', false],
]);
