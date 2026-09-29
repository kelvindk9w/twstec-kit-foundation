<?php

declare(strict_types=1);

use Illuminate\Support\Facades\View;
use Spatie\Backup\Commands\BackupCommand;
use Twstec\Kit\Foundation\Backup\Console\GuardedBackupCommand;
use Twstec\Kit\Foundation\Support\Platform;
use Twstec\Kit\Foundation\Tests\TestCase;

// O que o pacote instala sozinho numa aplicação Laravel: providers
// descobertos, config padrão, migrations com os nomes de sempre, views e
// traduções com os nomes de sempre, singleton da plataforma e a troca do
// backup:run.

function foundationPackagePath(string $relative = ''): string
{
    return dirname(__DIR__, 2).($relative === '' ? '' : '/'.$relative);
}

it('declara na descoberta automática exatamente os providers que a suíte sobe', function (): void {
    $composer = json_decode((string) file_get_contents(foundationPackagePath('composer.json')), true);

    expect($composer['extra']['laravel']['providers'])->toBe(TestCase::PACKAGE_PROVIDERS);
});

it('traz a configuração padrão de cada arquivo que publica', function (): void {
    expect(config('security.validation'))->toBeArray()->not->toBeEmpty()
        ->and(config('security.hosts'))->toBeArray()
        ->and(config('audit.retention_days'))->toBeInt()
        ->and(config('settings.cache_key'))->toBe('settings.db_overrides')
        ->and(config('platform'))->toBeArray()->not->toBeEmpty();
});

it('roda as migrations do pacote com os mesmos nomes de arquivo da 1.x', function (): void {
    $names = array_map(
        fn (string $file): string => basename($file, '.php'),
        glob(foundationPackagePath('database/migrations/*.php')) ?: [],
    );

    sort($names);

    // Bancos que já rodaram estas migrations (quando elas moravam no
    // aplicativo) não podem ver nenhuma como pendente: o nome é a chave.
    expect($names)->toBe([
        '2026_08_20_000001_create_request_logs_table',
        '2026_08_21_000002_create_settings_table',
        '2026_09_21_000001_add_client_correlation_id_to_request_logs_table',
        '2026_09_25_000001_create_audit_events_table',
        '2026_09_27_000001_add_tenant_uuid_to_audit_events_table',
    ]);

    expect(app('migrator')->paths())->toContain(foundationPackagePath('database/migrations'));
});

it('resolve as views do e-mail pelos nomes de sempre e pelo namespace do pacote', function (): void {
    expect(View::exists('mail.text.auto'))->toBeTrue()
        ->and(View::exists('mail.layouts.kit'))->toBeTrue()
        ->and(View::exists('foundation::mail.layouts.kit'))->toBeTrue()
        ->and(View::exists('foundation::mail.button'))->toBeTrue();
});

it('registra o singleton da plataforma e a troca do backup:run', function (): void {
    expect(app(Platform::class))->toBe(app(Platform::class))
        ->and(platform())->toBe(app(Platform::class))
        ->and(app(BackupCommand::class))->toBeInstanceOf(GuardedBackupCommand::class);
});
