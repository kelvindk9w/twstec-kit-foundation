<?php

declare(strict_types=1);

use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Twstec\Kit\Foundation\Logging\Enums\RequestActorType;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Logging\Redactor;

// =============================================================================
// A TRILHA RESPONDE QUEM, POR ONDE E COM QUAIS CABEÇALHOS (#121).
//
// Cada linha de `request_logs` diz quem fez (pessoa pelo uuid, chave de API,
// sistema numa rota de corpo assinado, anônimo — nunca o id interno nem o id
// da sessão), a cadeia de proxies e se ela era confiável, e uma lista FECHADA
// de cabeçalhos redigidos: credencial nunca entra, mesmo listada.
// =============================================================================

uses(RefreshDatabase::class);

/**
 * Pessoa autenticada sem tabela: o `id` interno é 42, o uuid é o que vale.
 */
function trailPerson(string $uuid): AuthUser
{
    $user = new AuthUser;
    $user->forceFill(['id' => 42, 'uuid' => $uuid]);

    return $user;
}

beforeEach(function (): void {
    Route::get('/_trilha/anonima', fn () => 'ok')->name('trilha.anonima');
    Route::middleware('web')->get('/_trilha/painel', fn () => 'ok')->name('trilha.painel');
    Route::get('/_trilha/chave', function (Request $request) {
        // O que o middleware de chave do pacote de contas faz.
        $request->attributes->set(RequestLog::API_KEY_ATTRIBUTE, '0193A3B4-0000-7000-8000-000000000abc');

        return 'ok';
    })->name('trilha.chave');
});

it('requisição anônima: ator anônimo, sem uuid, com o nome da rota', function (): void {
    $this->get('/_trilha/anonima')->assertOk();

    $log = RequestLog::query()->sole();

    expect($log->actor_type)->toBe(RequestActorType::Anonymous)
        ->and($log->actor_uuid)->toBeNull()
        ->and($log->api_key_uuid)->toBeNull()
        ->and($log->route_name)->toBe('trilha.anonima');
});

it('pessoa pela sessão: o uuid dela (nunca o id interno) e a referência da sessão, não o id', function (): void {
    // O grupo `web` cifra o cookie da sessão; a referência é um HMAC com a
    // mesma chave (a suíte do pacote roda sem APP_KEY no ambiente).
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $uuid = (string) Str::uuid();

    $this->actingAs(trailPerson($uuid))->get('/_trilha/painel')->assertOk();

    $log = RequestLog::query()->sole();
    $sessionId = session()->getId();

    expect($log->actor_type)->toBe(RequestActorType::User)
        ->and($log->actor_uuid)->toBe($uuid)
        ->and($log->actor_uuid)->not->toBe('42')
        ->and($log->session_ref)->toMatch('/^[0-9a-f]{32}$/')
        ->and($log->session_ref)->not->toBe($sessionId)
        ->and(json_encode($log->getAttributes()))->not->toContain($sessionId);
});

it('chamada por chave de API: tipo api_key e o uuid da chave', function (): void {
    $this->get('/_trilha/chave')->assertOk();

    $log = RequestLog::query()->sole();

    expect($log->actor_type)->toBe(RequestActorType::ApiKey)
        ->and($log->api_key_uuid)->toBe('0193a3b4-0000-7000-8000-000000000abc');
});

it('rota de corpo assinado: o ator é um sistema', function (): void {
    config(['security.validation.signed_body_paths' => ['_trilha/aviso/*']]);
    Route::post('/_trilha/aviso/{fonte}', fn () => response()->noContent());

    $this->postJson('/_trilha/aviso/provedor', ['evento' => 'pago'])->assertNoContent();

    expect(RequestLog::query()->sole()->actor_type)->toBe(RequestActorType::System);
});

it('grava só os cabeçalhos listados, redigidos; credencial nunca, mesmo listada', function (): void {
    config(['security.request_logging.headers' => 'accept,idempotency-key,referer,authorization,cookie,x-api-key,x-webhook-token,x-segredo-livre']);

    $this->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => 'pedido-123',
        'Referer' => 'https://loja.example.test/redefinir?token=abc123segredo#frag',
        'Authorization' => 'Bearer segredo-do-cabecalho',
        'X-Api-Key' => 'chave-do-cabecalho',
        'X-Webhook-Token' => 'token-do-cabecalho',
        'X-Segredo-Livre' => 'texto com sk_'.'live_51HxYzAbCdEfGhIjKl',
        'X-Fora-Da-Lista' => 'nao-deve-entrar',
    ])->get('/_trilha/anonima')->assertOk();

    $log = RequestLog::query()->sole();
    $dump = json_encode($log->getAttributes(), JSON_UNESCAPED_SLASHES);

    expect($log->headers)->toBe([
        'accept' => 'application/json',
        'idempotency-key' => 'pedido-123',
        'referer' => 'https://loja.example.test/redefinir',
        'x-segredo-livre' => 'texto com '.Redactor::MASK,
    ])
        ->and($dump)->not->toContain('segredo-do-cabecalho')
        ->and($dump)->not->toContain('chave-do-cabecalho')
        ->and($dump)->not->toContain('token-do-cabecalho')
        ->and($dump)->not->toContain('abc123segredo')
        ->and($dump)->not->toContain('nao-deve-entrar');
});

it('cadeia de proxies sem proxy confiável: item forjado vira [invalid], marcada como não confiável, e o ip é o da conexão', function (): void {
    config(['security.proxies.trusted' => []]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-For' => "198.51.100.7, 10.0.0.1<script>\r\n"])
        ->get('/_trilha/anonima')->assertOk();

    $log = RequestLog::query()->sole();

    expect($log->ip)->toBe('203.0.113.9')
        ->and($log->ip_chain)->toBe('198.51.100.7, [invalid]')
        ->and($log->forwarded_trusted)->toBeFalse();
});

it('cadeia de proxies vinda de proxy confiável: marcada como confiável, e o ip é o do cliente', function (): void {
    config(['security.proxies.trusted' => ['203.0.113.9']]);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeaders(['X-Forwarded-For' => '198.51.100.7'])
        ->get('/_trilha/anonima')->assertOk();

    $log = RequestLog::query()->sole();

    expect($log->ip)->toBe('198.51.100.7')
        ->and($log->ip_chain)->toBe('198.51.100.7')
        ->and($log->forwarded_trusted)->toBeTrue();
});
