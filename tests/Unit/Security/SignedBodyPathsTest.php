<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Security\SignedBodyPaths;

// Lista de caminhos de corpo assinado (webhook recebido): só vale padrão que
// aponte para um endereço específico. Curinga largo é recusado — a lista não
// pode virar desvio genérico do filtro de ataques.

it('aceita caminho literal e prefixo literal terminado em /*', function (string $pattern) {
    expect(SignedBodyPaths::isAcceptable($pattern))->toBeTrue();
})->with([
    'literal' => ['webhooks/whatsapp'],
    'um segmento' => ['webhook'],
    'prefixo com /*' => ['webhooks/meta/*'],
    'barras nas pontas' => ['/webhooks/whatsapp/'],
]);

it('recusa curinga largo, parcial, no começo ou no meio', function (string $pattern) {
    expect(SignedBodyPaths::isAcceptable($pattern))->toBeFalse();
})->with([
    'curinga sozinho' => ['*'],
    'barra e curinga' => ['/*'],
    'curinga duplo' => ['*/*'],
    'curinga no começo' => ['*/whatsapp'],
    'curinga no meio' => ['webhooks/*/whatsapp'],
    'curinga parcial' => ['webhooks*'],
    'curinga parcial no fim' => ['webhooks/whats*'],
    'segmento vazio' => ['webhooks//whatsapp'],
    'vazio' => [''],
    'só barra' => ['/'],
]);

it('separa as entradas válidas das recusadas, como foram escritas', function () {
    config()->set('security.validation.signed_body_paths', ['/webhooks/whatsapp', '*', 'webhooks/meta/*', 'api*', '  ']);

    expect(SignedBodyPaths::patterns())->toBe(['webhooks/whatsapp', 'webhooks/meta/*'])
        ->and(SignedBodyPaths::rejected())->toBe(['*', 'api*']);
});

it('casa só os caminhos válidos da lista', function () {
    config()->set('security.validation.signed_body_paths', ['webhooks/whatsapp', 'webhooks/meta/*', '*']);

    $matches = fn (string $path): bool => SignedBodyPaths::matches(Request::create($path, 'POST'));

    expect($matches('/webhooks/whatsapp'))->toBeTrue()
        ->and($matches('/webhooks/meta/instagram'))->toBeTrue()
        // O `*` recusado não abre nada.
        ->and($matches('/webhooks/whatsapp/extra'))->toBeFalse()
        ->and($matches('/api/charges'))->toBeFalse()
        ->and($matches('/login'))->toBeFalse();
});

it('lista vazia ou ausente não casa nada', function (mixed $value) {
    config()->set('security.validation.signed_body_paths', $value);

    expect(SignedBodyPaths::matches(Request::create('/webhooks/whatsapp', 'POST')))->toBeFalse()
        ->and(SignedBodyPaths::rejected())->toBe([]);
})->with([
    'vazia' => [[]],
    'ausente' => [null],
    'texto em vez de lista' => ['webhooks/whatsapp'],
]);
