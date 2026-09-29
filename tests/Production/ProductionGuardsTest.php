<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\Config\Config as BackupConfig;
use Twstec\Kit\Foundation\Mail\Exceptions\NonDeliveringMailerInProductionException;
use Twstec\Kit\Foundation\Security\ApiRateLimit;
use Twstec\Kit\Foundation\Support\Exceptions\MissingApplicationKeyException;

// =============================================================================
// AS GUARDAS DE PRODUÇÃO VALEM NUMA APLICAÇÃO LIMPA.
//
// Quem instala twstec/kit-foundation num aplicativo Laravel próprio — sem o
// starter, sem AppServiceProvider do kit — recebe as mesmas recusas que o
// starter tem: nenhuma delas depende de o aplicativo lembrar de chamar nada.
// Desligar é opt-out explícito por config, e deixa aviso no log.
// =============================================================================

/**
 * Canal de log num arquivo próprio do teste, para ler o que o boot gravou.
 *
 * @return array{0: array<string, mixed>, 1: string}
 */
function productionLogCapture(): array
{
    $file = sys_get_temp_dir().'/foundation-production-'.uniqid().'.log';

    return [[
        'logging.default' => 'guard-capture',
        'logging.channels.guard-capture' => ['driver' => 'single', 'path' => $file],
    ], $file];
}

function productionLogContents(string $file): string
{
    return is_file($file) ? (string) file_get_contents($file) : '';
}

it('recusa subir sem APP_KEY no processo que processa trabalho', function (): void {
    expect(fn () => $this->bootProductionApp([
        'config' => ['app.key' => null],
        'argv' => ['artisan', 'queue:work'],
    ]))->toThrow(MissingApplicationKeyException::class);
});

it('recusa subir com APP_KEY de fachada no processo que processa trabalho', function (): void {
    expect(fn () => $this->bootProductionApp([
        'config' => ['app.key' => 'base64:'],
        'argv' => ['artisan', 'horizon'],
    ]))->toThrow(MissingApplicationKeyException::class);
});

it('força APP_DEBUG desligado e HTTPS nas URLs geradas', function (): void {
    $this->bootProductionApp(['config' => ['app.debug' => true, 'app.url' => 'http://app.example.com']]);

    expect(config('app.debug'))->toBeFalse()
        ->and(url('/painel'))->toStartWith('https://');
});

it('recusa backup sem criptografia', function (): void {
    $origem = sys_get_temp_dir().'/foundation-backup-origem-'.uniqid();
    @mkdir($origem, 0755, true);
    file_put_contents($origem.'/dado.txt', 'conteudo sensivel de teste');

    Storage::fake('local');
    config()->set('backup.backup.password', null);
    config()->set('backup.backup.source.files.include', [$origem]);
    config()->set('backup.backup.source.files.exclude', []);
    config()->set('backup.backup.destination.disks', ['local']);
    config()->set('backup.notifications.webhook.url', '');
    app()->forgetInstance(BackupConfig::class);

    $this->artisan('backup:run', ['--only-files' => true, '--disable-notifications' => true])
        ->expectsOutputToContain('Backup RECUSADO em APP_ENV=production')
        ->assertExitCode(1);
});

it('recusa enviar e-mail pelo transporte log', function (): void {
    config()->set('mail.default', 'log');
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();

    expect(fn () => Mail::raw('codigo 731902', fn ($message) => $message->to('titular@example.com')))
        ->toThrow(NonDeliveringMailerInProductionException::class);
});

it('registra os limitadores api e sensitive do pacote', function (): void {
    $request = Request::create('/api/x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);

    $api = RateLimiter::limiter('api');
    $sensitive = RateLimiter::limiter('sensitive');

    expect($api)->not->toBeNull()
        ->and($sensitive)->not->toBeNull()
        ->and($api($request))->toEqual(ApiRateLimit::limit($request))
        ->and($sensitive($request)->key)->toBe('203.0.113.9');
});

it('opt-out das guardas: sobe sem recusar, mas avisa no log', function (): void {
    [$logging, $file] = productionLogCapture();

    $this->bootProductionApp([
        'config' => [...$logging, 'app.key' => null, 'app.debug' => true, 'security.production_guards.enabled' => false],
        'argv' => ['artisan', 'queue:work'],
    ]);

    expect(config('app.debug'))->toBeTrue()
        ->and(productionLogContents($file))->toContain('SECURITY_PRODUCTION_GUARDS=false');
});

it('opt-out dos limitadores: não registra, mas avisa no log', function (): void {
    [$logging, $file] = productionLogCapture();

    $this->bootProductionApp([
        'config' => [...$logging, 'security.rate_limit.define_limiters' => false],
    ]);

    expect(RateLimiter::limiter('sensitive'))->toBeNull()
        ->and(productionLogContents($file))->toContain('RATE_LIMIT_DEFINE_LIMITERS=false');
});
