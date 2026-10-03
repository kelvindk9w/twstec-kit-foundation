<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer;
use Twstec\Kit\Foundation\Idempotency\Contracts\IdempotencyScopeResolver;
use Twstec\Kit\Foundation\Idempotency\Exceptions\IdempotencyRefusedException;
use Twstec\Kit\Foundation\Idempotency\IdempotencyKey;
use Twstec\Kit\Foundation\Idempotency\IdempotencyScope;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;
use Twstec\Kit\Foundation\Idempotency\Middleware\HandleIdempotencyKey;
use Twstec\Kit\Foundation\Idempotency\RequestFingerprint;
use Twstec\Kit\Foundation\Logging\CorrelationId;

// =============================================================================
// IDEMPOTENCY-KEY NAS ESCRITAS DA API (middleware `idempotent`).
//
// Uma rota de teste cria um "pedido" (uma linha em idem_test_orders por
// execução) e devolve 201 com um valor aleatório no corpo — se a resposta de
// uma repetição for idêntica, ela veio do replay, não de uma nova execução.
//
// O escopo vem de um resolvedor de teste (cabeçalho X-Test-Tenant), como o
// módulo de contas faria; sem resolvedor, vale a pessoa autenticada.
// =============================================================================

uses(RefreshDatabase::class);

const IDEM_KEY = '3f1c2b9a-7d4e-4c1b-9a8f-2e6d5c4b3a21';
const IDEM_OTHER_KEY = '9b8a7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';

/** @var list<array{level: string, message: string, context: array<string, mixed>}> */
$GLOBALS['idemLogLines'] = [];

beforeEach(function (): void {
    $GLOBALS['idemLogLines'] = [];

    // A resposta guardada é cifrada com a APP_KEY. A aplicação mínima do
    // Testbench não tem uma (no CI não há .env; no container de dev, a do .env
    // do starter vazava pelo ambiente): cada teste ganha a sua, aleatória.
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $GLOBALS['idemLogLines'][] = ['level' => $event->level, 'message' => (string) $event->message, 'context' => $event->context];
    });

    // O envelope de erro da API (o módulo de contas o instala num app real).
    app(ExceptionHandler::class)->renderable(fn (Throwable $e, Request $request) => app(ApiErrorRenderer::class)($e, $request));

    Schema::create('idem_test_orders', function ($table): void {
        $table->id();
        $table->string('tenant')->nullable();
        $table->string('item');
    });

    app()->instance(IdempotencyScopeResolver::class, new class implements IdempotencyScopeResolver
    {
        public function resolve(Request $request): ?string
        {
            $tenant = $request->header('X-Test-Tenant');

            return is_string($tenant) && $tenant !== '' ? 'tenant:'.$tenant : null;
        }
    });

    $create = function (Request $request) {
        $data = $request->validate(['item' => ['required', 'string', 'max:50']]);

        if ($data['item'] === 'falha') {
            return response()->json(['error' => 'quebrou'], 503);
        }

        if ($data['item'] === 'excecao') {
            throw new RuntimeException('falha inesperada');
        }

        $id = DB::table('idem_test_orders')->insertGetId(['tenant' => $request->header('X-Test-Tenant'), 'item' => $data['item']]);

        // Gravou e DEPOIS falhou (o 5xx chega com a escrita feita).
        if ($data['item'] === 'grava-e-falha') {
            return response()->json(['error' => 'quebrou depois de gravar'], 503);
        }

        // Gravou com sucesso, e o banco "cai" antes de a chave ser marcada
        // concluída: a tabela de chaves passa a apontar para uma conexão que
        // não existe (só para a próxima operação do middleware).
        if ($data['item'] === 'conclusao-falha') {
            config()->set('idempotency.connection', 'conexao-que-caiu');
        }

        return response()->json([
            'data' => ['id' => $id, 'item' => $data['item'], 'nonce' => (string) Str::uuid()],
            'secret_token' => 'segredo-exibido-uma-vez-'.$id,
        ], 201)->header('Location', '/api/pedidos/'.$id);
    };

    Route::post('api/pedidos', $create)->middleware('idempotent');
    Route::patch('api/pedidos', $create)->middleware('idempotent');
    Route::get('api/pedidos', fn () => response()->json(['ok' => true]))->middleware('idempotent');
    Route::post('api/pedidos-obrigatorios', $create)->middleware('idempotent:required');
    Route::post('api/pedidos-atomicos', $create)->middleware('idempotent:transactional');
    Route::post('api/tokens', $create)->middleware(HandleIdempotencyKey::using(withhold: true, keep: ['data.id', 'data.item']));
    // Declarada ANTES da autenticação: a lista de prioridade corrige a ordem.
    Route::post('api/pedidos-autenticados', $create)->middleware(['idempotent', 'auth']);
});

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function idemPost(array $body = ['item' => 'caderno'], ?string $key = IDEM_KEY, string $tenant = 'conta-a', string $uri = '/api/pedidos', array $headers = []): TestResponse
{
    $headers = ['X-Test-Tenant' => $tenant, ...$headers];

    if ($key !== null) {
        $headers['Idempotency-Key'] = $key;
    }

    return test()->postJson($uri, $body, $headers);
}

