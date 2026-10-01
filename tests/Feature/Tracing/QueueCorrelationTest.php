<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Logging\CorrelationContext;
use Twstec\Kit\Foundation\Logging\CorrelationId;
use Twstec\Kit\Foundation\Tests\Fixtures\Tracing\ProbeJob;
use Twstec\Kit\Foundation\Tests\Fixtures\Tracing\QueueTables;
use Twstec\Kit\Foundation\Tests\Fixtures\Tracing\TracingProbe;
use Twstec\Kit\Foundation\Tracing\Models\OutboundHttpLog;
use Twstec\Kit\Foundation\Tracing\Queue\QueueCorrelation;

// =============================================================================
// O JOB LEVA O correlation_id DE QUEM O DESPACHOU E O RESTAURA NO WORKER.
//
// Requisição → job → job encadeado/lote → chamada HTTP de dentro do job: a
// mesma operação, o mesmo id — no contexto do log e nas trilhas. Sem a
// aplicação lembrar de nada. Os testes rodam o worker de verdade
// (`queue:work --once` / `--stop-when-empty`) com o estado do processo
// zerado antes, como num worker separado: o id só pode ter vindo do payload.
// =============================================================================

uses(RefreshDatabase::class);

/** @var list<array{message: string, context: array<string, mixed>}> */
$GLOBALS['tracingLogLines'] = [];

beforeEach(function (): void {
    TracingProbe::reset();
    $GLOBALS['tracingLogLines'] = [];

    QueueTables::create();

    config([
        'queue.default' => 'database',
        'queue.connections.database.connection' => 'testing',
        'queue.batching.database' => 'testing',
        'queue.failed.database' => 'testing',
        'queue.failed.driver' => 'database-uuids',
    ]);

    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $GLOBALS['tracingLogLines'][] = ['message' => (string) $event->message, 'context' => $event->context];
    });

    Route::get('/tracing/dispatch', function () {
        dispatch(new ProbeJob('from-request'));

        return response()->json(['ok' => true]);
    });
});

/**
 * Zera o estado do processo como um worker novo o teria: sem contexto de
 * correlação, sem contexto compartilhado no log. O que o job enxergar depois
 * disso só pode ter vindo do payload.
 */
function freshWorkerProcess(): void
{
    app()->forgetScopedInstances();
    Log::flushSharedContext();
    Log::withoutContext();

    expect(CorrelationId::current())->toBeNull();
}

function workOnce(string $connection = 'database'): void
{
    freshWorkerProcess();

    test()->artisan('queue:work', ['connection' => $connection, '--once' => true, '--sleep' => 0])->assertSuccessful();
}

