<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;
use Twstec\Kit\Foundation\Logging\CorrelationContext;
use Twstec\Kit\Foundation\Logging\CorrelationId;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;
use Twstec\Kit\Foundation\Tracing\Http\OutboundHttpTrail;
use Twstec\Kit\Foundation\Tracing\Models\OutboundHttpLog;
use Twstec\Kit\Foundation\Tracing\Support\OutboundHttpLogTrigger;

// =============================================================================
// CHAMADA HTTP DE SAÍDA: LEVA O correlation_id E DEIXA UMA LINHA REDIGIDA.
//
// Sem a aplicação lembrar de nada: `Http::post(...)` sai com o cabeçalho e
// grava em `outbound_http_logs` destino normalizado, método, status, duração,
// tentativa, tamanhos, id e conta — e NUNCA o token, a senha, o corpo
// sensível, a query com segredo ou o cabeçalho de autenticação. A trilha é
// só-acréscimo e a falha dela não derruba a chamada.
// =============================================================================

uses(RefreshDatabase::class);

// Montado em partes: o literal inteiro no formato de chave de API real seria
// barrado pela varredura de segredos do GitHub (é um valor falso de teste).
define('TRACING_SECRET_TOKEN', implode('_', ['sk', 'live', '9f8e7d6c5b4a39281706f5e4d3c2b1a0']));
const TRACING_PASSWORD = 'S3nh@-Muito-Secreta!';
const TRACING_CPF = '123.456.789-09';
const TRACING_CARD = '4111 1111 1111 1111';
const TRACING_EMAIL = 'maria.silva@example.com';

/** @var list<array{level: string, message: string, context: array<string, mixed>}> */
$GLOBALS['outboundLogLines'] = [];

beforeEach(function (): void {
    $GLOBALS['outboundLogLines'] = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $GLOBALS['outboundLogLines'][] = ['level' => $event->level, 'message' => (string) $event->message, 'context' => $event->context];
    });

    Http::fake([
        'payments.example.test/*' => Http::response([
            'id' => 'ch_1',
            'status' => 'paid',
            'customer' => ['document' => TRACING_CPF, 'email' => TRACING_EMAIL],
            'card_number' => TRACING_CARD,
            'access_token' => TRACING_SECRET_TOKEN,
        ], 201),
    ]);

    Route::post('/tracing/charge', function () {
        Http::withToken(TRACING_SECRET_TOKEN)
            ->withBasicAuth('merchant', TRACING_PASSWORD)
            ->withHeaders(['X-Api-Key' => TRACING_SECRET_TOKEN])
            ->post('https://payments.example.test/v1/customers/'.TRACING_CPF.'/charges?access_token='.TRACING_SECRET_TOKEN.'&page=2', [
                'amount' => 1500,
                'password' => TRACING_PASSWORD,
                'token' => TRACING_SECRET_TOKEN,
                'card' => ['number' => TRACING_CARD, 'cvv' => '123'],
                'customer' => ['cpf' => TRACING_CPF, 'email' => TRACING_EMAIL],
                'description' => 'Pedido do cliente '.TRACING_CPF.' cartão '.TRACING_CARD,
            ]);

        return response()->noContent();
    });
});

/**
 * Tudo o que a trilha guardou, em texto: as linhas do banco como estão na
 * tabela (sem passar pelo model) e as linhas do canal de arquivo.
 */