function idemOrders(): int
{
    return DB::table('idem_test_orders')->count();
}

/**
 * @return list<array{level: string, message: string, context: array<string, mixed>}>
 */
function idemLog(string $message): array
{
    return array_values(array_filter($GLOBALS['idemLogLines'], fn (array $line): bool => $line['message'] === $message));
}

it('dois POST com a mesma chave: UMA escrita e duas respostas idênticas, a segunda marcada como replay', function (): void {
    $first = idemPost();
    $second = idemPost();

    $first->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $second->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('Location', $first->headers->get('Location'))
        ->assertHeader('Content-Type', $first->headers->get('Content-Type'));

    expect(idemOrders())->toBe(1)
        ->and($second->getContent())->toBe($first->getContent());
});

it('o replay tem o próprio correlation id e aponta o da requisição original', function (): void {
    $first = idemPost();
    $second = idemPost();

    $originalId = $first->headers->get(CorrelationId::HEADER);

    expect($originalId)->not->toBeNull()
        ->and($second->headers->get(CorrelationId::HEADER))->not->toBe($originalId)
        ->and($second->headers->get('X-Original-Correlation-Id'))->toBe($originalId);

    $replayed = idemLog('api.idempotency.replayed');
    expect($replayed)->toHaveCount(1)
        ->and($replayed[0]['context']['original_correlation_id'])->toBe($originalId)
        ->and($replayed[0]['context']['correlation_id'])->toBe($second->headers->get(CorrelationId::HEADER));
});

it('reordenar as chaves do JSON é a mesma requisição (replay, não recusa)', function (): void {
    $first = test()->call('POST', '/api/pedidos', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TEST_TENANT' => 'conta-a', 'HTTP_IDEMPOTENCY_KEY' => IDEM_KEY,
    ], '{"item":"caderno","obs":{"a":1,"b":2}}');
    $second = test()->call('POST', '/api/pedidos', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_TEST_TENANT' => 'conta-a', 'HTTP_IDEMPOTENCY_KEY' => IDEM_KEY,
    ], '{ "obs": {"b": 2, "a": 1}, "item": "caderno" }');

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect(idemOrders())->toBe(1);
});