function workUntilEmpty(string $connection = 'database'): void
{
    freshWorkerProcess();

    test()->artisan('queue:work', ['connection' => $connection, '--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();
}

/**
 * @return array<string, mixed>
 */
function logContextOf(string $label): array
{
    foreach ($GLOBALS['tracingLogLines'] as $line) {
        if ($line['message'] === 'probe.job' && ($line['context']['label'] ?? null) === $label) {
            return $line['context'];
        }
    }

    return [];
}

/**
 * @return array<string, mixed>
 */
function lastQueuedPayload(): array
{
    return (array) json_decode((string) DB::table('jobs')->orderByDesc('id')->value('payload'), true);
}

it('requisição que despacha job: o worker (queue:work --once) restaura o MESMO correlation_id no log e no contexto', function (): void {
    $response = $this->get('/tracing/dispatch')->assertOk();
    $requestId = $response->headers->get('X-Correlation-Id');

    expect(Str::isUuid($requestId))->toBeTrue();

    // O payload leva id e origem — e só isso.
    $payload = lastQueuedPayload();
    expect($payload[QueueCorrelation::PAYLOAD_KEY])->toBe(['id' => $requestId, 'origin' => 'http']);

    workOnce();

    expect(TracingProbe::of('from-request'))->toBe(['label' => 'from-request', 'id' => $requestId, 'origin' => 'http'])
        ->and(logContextOf('from-request')[CorrelationContext::LOG_ID] ?? null)->toBe($requestId)
        ->and(logContextOf('from-request')[CorrelationContext::LOG_ORIGIN] ?? null)->toBe('http');

    // Terminado o job, o worker não fica com o id dele (o próximo job não herda).
    expect(CorrelationId::current())->toBeNull()
        ->and(Log::sharedContext())->not->toHaveKey(CorrelationContext::LOG_ID);
});

it('job despachado de dentro de outro job herda o id (processado pelo worker em laço)', function (): void {
    $requestId = (string) Str::uuid7();
    app(CorrelationContext::class)->enterRequest($requestId);

    dispatch(new ProbeJob('parent', dispatchChild: 'child'));

    workUntilEmpty();

    expect(TracingProbe::of('parent')['id'])->toBe($requestId)
        ->and(TracingProbe::of('child')['id'])->toBe($requestId)
        ->and(logContextOf('child')[CorrelationContext::LOG_ID] ?? null)->toBe($requestId);
});

it('Bus::chain: todos os elos rodam com o id de quem despachou a cadeia', function (): void {
    Route::get('/tracing/chain', function () {
        Bus::chain([new ProbeJob('chain-1'), new ProbeJob('chain-2'), new ProbeJob('chain-3')])->dispatch();

        return response()->noContent();
    });

    $requestId = $this->get('/tracing/chain')->headers->get('X-Correlation-Id');

    workUntilEmpty();

    expect(array_column(TracingProbe::$seen, 'label'))->toBe(['chain-1', 'chain-2', 'chain-3'])
        ->and(array_unique(array_column(TracingProbe::$seen, 'id')))->toBe([$requestId])
        ->and(logContextOf('chain-3')[CorrelationContext::LOG_ID] ?? null)->toBe($requestId);
});

it('Bus::batch: todos os jobs do lote (e o callback do fim) rodam com o id de quem despachou', function (): void {
    Route::get('/tracing/batch', function () {
        Bus::batch([new ProbeJob('batch-1'), new ProbeJob('batch-2')])
            ->then(function (Batch $batch): void {
                TracingProbe::see('batch-then');
            })
            ->dispatch();

        return response()->noContent();
    });

    $requestId = $this->get('/tracing/batch')->headers->get('X-Correlation-Id');

    workUntilEmpty();

    expect(TracingProbe::of('batch-1')['id'])->toBe($requestId)
        ->and(TracingProbe::of('batch-2')['id'])->toBe($requestId)
        ->and(TracingProbe::of('batch-then')['id'])->toBe($requestId);
});

it('fila sync: o job roda dentro da requisição com o id dela, e a requisição continua com o dela depois', function (): void {
    config(['queue.default' => 'sync']);

    Route::get('/tracing/sync', function () {
        dispatch(new ProbeJob('sync'));

        return response()->json(['after' => CorrelationId::current()]);
    });

    $response = $this->get('/tracing/sync')->assertOk();
    $requestId = $response->headers->get('X-Correlation-Id');

    expect(TracingProbe::of('sync')['id'])->toBe($requestId)
        ->and($response->json('after'))->toBe($requestId);
});

it('chamada Http:: de dentro do job leva o cabeçalho com o id da requisição de origem', function (): void {
    Http::fake(['payments.example.test/*' => Http::response(['ok' => true])]);

    Route::get('/tracing/job-http', function () {
        dispatch(new ProbeJob('job-http', callUrl: 'https://payments.example.test/v1/charges'));

        return response()->noContent();
    });

    $requestId = $this->get('/tracing/job-http')->headers->get('X-Correlation-Id');

    workOnce();

    Http::assertSent(fn (HttpRequest $request): bool => $request->header('X-Correlation-Id') === [$requestId]);

    expect(OutboundHttpLog::query()->sole()->correlation_id)->toBe($requestId);
});

it('job que chega SEM id no payload ganha um novo, de origem queue', function (): void {
    Queue::connection('database')->pushRaw(json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => ProbeJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'maxTries' => null,
        'maxExceptions' => null,
        'failOnTimeout' => false,
        'backoff' => null,
        'timeout' => null,
        'data' => [
            'commandName' => ProbeJob::class,
            'command' => serialize(new ProbeJob('legacy')),
        ],
    ]));

    expect(lastQueuedPayload())->not->toHaveKey(QueueCorrelation::PAYLOAD_KEY);

    workOnce();

    expect(Str::isUuid(TracingProbe::of('legacy')['id']))->toBeTrue()
        ->and(TracingProbe::of('legacy')['origin'])->toBe('queue');
});

it('payload adulterado (id que não é UUID) não chega ao log: o job ganha id novo', function (): void {
    dispatch(new ProbeJob('tampered'));

    $payload = lastQueuedPayload();
    $payload[QueueCorrelation::PAYLOAD_KEY] = ['id' => "x\n[CRITICAL] forjado", 'origin' => 'http'];
    DB::table('jobs')->update(['payload' => json_encode($payload)]);

    workOnce();

    expect(Str::isUuid(TracingProbe::of('tampered')['id']))->toBeTrue()
        ->and(TracingProbe::of('tampered')['origin'])->toBe('queue');
});

it('job despachado no console (sem requisição) ganha id próprio, de origem console', function (): void {
    freshWorkerProcess();

    dispatch(new ProbeJob('console'));

    $snapshot = lastQueuedPayload()[QueueCorrelation::PAYLOAD_KEY];

    expect(Str::isUuid($snapshot['id']))->toBeTrue()
        ->and($snapshot['origin'])->toBe('console');

    workOnce();

    expect(TracingProbe::of('console')['id'])->toBe($snapshot['id']);
});