function outboundTrailDump(): string
{
    $rows = DB::table('outbound_http_logs')->get()->map(fn ($row) => (array) $row)->all();
    $file = array_values(array_filter($GLOBALS['outboundLogLines'], fn (array $line): bool => str_starts_with($line['message'], 'http.outbound')));

    return json_encode([$rows, $file], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Asserção de conteúdo: nenhum segredo nem dado pessoal em claro.
 */
function expectNoSecretsIn(string $dump): void
{
    foreach ([
        TRACING_SECRET_TOKEN,
        TRACING_PASSWORD,
        base64_encode('merchant:'.TRACING_PASSWORD),
        TRACING_CPF,
        '12345678909',
        TRACING_CARD,
        '4111111111111111',
        TRACING_EMAIL,
        'Bearer',
        'Authorization',
        'X-Api-Key',
        '"123"',
    ] as $secret) {
        expect(str_contains($dump, $secret))->toBeFalse("a trilha contém em claro: {$secret}");
    }
}

it('a chamada sai com o X-Correlation-Id da requisição e grava a linha da trilha', function (): void {
    $requestId = $this->post('/tracing/charge')->assertNoContent()->headers->get('X-Correlation-Id');

    Http::assertSent(fn (HttpRequest $request): bool => $request->header('X-Correlation-Id') === [$requestId]);

    $log = OutboundHttpLog::query()->sole();

    expect($log->correlation_id)->toBe($requestId)
        ->and($log->correlation_origin)->toBe('http')
        ->and($log->method)->toBe('POST')
        ->and($log->host)->toBe('payments.example.test')
        ->and($log->path)->toBe('/v1/customers/{n}/charges')
        ->and($log->query_keys)->toBe(['access_token', 'page'])
        ->and($log->http_status)->toBe(201)
        ->and($log->attempt)->toBe(1)
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($log->request_bytes)->toBeGreaterThan(0)
        ->and($log->response_bytes)->toBeGreaterThan(0)
        ->and($log->error)->toBeNull()
        ->and($log->request_body)->toBeNull()
        ->and($log->response_body)->toBeNull();

    // A segunda camada (arquivo) tem a mesma linha, sem corpo.
    $line = collect($GLOBALS['outboundLogLines'])->firstWhere('message', 'http.outbound');
    expect($line['context']['correlation_id'] ?? null)->toBe($requestId)
        ->and($line['context']['path'] ?? null)->toBe('/v1/customers/{n}/charges');
});

it('a trilha NÃO contém token, senha, cabeçalho de autenticação, query, CPF, cartão nem e-mail', function (): void {
    $this->post('/tracing/charge')->assertNoContent();

    expect(OutboundHttpLog::query()->count())->toBe(1);

    expectNoSecretsIn(outboundTrailDump());
});

it('com withBodyInTrail() o corpo entra REDIGIDO (enviado e recebido)', function (): void {
    Http::withBodyInTrail()->withToken(TRACING_SECRET_TOKEN)->post('https://payments.example.test/v1/charges', [
        'amount' => 1500,
        'password' => TRACING_PASSWORD,
        'token' => TRACING_SECRET_TOKEN,
        'card' => ['number' => TRACING_CARD, 'cvv' => '123'],
        'customer' => ['cpf' => TRACING_CPF, 'email' => TRACING_EMAIL],
        'description' => 'Pedido do cliente '.TRACING_CPF.' cartão '.TRACING_CARD,
    ])->throw();

    $log = OutboundHttpLog::query()->sole();

    expect($log->request_body['amount'])->toBe(1500)
        ->and($log->request_body['password'])->toBe('[REDACTED]')
        ->and($log->request_body['token'])->toBe('[REDACTED]')
        ->and($log->request_body['card']['cvv'])->toBe('[REDACTED]')
        ->and($log->request_body['card']['number'])->toBe('**** **** **** 1111')
        ->and($log->request_body['customer']['cpf'])->toBe('123.***.***-09')
        ->and($log->request_body['customer']['email'])->toBe('m***@example.com')
        ->and($log->response_body['status'])->toBe('paid')
        ->and($log->response_body['access_token'])->toBe('[REDACTED]')
        ->and($log->response_body['card_number'])->toBe('[REDACTED]')
        ->and($log->response_body['customer']['document'])->toBe('123.***.***-09');

    expectNoSecretsIn(outboundTrailDump());
});

it('formulário também é redigido; corpo que não é JSON nem formulário entra só como tipo e tamanho', function (): void {
    Http::withBodyInTrail()->asForm()->post('https://payments.example.test/v1/tokens', [
        'client_secret' => TRACING_SECRET_TOKEN,
        'cpf' => TRACING_CPF,
    ]);

    Http::withBodyInTrail()->withBody('cartão '.TRACING_CARD.' senha '.TRACING_PASSWORD, 'text/plain')
        ->post('https://payments.example.test/v1/raw');

    [$form, $raw] = OutboundHttpLog::query()->orderBy('id')->get()->all();

    expect($form->request_body)->toBe(['client_secret' => '[REDACTED]', 'cpf' => '123.***.***-09'])
        ->and($raw->request_body)->toBe(['_omitted' => 'text/plain', 'bytes' => strlen('cartão '.TRACING_CARD.' senha '.TRACING_PASSWORD)]);

    expectNoSecretsIn(outboundTrailDump());
});

it('cada tentativa do retry() é uma linha, com o número da tentativa', function (): void {
    Http::fake(['flaky.example.test/*' => Http::sequence()->push('erro', 503)->push('erro', 503)->push(['ok' => true], 200)]);

    Http::retry(3, 0)->get('https://flaky.example.test/v1/status')->throw();

    expect(OutboundHttpLog::query()->orderBy('id')->get(['attempt', 'http_status'])->map->only(['attempt', 'http_status'])->all())
        ->toBe([
            ['attempt' => 1, 'http_status' => 503],
            ['attempt' => 2, 'http_status' => 503],
            ['attempt' => 3, 'http_status' => 200],
        ]);
});

it('falha de conexão: a exceção chega à aplicação como sempre, e a linha guarda o tipo do erro sem a URL', function (): void {
    // O mesmo que o Guzzle entrega num timeout: ConnectException com a URL
    // inteira (e a query) na mensagem.
    Http::fake(['down.example.test/*' => Http::failedConnection('cURL error 28: timeout for https://down.example.test/v1/x?access_token='.TRACING_SECRET_TOKEN)]);

    expect(fn () => Http::get('https://down.example.test/v1/x?access_token='.TRACING_SECRET_TOKEN))
        ->toThrow(ConnectionException::class);

    $log = OutboundHttpLog::query()->sole();

    expect($log->http_status)->toBeNull()
        ->and($log->error)->toStartWith('ConnectException: cURL error 28: timeout for [url]');

    expectNoSecretsIn(outboundTrailDump());
});

it('falha de conexão pelo caminho real do Guzzle (promessa rejeitada) também vira linha', function (): void {
    $url = 'https://refused.example.test/v1/x?token='.TRACING_SECRET_TOKEN;
    $handler = new MockHandler([new ConnectException('cURL error 7: Failed to connect for '.$url, new PsrRequest('GET', $url))]);

    expect(fn () => Http::setHandler($handler)->get($url))->toThrow(ConnectionException::class);

    $log = OutboundHttpLog::query()->sole();

    expect($log->host)->toBe('refused.example.test')
        ->and($log->http_status)->toBeNull()
        ->and($log->error)->toBe('ConnectException: cURL error 7: Failed to connect for [url]');

    expectNoSecretsIn(outboundTrailDump());
});

it('FAIL-OPEN só da trilha: sem a tabela, a chamada responde normalmente e a falha vai para o arquivo', function (): void {
    Schema::drop('outbound_http_logs');

    $response = Http::post('https://payments.example.test/v1/charges', ['token' => TRACING_SECRET_TOKEN]);

    expect($response->status())->toBe(201)
        ->and($response->json('status'))->toBe('paid');

    $failure = collect($GLOBALS['outboundLogLines'])->firstWhere('message', 'http.outbound.persist_failed');

    expect($failure)->not->toBeNull()
        ->and($failure['level'])->toBe('critical')
        ->and($failure['context']['host'] ?? null)->toBe('payments.example.test');

    expectNoSecretsIn(json_encode($GLOBALS['outboundLogLines']));
});

it('outbound_http_logs é só-acréscimo: UPDATE e DELETE recusados (instância e em massa)', function (): void {
    Http::get('https://payments.example.test/v1/charges/1');

    $log = OutboundHttpLog::query()->sole();

    foreach ([
        fn () => $log->update(['http_status' => 200]),
        fn () => $log->delete(),
        fn () => OutboundHttpLog::query()->update(['http_status' => 200]),
        fn () => OutboundHttpLog::query()->delete(),
        fn () => OutboundHttpLog::query()->where('id', $log->id)->forceDelete(),
        fn () => OutboundHttpLog::query()->increment('attempt'),
        fn () => OutboundHttpLog::query()->upsert([['uuid' => $log->uuid, 'http_status' => 1]], ['uuid'], ['http_status']),
        fn () => OutboundHttpLog::query()->touch(),
        fn () => OutboundHttpLog::truncate(),
        fn () => OutboundHttpLog::query()->updateOrInsert(['host' => 'x'], ['host' => 'y']),
    ] as $attempt) {
        expect($attempt)->toThrow(AppendOnlyViolationException::class);
    }

    expect(OutboundHttpLog::query()->sole()->http_status)->toBe(201);
});

it('no PostgreSQL, o gatilho recusa UPDATE/DELETE/TRUNCATE por SQL cru — fora da poda', function (): void {
    Http::get('https://payments.example.test/v1/charges/1');

    expect(OutboundHttpLogTrigger::installed())->toBeTrue();

    foreach ([
        fn () => DB::table('outbound_http_logs')->update(['http_status' => 1]),
        fn () => DB::table('outbound_http_logs')->delete(),
        fn () => DB::statement('TRUNCATE outbound_http_logs'),
    ] as $attempt) {
        try {
            DB::transaction(fn () => $attempt());
            $this->fail('o banco aceitou uma escrita na trilha append-only');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('TWS_OUTBOUND_HTTP_APPEND_ONLY');
        }
    }
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'gatilho só existe no PostgreSQL');

it('cabeçalho: não vai para destino em except_hosts, nem com withoutCorrelationHeader(), e não sobrescreve o da chamada', function (): void {
    config(['tracing.http.header.except_hosts' => ['*.partner.test']]);
    Http::fake();

    Http::get('https://api.partner.test/v1/a');
    Http::withoutCorrelationHeader()->get('https://payments.example.test/v1/b');
    Http::withHeaders(['X-Correlation-Id' => 'do-chamador'])->get('https://payments.example.test/v1/c');
    Http::get('https://payments.example.test/v1/d');

    $headers = collect(Http::recorded())->mapWithKeys(fn (array $pair) => [basename($pair[0]->url()) => $pair[0]->header('X-Correlation-Id')]);

    expect($headers['a'])->toBe([])
        ->and($headers['b'])->toBe([])
        ->and($headers['c'])->toBe(['do-chamador'])
        ->and(Str::isUuid($headers['d'][0] ?? null))->toBeTrue();

    // As quatro continuam na trilha.
    expect(OutboundHttpLog::query()->count())->toBe(4);
});

it('fora da requisição (console), a chamada ganha um id de origem console, o mesmo para o resto do processo', function (): void {
    app()->forgetScopedInstances();
    Http::fake();

    Http::get('https://payments.example.test/v1/a');
    Http::get('https://payments.example.test/v1/b');

    $logs = OutboundHttpLog::query()->orderBy('id')->get();

    expect($logs[0]->correlation_origin)->toBe('console')
        ->and($logs[0]->correlation_id)->toBe($logs[1]->correlation_id)
        ->and($logs[0]->correlation_id)->toBe(CorrelationId::current());
});

it('nome do cabeçalho configurável; trilha fora para destino em except_hosts', function (): void {
    config([
        'tracing.http.header.name' => 'X-Request-Trace',
        'tracing.http.trail.except_hosts' => ['metrics.example.test'],
    ]);
    Http::fake();

    Http::get('https://metrics.example.test/ping');
    Http::get('https://payments.example.test/v1/a');

    Http::assertSent(fn (HttpRequest $request): bool => $request->hasHeader('X-Request-Trace') && ! $request->hasHeader('X-Correlation-Id'));

    expect(OutboundHttpLog::query()->pluck('host')->all())->toBe(['payments.example.test']);
});

it('desligados por config, nem cabeçalho nem trilha', function (): void {
    $this->bootWithAppConfig([
        'tracing.http.header.enabled' => false,
        'tracing.http.trail.enabled' => false,
    ]);
    $this->artisan('migrate')->assertSuccessful();
    Http::fake();

    Http::get('https://payments.example.test/v1/a');

    Http::assertSent(fn (HttpRequest $request): bool => ! $request->hasHeader('X-Correlation-Id'));
    expect(OutboundHttpLog::query()->count())->toBe(0);
});

it('grava a conta quando um pacote informa qual é (e ignora o que não é uuid)', function (): void {
    $tenant = (string) Str::uuid();

    try {
        OutboundHttpTrail::resolveTenantUsing(fn (): string => $tenant);
        Http::get('https://payments.example.test/v1/a');

        OutboundHttpTrail::resolveTenantUsing(fn (): string => "não-é-uuid'; DROP TABLE x;--");
        Http::get('https://payments.example.test/v1/b');

        OutboundHttpTrail::resolveTenantUsing(fn () => throw new RuntimeException('sem conta'));
        Http::get('https://payments.example.test/v1/c');
    } finally {
        OutboundHttpTrail::resolveTenantUsing(null);
    }

    expect(OutboundHttpLog::query()->orderBy('id')->pluck('tenant_uuid')->all())->toBe([$tenant, null, null]);
});

it('a linha da trilha não vira evento de auditoria (é efeito, não ação)', function (): void {
    $trail = app(AuditTrail::class);

    $trail->within(AuditScope::console('teste'), fn () => Http::get('https://payments.example.test/v1/a'));

    expect(OutboundHttpLog::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(0);
});

// -----------------------------------------------------------------------------
// Retenção: outbound-http:prune
// -----------------------------------------------------------------------------

it('outbound-http:prune apaga só o que passou da retenção e deixa rastro na auditoria', function (): void {
    Http::get('https://payments.example.test/v1/old');
    DB::table('outbound_http_logs')->insert([
        ...collect((array) DB::table('outbound_http_logs')->first())->except(['id', 'uuid', 'created_at'])->all(),
        'uuid' => (string) Str::uuid7(),
        'path' => '/v1/older',
        'created_at' => now()->subDays(91),
    ]);

    config(['tracing.http.trail.retention_days' => 90]);

    $this->artisan('outbound-http:prune')
        ->expectsOutput(__('tracing.pruned', ['count' => 1, 'days' => 90]))
        ->assertSuccessful();

    expect(OutboundHttpLog::query()->pluck('path')->all())->toBe(['/v1/old']);

    $event = AuditEvent::query()->where('action', 'outbound_http_log.pruned')->sole();
    expect($event->changes['deleted']['after'])->toBe(1);
});

it('outbound-http:prune com retenção 0 não apaga nada', function (): void {
    config(['tracing.http.trail.retention_days' => 0]);

    $this->artisan('outbound-http:prune')
        ->expectsOutput(__('tracing.prune_disabled'))
        ->assertSuccessful();
});

it('a poda entra sozinha no agendador; cron vazio a desliga', function (): void {
    $command = fn (): ?string => collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command)
        ->first(fn (string $command): bool => str_contains($command, 'outbound-http:prune'));

    expect($command())->not->toBeNull();

    $this->bootWithAppConfig(['tracing.http.trail.prune_schedule' => '']);

    expect($command())->toBeNull();
});

it('o contexto do log de quem chama não muda por causa da chamada', function (): void {
    $id = (string) Str::uuid7();
    app(CorrelationContext::class)->enterRequest($id);

    Http::get('https://payments.example.test/v1/a');

    expect(CorrelationId::current())->toBe($id);
});

it('resposta 4xx/5xx também é gravada, com status', function (): void {
    Http::fake(['validation.example.test/*' => Http::response(['errors' => ['amount' => 'inválido']], 422)]);

    Http::post('https://validation.example.test/v1/charges');

    expect(OutboundHttpLog::query()->sole()->http_status)->toBe(422);
});
