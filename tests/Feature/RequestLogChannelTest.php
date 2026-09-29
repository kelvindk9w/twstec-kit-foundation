<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Logging\MaskCardNumbersInLogs;
use Twstec\Kit\Foundation\Logging\RequestLogChannel;

// =============================================================================
// A SEGUNDA CAMADA DAS TRILHAS NÃO SOME NUMA APLICAÇÃO LIMPA.
//
// O pacote grava no canal `request_log` o que precisa sobreviver ao banco:
// `security.observed`, `request.throttled`, `audit.event`… Uma aplicação que
// não tem esse canal no config/logging.php (o esqueleto Laravel não tem)
// ganha o canal padrão do pacote — e as linhas vão para o arquivo dele, não
// para o logger de emergência. Uma aplicação que TEM o canal usa o dela.
// =============================================================================

uses(RefreshDatabase::class);

/**
 * Linhas JSON gravadas nos arquivos diários de um caminho de canal `daily`.
 *
 * @return list<array<string, mixed>>
 */
function requestLogLines(string $path): array
{
    $lines = [];

    foreach (glob(str_replace('.log', '-*.log', $path)) ?: [] as $file) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $lines[] = (array) json_decode($line, true);
        }
    }

    return $lines;
}

function clearLogFiles(string $path): void
{
    foreach (glob(str_replace('.log', '*.log', $path)) ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Gera os três eventos de segurança que vão para o canal: uma tentativa de
 * ataque observada, um cliente acima do teto da borda e uma ação auditada.
 */
function emitTrailEvents(): void
{
    config(['security.validation.mode' => 'observe', 'security.rate_limit.web' => 2]);

    test()->get('/?q=<script>alert(1)</script>');
    test()->get('/');
    test()->get('/')->assertTooManyRequests();

    $trail = app(AuditTrail::class);
    $trail->within(AuditScope::console('teste:canal'), fn () => $trail->record('canal.testado'));
}

it('sem canal na aplicação, o pacote registra o padrão — o mesmo do starter', function (): void {
    expect(config('logging.channels.request_log'))->toBe(RequestLogChannel::definition())
        ->and(config('logging.channels.request_log.driver'))->toBe('daily')
        ->and(config('logging.channels.request_log.path'))->toBe(storage_path('logs/request.log'))
        ->and(config('logging.channels.request_log.tap'))->toBe([MaskCardNumbersInLogs::class]);
});

it('sem canal na aplicação, security.observed, request.throttled e audit.event vão para o arquivo do canal padrão, não para o de emergência', function (): void {
    $path = (string) config('logging.channels.request_log.path');
    $emergency = storage_path('logs/laravel.log');
    clearLogFiles($path);
    @unlink($emergency);
    Log::forgetChannel('request_log');

    emitTrailEvents();

    $messages = array_column(requestLogLines($path), 'message');
    clearLogFiles($path);

    expect($messages)->toContain('security.observed', 'request.throttled', 'audit.event')
        ->and(is_file($emergency) ? (string) file_get_contents($emergency) : '')->not->toContain('request_log');
});

it('com canal na aplicação, o da aplicação vence — o pacote não o substitui', function (): void {
    $own = sys_get_temp_dir().'/app-request-log-'.uniqid().'.log';

    $this->bootWithAppConfig(['logging.channels.request_log' => [
        'driver' => 'daily',
        'path' => $own,
        'level' => 'debug',
        'formatter' => JsonFormatter::class,
    ]]);
    $this->artisan('migrate')->run();

    expect(config('logging.channels.request_log.path'))->toBe($own)
        ->and(config('logging.channels.request_log'))->not->toBe(RequestLogChannel::definition());

    Log::forgetChannel('request_log');
    emitTrailEvents();

    $messages = array_column(requestLogLines($own), 'message');
    clearLogFiles($own);

    expect($messages)->toContain('security.observed', 'request.throttled', 'audit.event');
});

it('registerDefault só age quando a aplicação não tem o canal', function (): void {
    $empty = new Repository(['logging' => ['channels' => []]]);
    $own = new Repository(['logging' => ['channels' => ['request_log' => ['driver' => 'single']]]]);

    expect(RequestLogChannel::registerDefault($empty))->toBeTrue()
        ->and($empty->get('logging.channels.request_log'))->toBe(RequestLogChannel::definition())
        ->and(RequestLogChannel::registerDefault($own))->toBeFalse()
        ->and($own->get('logging.channels.request_log'))->toBe(['driver' => 'single']);
});