it('cada tarefa do agendador ganha um id próprio, de origem scheduler, que segue para o job', function (): void {
    // Meio-dia: fora do horário da poda agendada pelo pacote.
    $this->travelTo(now()->setTime(12, 0));
    freshWorkerProcess();

    $schedule = app(Schedule::class);
    $schedule->call(function (): void {
        TracingProbe::see('callback');
        dispatch(new ProbeJob('scheduled-a'));
    })->everyMinute();
    $schedule->job(new ProbeJob('scheduled-b'))->everyMinute();

    $this->artisan('schedule:run')->assertSuccessful();

    // Fora da tarefa, nada sobra no processo do agendador.
    expect(CorrelationId::current())->toBeNull();

    workUntilEmpty();

    $a = TracingProbe::of('scheduled-a');
    $b = TracingProbe::of('scheduled-b');

    expect(TracingProbe::of('callback')['origin'])->toBe('scheduler')
        ->and(TracingProbe::of('callback')['id'])->toBe($a['id'])
        ->and($a['origin'])->toBe('scheduler')
        ->and($b['origin'])->toBe('scheduler')
        ->and(Str::isUuid($a['id']))->toBeTrue()
        ->and($a['id'])->not->toBe($b['id']);
});

it('o processo filho de uma tarefa do agendador recebe o id da tarefa', function (): void {
    $this->travelTo(now()->setTime(12, 0));
    freshWorkerProcess();

    $out = tempnam(sys_get_temp_dir(), 'tracing-child-');

    // O filho lê o Context que o Laravel lhe passa (__LARAVEL_CONTEXT) — o
    // mesmo que o boot do pacote adota num `php artisan …` filho.
    app(Schedule::class)->exec(PHP_BINARY.' -r '.escapeshellarg('echo getenv("__LARAVEL_CONTEXT");'))
        ->sendOutputTo($out)
        ->everyMinute();

    $this->artisan('schedule:run')->assertSuccessful();

    $context = json_decode((string) file_get_contents($out), true);
    @unlink($out);

    $snapshot = unserialize($context['hidden']['tws_correlation'] ?? 'b:0;');

    expect($snapshot['origin'] ?? null)->toBe('scheduler')
        ->and(Str::isUuid($snapshot['id'] ?? null))->toBeTrue();
});

it('processo de console nascido de uma tarefa adota o id dela no boot', function (): void {
    $id = (string) Str::uuid7();

    $_SERVER['__LARAVEL_CONTEXT'] = $_ENV['__LARAVEL_CONTEXT'] = json_encode([
        'data' => [],
        'hidden' => ['tws_correlation' => serialize(['id' => $id, 'origin' => 'scheduler'])],
    ]);
    putenv('__LARAVEL_CONTEXT='.$_ENV['__LARAVEL_CONTEXT']);

    try {
        $this->refreshApplication();

        expect(CorrelationId::current())->toBe($id)
            ->and(CorrelationId::origin()?->value)->toBe('scheduler');
    } finally {
        unset($_SERVER['__LARAVEL_CONTEXT'], $_ENV['__LARAVEL_CONTEXT']);
        putenv('__LARAVEL_CONTEXT');
        $this->refreshApplication();
    }
});

it('com a fila redis o id também vai e volta', function (): void {
    config([
        'database.redis.client' => 'phpredis',
        'database.redis.default.host' => env('REDIS_HOST', '127.0.0.1'),
        'queue.connections.redis.queue' => 'tracing-test-'.Str::random(8),
    ]);

    try {
        Redis::connection()->ping();
    } catch (Throwable) {
        $this->markTestSkipped('Redis não está acessível neste ambiente (o CI dos pacotes não sobe Redis).');
    }

    config(['queue.default' => 'redis']);

    $requestId = $this->get('/tracing/dispatch')->headers->get('X-Correlation-Id');

    workOnce('redis');

    expect(TracingProbe::of('from-request')['id'])->toBe($requestId)
        ->and(logContextOf('from-request')[CorrelationContext::LOG_ID] ?? null)->toBe($requestId);
})->skip(fn (): bool => ! extension_loaded('redis'), 'extensão phpredis ausente');

it('desligado por config (TRACING_QUEUE=false), o payload não leva o id', function (): void {
    // O gancho do payload é estático no Queue do Laravel: sai o do boot
    // anterior antes de subir a aplicação desligada.
    Queue::createPayloadUsing(null);
    $this->bootWithAppConfig(['tracing.queue.enabled' => false]);
    QueueTables::create();
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'testing']);

    dispatch(new ProbeJob('off'));

    expect(lastQueuedPayload())->not->toHaveKey(QueueCorrelation::PAYLOAD_KEY);
});
