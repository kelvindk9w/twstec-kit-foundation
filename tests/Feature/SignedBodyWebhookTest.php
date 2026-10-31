<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;

// Webhook RECEBIDO com corpo autenticado por assinatura na aplicação (config
// security.validation.signed_body_paths): o texto é de terceiros — a mensagem
// de um cliente final, dado pessoal. O filtro de ataques não pode recusá-lo
// (o provedor reenviaria e descartaria a mensagem) e a trilha não pode
// copiá-lo. Query, cabeçalhos, caminho e o teto de inspeção continuam valendo.

uses(RefreshDatabase::class);

const SIGNED_BODY_TEXT = 'Olá, meu CPF é 123 e o código é <script>alert(1)</script>';

beforeEach(function (): void {
    config()->set('security.validation.signed_body_paths', ['webhooks/whatsapp', 'webhooks/meta/*']);

    Route::post('/webhooks/whatsapp', fn () => response()->json(['ok' => true]));
    Route::post('/webhooks/meta/{provider}', fn () => response()->json(['ok' => true]));
    Route::post('/webhooks/outro', fn () => response()->json(['ok' => true]));
});

/**
 * @return array<string, mixed>
 */
function signedWebhookBody(string $text): array
{
    return ['entry' => [['changes' => [['value' => ['messages' => [['text' => ['body' => $text]]]]]]]]];
}

it('deixa passar o corpo que parece ataque, nos dois modos, sem marcar nem gravar o texto', function (string $mode, string $text) {
    config()->set('security.validation.mode', $mode);

    $body = signedWebhookBody($text);

    $this->postJson('/webhooks/whatsapp', $body)->assertOk()->assertJson(['ok' => true]);

    $log = RequestLog::query()->sole();
    $gravado = (string) json_encode($log->payload);

    expect($log->status)->toBe(RequestLogStatus::Concluida)
        ->and($log->attack_type)->toBeNull()
        ->and($gravado)->not->toContain('script')
        ->and($gravado)->not->toContain('OR')
        ->and($gravado)->not->toContain('CPF')
        ->and($log->payload['_corpo_assinado'])->toBe([
            'tamanho_bytes' => strlen((string) json_encode($body)),
            'formato' => 'json',
        ]);
})->with([
    'observe' => 'observe',
    'block' => 'block',
])->with([
    'xss' => SIGNED_BODY_TEXT,
    'sqli' => "Meu CPF é 123 e o pedido é 1' OR '1'='1",
]);

it('o prefixo literal com /* também vale', function () {
    config()->set('security.validation.mode', 'block');

    $this->postJson('/webhooks/meta/instagram', signedWebhookBody(SIGNED_BODY_TEXT))->assertOk();

    expect(RequestLog::query()->sole()->attack_type)->toBeNull();
});

it('rota fora da lista continua como antes: recusa no block, com a evidência neutralizada', function () {
    config()->set('security.validation.mode', 'block');

    $this->postJson('/webhooks/outro', signedWebhookBody(SIGNED_BODY_TEXT))->assertStatus(422);

    $log = RequestLog::query()->sole();

    expect($log->status)->toBe(RequestLogStatus::Bloqueada)
        ->and($log->attack_type)->toBe('xss')
        ->and((string) json_encode($log->payload))->toContain('&lt;script&gt;');
});

it('rota fora da lista continua como antes: segue no observe, marcada', function () {
    $this->postJson('/webhooks/outro', signedWebhookBody(SIGNED_BODY_TEXT))->assertOk();

    expect(RequestLog::query()->sole()->attack_type)->toBe('xss');
});

it('ataque na query da rota assinada continua recusado no block, sem o corpo na evidência', function () {
    config()->set('security.validation.mode', 'block');

    $this->postJson('/webhooks/whatsapp?next=<script>alert(2)</script>', signedWebhookBody('mensagem do cliente com CPF 123'))
        ->assertStatus(422);

    $log = RequestLog::query()->sole();
    $gravado = (string) json_encode($log->payload);

    expect($log->status)->toBe(RequestLogStatus::Bloqueada)
        ->and($log->attack_type)->toBe('xss')
        ->and($gravado)->toContain('&lt;script&gt;alert(2)')
        ->and($gravado)->not->toContain('CPF')
        ->and($log->payload)->toHaveKey('_corpo_assinado');
});

it('ataque em cabeçalho inspecionado da rota assinada continua marcado no observe, sem o corpo', function () {
    $this->withHeaders(['User-Agent' => '<script>alert(3)</script>'])
        ->postJson('/webhooks/whatsapp', signedWebhookBody('mensagem do cliente com CPF 123'))
        ->assertOk();

    $log = RequestLog::query()->sole();
    $gravado = (string) json_encode($log->payload);

    expect($log->attack_type)->toBe('xss')
        ->and($gravado)->toContain('_cabecalhos')
        ->and($gravado)->not->toContain('CPF');
});

it('o teto de inspeção continua contando o corpo da rota assinada', function () {
    config()->set('security.validation.max_inspected_bytes', 200);

    $this->postJson('/webhooks/whatsapp', signedWebhookBody(str_repeat('a', 500)))->assertStatus(413);

    $log = RequestLog::query()->sole();

    expect($log->attack_type)->toBe('payload_too_large')
        ->and((string) json_encode($log->payload))->not->toContain(str_repeat('a', 50));
});

it('curinga largo na lista é ignorado: o corpo volta a ser inspecionado e recusado no block', function () {
    config()->set('security.validation.signed_body_paths', ['*']);
    config()->set('security.validation.mode', 'block');

    $this->postJson('/webhooks/whatsapp', signedWebhookBody(SIGNED_BODY_TEXT))->assertStatus(422);

    expect(RequestLog::query()->sole()->attack_type)->toBe('xss');
});