it('mesma chave com corpo diferente: recusa 422 com código estável, sem executar', function (): void {
    idemPost(['item' => 'caderno'])->assertCreated();

    idemPost(['item' => 'lápis'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_reused')
        ->assertJsonPath('error.message', __('api.errors.idempotency_key_reused'))
        ->assertJsonStructure(['error' => ['code', 'message', 'correlation_id']]);

    // Mesma chave em outra rota também é "outra requisição".
    idemPost(['item' => 'caderno'], uri: '/api/pedidos-obrigatorios')->assertStatus(422);

    expect(idemOrders())->toBe(1);

    $refused = idemLog('api.idempotency.refused');
    expect(array_column(array_column($refused, 'context'), 'reason'))->toBe(['key_reused', 'key_reused'])
        ->and($refused[0]['level'])->toBe('warning');
});

it('a mesma chave em dois escopos (contas) são duas escritas independentes', function (): void {
    $a = idemPost(tenant: 'conta-a');
    $b = idemPost(tenant: 'conta-b');

    $a->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    $b->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(idemOrders())->toBe(2)
        ->and(DB::table('idem_test_orders')->orderBy('id')->pluck('tenant')->all())->toBe(['conta-a', 'conta-b'])
        ->and($a->json('data.nonce'))->not->toBe($b->json('data.nonce'))
        // E nenhuma recebe a resposta da outra.
        ->and(idemPost(tenant: 'conta-b')->json('data.id'))->toBe($b->json('data.id'));
});

it('sem resolvedor de escopo, a chave vale por pessoa autenticada', function (): void {
    app()->forgetInstance(IdempotencyScopeResolver::class);
    app()->offsetUnset(IdempotencyScopeResolver::class);

    $ana = new GenericUser(['id' => 1]);
    $bia = new GenericUser(['id' => 2]);

    test()->actingAs($ana)->postJson('/api/pedidos', ['item' => 'x'], ['Idempotency-Key' => IDEM_KEY])->assertCreated();
    test()->actingAs($ana)->postJson('/api/pedidos', ['item' => 'x'], ['Idempotency-Key' => IDEM_KEY])->assertHeader('Idempotent-Replayed', 'true');
    test()->actingAs($bia)->postJson('/api/pedidos', ['item' => 'x'], ['Idempotency-Key' => IDEM_KEY])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(idemOrders())->toBe(2)
        ->and(IdempotencyScope::resolve(Request::create('/')))->toBeNull();
});

it('sem escopo identificado, FALHA FECHADA: a requisição com chave não executa', function (): void {
    idemPost(tenant: '')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error');

    expect(idemOrders())->toBe(0)
        ->and(idemLog('api.idempotency.scope_missing'))->toHaveCount(1);

    // Sem chave (opcional), a rota segue funcionando como sempre.
    idemPost(key: null, tenant: '')->assertCreated();
    expect(idemOrders())->toBe(1);
});

it('a autenticação roda ANTES da idempotência, qualquer que seja a ordem declarada na rota', function (): void {
    app()->forgetInstance(IdempotencyScopeResolver::class);
    app()->offsetUnset(IdempotencyScopeResolver::class);

    idemPost(uri: '/api/pedidos-autenticados')->assertUnauthorized();

    expect(idemOrders())->toBe(0)
        ->and(idemLog('api.idempotency.scope_missing'))->toBe([])
        ->and(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);
});

it('sem a chave: rota opcional executa sempre; rota obrigatória recusa com 400', function (): void {
    idemPost(key: null)->assertCreated();
    idemPost(key: null)->assertCreated();

    idemPost(key: null, uri: '/api/pedidos-obrigatorios')
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_missing');

    expect(idemOrders())->toBe(2)
        ->and(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);
});

it('chave fora do formato: 400 com código estável, sem executar e sem a chave no log', function (string $key): void {
    idemPost(key: $key)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'idempotency_key_invalid');

    expect(idemOrders())->toBe(0);

    $refused = idemLog('api.idempotency.refused');
    expect($refused)->toHaveCount(1)
        ->and($refused[0]['context']['reason'])->toBe('invalid_key')
        ->and(json_encode($GLOBALS['idemLogLines']))->not->toContain($key);
})->with([
    'curta' => ['pedido-1'],
    'caractere proibido' => ['pedido<script>-0000000001'],
    'longa' => [str_repeat('k', 300)],
]);

it('dois cabeçalhos Idempotency-Key na mesma requisição são recusados', function (): void {
    $request = Request::create('/api/pedidos', 'POST');
    $request->headers->set('Idempotency-Key', [IDEM_KEY, IDEM_OTHER_KEY]);

    expect(fn () => IdempotencyKey::fromRequest($request))->toThrow(IdempotencyRefusedException::class);
});

it('nos métodos fora da lista (GET), a chave é ignorada', function (): void {
    test()->getJson('/api/pedidos', ['Idempotency-Key' => IDEM_KEY, 'X-Test-Tenant' => 'conta-a'])->assertOk();

    expect(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);
});

it('PATCH também é protegido (configurável)', function (): void {
    $headers = ['Idempotency-Key' => IDEM_KEY, 'X-Test-Tenant' => 'conta-a'];

    test()->patchJson('/api/pedidos', ['item' => 'x'], $headers)->assertCreated();
    test()->patchJson('/api/pedidos', ['item' => 'x'], $headers)->assertHeader('Idempotent-Replayed', 'true');
    expect(idemOrders())->toBe(1);

    config()->set('idempotency.methods', ['POST']);
    test()->patchJson('/api/pedidos', ['item' => 'x'], $headers)->assertHeaderMissing('Idempotent-Replayed');
    expect(idemOrders())->toBe(2);
});

it('chave vencida executa de novo; antes de vencer, replay', function (): void {
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    idemPost()->assertCreated();

    $this->travelTo($start->addHours(24)->subSecond());
    idemPost()->assertHeader('Idempotent-Replayed', 'true');
    expect(idemOrders())->toBe(1);

    $this->travelTo($start->addHours(24));
    idemPost()->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(idemOrders())->toBe(2);

    // Vencida, ela vale como nova — inclusive com outro corpo.
    $this->travelTo($start->addHours(49));
    idemPost(['item' => 'outro'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
    expect(idemOrders())->toBe(3);
});

it('a validade segue IDEMPOTENCY_TTL_HOURS', function (): void {
    config()->set('idempotency.ttl_hours', 2);
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    idemPost()->assertCreated();
    $this->travelTo($start->addHours(2));
    idemPost()->assertHeaderMissing('Idempotent-Replayed');

    expect(idemOrders())->toBe(2);
});

it('erro de validação NÃO congela a chave: corrigido o corpo, a mesma chave executa', function (): void {
    idemPost(['item' => ''])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');

    idemPost(['item' => 'caderno'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(idemOrders())->toBe(1);
});

it('com IDEMPOTENCY_FREEZE_VALIDATION_ERRORS, o 422 de validação é guardado e repetido', function (): void {
    config()->set('idempotency.freeze_validation_errors', true);

    $first = idemPost(['item' => '']);
    $second = idemPost(['item' => '']);

    $first->assertStatus(422);
    $second->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');
    expect($second->getContent())->toBe($first->getContent());

    idemPost(['item' => 'caderno'])->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_reused');
    expect(idemOrders())->toBe(0);
});

it('erro de servidor (5xx) e exceção liberam a chave: a próxima tentativa executa', function (): void {
    idemPost(['item' => 'falha'])->assertStatus(503);
    expect(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);

    idemPost(['item' => 'falha'])->assertStatus(503)->assertHeaderMissing('Idempotent-Replayed');

    idemPost(['item' => 'excecao'], key: IDEM_OTHER_KEY)->assertStatus(500);
    expect(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);

    idemPost(['item' => 'caderno'], key: IDEM_OTHER_KEY)->assertCreated();
    // Dois 503 e a exceção (que o roteador já entrega como resposta 500).
    expect(idemOrders())->toBe(1)
        ->and(array_column(array_column(idemLog('api.idempotency.released'), 'context'), 'response_status'))->toBe([503, 503, 500]);
});

it('erro de cliente listado em IDEMPOTENCY_FREEZE_CLIENT_ERRORS congela; 401/403/429 nunca', function (): void {
    config()->set('idempotency.freeze_client_errors', [404, 403, 429]);

    expect(HandleIdempotencyKey::freezes(201))->toBeTrue()
        ->and(HandleIdempotencyKey::freezes(302))->toBeTrue()
        ->and(HandleIdempotencyKey::freezes(404))->toBeTrue()
        ->and(HandleIdempotencyKey::freezes(410))->toBeFalse()
        ->and(HandleIdempotencyKey::freezes(403))->toBeFalse()
        ->and(HandleIdempotencyKey::freezes(429))->toBeFalse()
        ->and(HandleIdempotencyKey::freezes(401))->toBeFalse()
        ->and(HandleIdempotencyKey::freezes(500))->toBeFalse()
        ->and(HandleIdempotencyKey::freezes(503))->toBeFalse()
        ->and(HandleIdempotencyKey::freezes(422))->toBeFalse();
});

it('mesma chave ainda em processamento: 409 com Retry-After, sem executar', function (): void {
    $request = Request::create('/api/pedidos', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['item' => 'caderno']));
    app(IdempotencyStore::class)->acquire(
        IdempotencyScope::hash('tenant:conta-a'),
        hash('sha256', IDEM_KEY),
        RequestFingerprint::hash($request),
        'POST',
        'api/pedidos',
        null,
    );

    idemPost()
        ->assertStatus(409)
        ->assertHeader('Retry-After', '1')
        ->assertJsonPath('error.code', 'idempotency_request_in_progress');

    expect(idemOrders())->toBe(0)
        ->and(idemLog('api.idempotency.refused')[0]['context']['reason'])->toBe('in_progress');
});

it('com espera curta configurada, a segunda recebe o replay quando a primeira termina a tempo', function (): void {
    config()->set('idempotency.wait_ms', 500);

    // Uma execução "em andamento" do mesmo pedido.
    $first = idemPost();
    $first->assertCreated();
    $completed = DB::table(IdempotencyStore::TABLE)->first();
    DB::table(IdempotencyStore::TABLE)->update(['status' => IdempotencyStore::PROCESSING, 'response' => null, 'response_status' => null]);

    Sleep::fake();
    Sleep::whenFakingSleep(function () use ($completed): void {
        // A "primeira" termina durante a espera.
        DB::table(IdempotencyStore::TABLE)->update([
            'status' => IdempotencyStore::COMPLETED,
            'response' => $completed->response,
            'response_status' => $completed->response_status,
        ]);
    });

    idemPost()->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    Sleep::assertSleptTimes(1);
    expect(idemOrders())->toBe(1);
});

it('com espera curta, se a primeira não termina a tempo, 409 depois de esperar', function (): void {
    config()->set('idempotency.wait_ms', 300);

    idemPost()->assertCreated();
    DB::table(IdempotencyStore::TABLE)->update(['status' => IdempotencyStore::PROCESSING]);

    Sleep::fake();

    idemPost()->assertStatus(409);
    Sleep::assertSleptTimes(3);
    expect(idemOrders())->toBe(1);
});

it('com prazo de retomada configurado, a execução abandonada é retomada depois dele — só com o MESMO corpo', function (): void {
    config()->set('idempotency.abandoned_takeover_seconds', 120);
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    idemPost()->assertCreated();
    // Simula o processo que morreu antes de guardar o resultado.
    DB::table(IdempotencyStore::TABLE)->update(['status' => IdempotencyStore::PROCESSING, 'locked_until' => $start->addSeconds(120), 'response' => null]);

    $this->travelTo($start->addSeconds(119));
    idemPost()->assertStatus(409);

    $this->travelTo($start->addSeconds(120));
    idemPost(['item' => 'outro'])->assertStatus(422);
    idemPost()->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(idemOrders())->toBe(2)
        ->and(idemLog('api.idempotency.abandoned_taken_over'))->toHaveCount(1)
        ->and(DB::table(IdempotencyStore::TABLE)->value('status'))->toBe(IdempotencyStore::COMPLETED);
});

it('a execução antiga que termina depois de retomada não sobrescreve a nova', function (): void {
    $this->freezeSecond();
    $store = app(IdempotencyStore::class);
    $scope = IdempotencyScope::hash('tenant:x');
    $key = hash('sha256', IDEM_KEY);

    $old = $store->acquire($scope, $key, 'h', 'POST', 'r', null, 120);
    $this->travel(121)->seconds();
    $new = $store->acquire($scope, $key, 'h', 'POST', 'r', null, 120);

    expect($old->owner)->not->toBeNull()
        ->and($new->owner)->not->toBeNull()
        ->and($new->owner)->not->toBe($old->owner)
        ->and($store->complete($scope, $key, (string) $old->owner, 201, 'x', false))->toBeFalse()
        ->and($store->complete($scope, $key, (string) $new->owner, 201, 'y', false))->toBeTrue();

    $store->release($scope, $key, (string) $old->owner);
    expect(DB::table(IdempotencyStore::TABLE)->value('response'))->toBe('y');
});

it('guarda só HASHES do pedido e a resposta CIFRADA: nada do corpo nem da chave em claro na tabela', function (): void {
    idemPost(['item' => 'marcador-do-corpo-xyz'])->assertCreated();

    $row = (array) DB::table(IdempotencyStore::TABLE)->first();
    $dump = json_encode($row);

    expect($dump)->not->toContain('marcador-do-corpo-xyz')
        ->and($dump)->not->toContain('segredo-exibido-uma-vez')
        ->and($dump)->not->toContain(IDEM_KEY)
        ->and($dump)->not->toContain('conta-a')
        ->and($row['key_hash'])->toBe(hash('sha256', IDEM_KEY))
        ->and($row['scope_hash'])->toBe(hash('sha256', 'tenant:conta-a'))
        ->and($row['route'])->toBe('api/pedidos')
        ->and($row['response_withheld'])->toBeFalsy();

    // Decifrável só com a chave da aplicação.
    $snapshot = json_decode(Crypt::decryptString((string) $row['response']), true);
    expect(base64_decode((string) $snapshot['body']))->toContain('marcador-do-corpo-xyz');
});

it('rota de segredo exibido UMA vez (withhold): o segredo nunca vai à tabela e o replay não o reexibe', function (): void {
    $first = idemPost(uri: '/api/tokens');
    $second = idemPost(uri: '/api/tokens');

    $first->assertCreated();
    expect($first->json('secret_token'))->toStartWith('segredo-exibido-uma-vez-');

    $second->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertHeader('Location', $first->headers->get('Location'))
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertJsonPath('data.item', 'caderno')
        ->assertJsonPath('idempotency.replayed', true)
        ->assertJsonPath('idempotency.body_withheld', true)
        ->assertJsonPath('idempotency.message', __('api.idempotency.withheld'))
        ->assertJsonMissingPath('secret_token')
        ->assertJsonMissingPath('data.nonce');

    $row = DB::table(IdempotencyStore::TABLE)->first();
    $decrypted = Crypt::decryptString((string) $row->response);

    expect(idemOrders())->toBe(1)
        ->and((bool) $row->response_withheld)->toBeTrue()
        ->and($decrypted)->not->toContain('segredo-exibido-uma-vez')
        ->and($decrypted)->not->toContain($first->json('data.nonce'));
});

it('resposta maior que o teto é guardada sem corpo (replay "já processada", sem nova execução)', function (): void {
    config()->set('idempotency.max_response_bytes', 10);

    idemPost()->assertCreated();
    idemPost()->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('idempotency.body_withheld', true);

    expect(idemOrders())->toBe(1);
});

it('resposta guardada ilegível (APP_KEY trocada) nunca re-executa: replay sem corpo e erro no log', function (): void {
    idemPost()->assertCreated();
    DB::table(IdempotencyStore::TABLE)->update(['response' => 'adulterado']);

    idemPost()->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('idempotency.body_withheld', true);

    expect(idemOrders())->toBe(1)
        ->and(idemLog('api.idempotency.replay_unreadable'))->toHaveCount(1);
});

it('o log das recusas e dos replays traz a impressão curta da chave, nunca a chave', function (): void {
    idemPost()->assertCreated();
    idemPost()->assertCreated();
    idemPost(['item' => 'outro'])->assertStatus(422);

    $lines = [...idemLog('api.idempotency.replayed'), ...idemLog('api.idempotency.refused')];

    expect($lines)->toHaveCount(2)
        ->and(json_encode($GLOBALS['idemLogLines']))->not->toContain(IDEM_KEY)
        ->and(array_unique(array_column(array_column($lines, 'context'), 'key_fingerprint')))->toBe([substr(hash('sha256', IDEM_KEY), 0, 12)]);
});

it('parâmetro desconhecido na rota é erro de montagem, não um padrão silencioso', function (): void {
    Route::post('api/mal-montada', fn () => response()->json([], 201))->middleware('idempotent:obrigatoria');

    idemPost(uri: '/api/mal-montada')->assertStatus(500);

    expect(HandleIdempotencyKey::using(required: true, withhold: true, keep: ['data.uuid', 'data.public_key']))
        ->toBe('idempotent:required,withhold,keep=data.uuid|data.public_key')
        ->and(HandleIdempotencyKey::using())->toBe('idempotent:optional')
        ->and(HandleIdempotencyKey::using(required: true, transactional: true))->toBe('idempotent:required,transactional');
});

it('idempotency:prune remove só as chaves vencidas', function (): void {
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    idemPost(key: IDEM_KEY)->assertCreated();
    $this->travelTo($start->addHours(23));
    idemPost(key: IDEM_OTHER_KEY)->assertCreated();

    $this->travelTo($start->addHours(24));
    $this->artisan('idempotency:prune')->assertSuccessful();

    expect(DB::table(IdempotencyStore::TABLE)->pluck('key_hash')->all())->toBe([hash('sha256', IDEM_OTHER_KEY)]);
});

it('a poda entra sozinha no agendador, de hora em hora', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'idempotency:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('35 * * * *');
});

it('FALHA AO CONCLUIR depois de a rota gravar: por padrão, a nova tentativa NUNCA executa de novo (409 até a chave vencer)', function (): void {
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    // A rota grava e responde 201; a gravação da conclusão falha.
    idemPost(['item' => 'conclusao-falha'])->assertCreated();
    config()->set('idempotency.connection', null);

    $failed = idemLog('api.idempotency.persist_failed');
    expect(idemOrders())->toBe(1)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['level'])->toBe('critical')
        ->and($failed[0]['context']['rolled_back'])->toBeFalse()
        ->and(DB::table(IdempotencyStore::TABLE)->value('status'))->toBe(IdempotencyStore::PROCESSING)
        ->and(DB::table(IdempotencyStore::TABLE)->value('locked_until'))->toBeNull();

    // Muito depois do antigo prazo de 120 s, e até o último segundo da
    // validade: 409, sem segunda escrita.
    foreach ([121, 3600, 24 * 3600 - 1] as $seconds) {
        $this->travelTo($start->addSeconds($seconds));
        idemPost(['item' => 'conclusao-falha'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_request_in_progress');
    }

    expect(idemOrders())->toBe(1);

    // Vencida, vale como chave nova (a regra de toda chave vencida).
    $this->travelTo($start->addHours(24));
    idemPost(['item' => 'caderno'])->assertCreated();
    expect(idemOrders())->toBe(2);
});

it('com IDEMPOTENCY_ABANDONED_TAKEOVER_SECONDS > 0, a falha ao concluir pode virar segunda execução depois do prazo (o risco documentado)', function (): void {
    config()->set('idempotency.abandoned_takeover_seconds', 60);
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    idemPost(['item' => 'conclusao-falha'])->assertCreated();
    config()->set('idempotency.connection', null);

    $this->travelTo($start->addSeconds(59));
    idemPost(['item' => 'conclusao-falha'])->assertStatus(409);

    $this->travelTo($start->addSeconds(60));
    idemPost(['item' => 'conclusao-falha'])->assertCreated();
    config()->set('idempotency.connection', null);

    expect(idemOrders())->toBe(2)
        ->and(idemLog('api.idempotency.abandoned_taken_over'))->toHaveCount(1);
});

it('modo transactional: a escrita da rota e a conclusão da chave são confirmadas juntas', function (): void {
    $first = idemPost(uri: '/api/pedidos-atomicos');
    $second = idemPost(uri: '/api/pedidos-atomicos');

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and(idemOrders())->toBe(1)
        ->and(DB::table(IdempotencyStore::TABLE)->value('status'))->toBe(IdempotencyStore::COMPLETED);
});

it('modo transactional: falha ao concluir DESFAZ a escrita da rota e responde erro; a retomada depois do prazo executa uma vez', function (): void {
    $this->freezeSecond();
    $start = CarbonImmutable::now();

    // O banco das chaves "cai" depois de a rota gravar: a conclusão falha, a
    // transação inteira é desfeita e nem a liberação da chave consegue
    // passar (o mesmo banco fora) — a linha fica com o prazo do modo.
    idemPost(['item' => 'conclusao-falha'], uri: '/api/pedidos-atomicos')->assertStatus(500);
    config()->set('idempotency.connection', null);

    $failed = idemLog('api.idempotency.persist_failed');
    expect(idemOrders())->toBe(0)
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]['level'])->toBe('critical')
        ->and($failed[0]['context']['rolled_back'])->toBeTrue()
        ->and(idemLog('api.idempotency.release_failed'))->toHaveCount(1)
        ->and(DB::table(IdempotencyStore::TABLE)->value('status'))->toBe(IdempotencyStore::PROCESSING);

    $this->travelTo($start->addSeconds(119));
    idemPost(['item' => 'conclusao-falha'], uri: '/api/pedidos-atomicos')->assertStatus(409);

    // Nada foi confirmado: retomar é seguro, e executa uma vez só.
    $this->travelTo($start->addSeconds(120));
    idemPost(['item' => 'caderno-atomico'], uri: '/api/pedidos-atomicos')->assertStatus(422);
    idemPost(['item' => 'conclusao-falha'], uri: '/api/pedidos-atomicos')->assertStatus(500);
    config()->set('idempotency.connection', null);
    expect(idemOrders())->toBe(0);

    idemPost(['item' => 'caderno'], uri: '/api/pedidos-atomicos', key: IDEM_OTHER_KEY)->assertCreated();
    idemPost(['item' => 'caderno'], uri: '/api/pedidos-atomicos', key: IDEM_OTHER_KEY)->assertHeader('Idempotent-Replayed', 'true');
    expect(idemOrders())->toBe(1);
});

it('modo transactional: 5xx desfaz a escrita e a chave fica livre na hora', function (): void {
    idemPost(['item' => 'grava-e-falha'], uri: '/api/pedidos-atomicos', key: '7a6b5c4d-3e2f-4a1b-8c9d-0e1f2a3b4c5d')->assertStatus(503);
    expect(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);
});

it('modo transactional: 5xx depois de gravar desfaz a escrita e libera a chave', function (): void {
    idemPost(['item' => 'grava-e-falha'], uri: '/api/pedidos-atomicos')->assertStatus(503);

    expect(idemOrders())->toBe(0)
        ->and(DB::table(IdempotencyStore::TABLE)->count())->toBe(0);

    // Nas rotas comuns, o mesmo 5xx deixa a escrita (por isso a recomendação
    // de gravar em transação).
    idemPost(['item' => 'grava-e-falha'], key: IDEM_OTHER_KEY)->assertStatus(503);
    expect(idemOrders())->toBe(1);
});

it('modo transactional: execução abandonada é retomada depois de IDEMPOTENCY_LOCK_SECONDS (nada foi confirmado)', function (): void {
    $this->freezeSecond();
    $start = CarbonImmutable::now();
    $request = Request::create('/api/pedidos-atomicos', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['item' => 'caderno']));

    // Um processo que morreu no meio da transação: só a linha "em processamento".
    app(IdempotencyStore::class)->acquire(IdempotencyScope::hash('tenant:conta-a'), hash('sha256', IDEM_KEY), RequestFingerprint::hash($request), 'POST', 'api/pedidos-atomicos', null, IdempotencyStore::lockSeconds());

    $this->travelTo($start->addSeconds(119));
    idemPost(uri: '/api/pedidos-atomicos')->assertStatus(409);

    $this->travelTo($start->addSeconds(120));
    idemPost(uri: '/api/pedidos-atomicos')->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

    expect(idemOrders())->toBe(1);
});

it('modo transactional com a tabela de chaves em outra conexão é erro de montagem (não seria atômico)', function (): void {
    config()->set('database.connections.outra', config('database.connections.'.config('database.default')));
    config()->set('idempotency.connection', 'outra');

    idemPost(uri: '/api/pedidos-atomicos')->assertStatus(500);

    config()->set('idempotency.connection', null);
    expect(idemOrders())->toBe(0);
});

it('sem chave de cifra utilizável (APP_KEY ausente): FALHA FECHADA antes de executar, com log crítico; sem a chave, a rota segue', function (string $appKey): void {
    config()->set('app.key', $appKey);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');

    idemPost()->assertStatus(500)->assertJsonPath('error.code', 'server_error');

    $unavailable = idemLog('api.idempotency.encryption_unavailable');
    expect(idemOrders())->toBe(0)
        ->and(DB::table(IdempotencyStore::TABLE)->count())->toBe(0)
        ->and($unavailable)->toHaveCount(1)
        ->and($unavailable[0]['level'])->toBe('critical');

    // A requisição sem Idempotency-Key não depende da cifra.
    idemPost(key: null)->assertCreated();
    expect(idemOrders())->toBe(1);
})->with([
    'ausente' => [''],
    'de tamanho errado' => ['base64:'.base64_encode('curta')],
]);
