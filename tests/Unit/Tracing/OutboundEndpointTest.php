<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Uri;
use Twstec\Kit\Foundation\Logging\Redactor;
use Twstec\Kit\Foundation\Tracing\Http\OutboundEndpoint;

// =============================================================================
// O DESTINO QUE VAI PARA A TRILHA: host + rota normalizada + nomes da query.
// Nenhum valor que pareça identificador, token ou dado pessoal sobrevive.
// =============================================================================

function endpoint(): OutboundEndpoint
{
    return new OutboundEndpoint(new Redactor);
}

it('normaliza os segmentos que parecem valor e mantém os nomes de rota', function (string $url, string $path): void {
    expect(endpoint()->path(new Uri($url)))->toBe($path);
})->with([
    'número' => ['https://api.test/v1/charges/123/refund', '/v1/charges/{n}/refund'],
    'uuid' => ['https://api.test/v1/orders/0192c3a4-5b6c-7d8e-9f01-23456789abcd', '/v1/orders/{uuid}'],
    'cpf pontuado' => ['https://api.test/clientes/123.456.789-09', '/clientes/{n}'],
    'cnpj' => ['https://api.test/empresas/12.345.678%2F0001-90/notas', '/empresas/{n}/notas'],
    'cartão' => ['https://api.test/cards/4111111111111111', '/cards/{n}'],
    'e-mail' => ['https://api.test/users/maria%40example.com/keys', '/users/{email}/keys'],
    'token com dígitos' => ['https://api.test/webhooks/whsec_AbCdEf1GhIjK2L', '/webhooks/{token}'],
    'token só de letras longo' => ['https://api.test/k/AbCdEfGhIjKlMnOpQrStUvWxYz', '/k/{token}'],
    'nomes longos de rota ficam' => ['https://api.test/v1/payment-method-configurations', '/v1/payment-method-configurations'],
    'raiz' => ['https://api.test', '/'],
    'barras repetidas' => ['https://api.test//v1///status', '/v1/status'],
]);

it('guarda só os NOMES dos parâmetros da query, sem repetição', function (): void {
    expect(endpoint()->queryKeys(new Uri('https://api.test/x?access_token=abc&page=2&page=3&sig=zzz&filter%5Bcpf%5D=123.456.789-09')))
        ->toBe(['access_token', 'page', 'sig', 'filter[cpf]']);

    expect(endpoint()->queryKeys(new Uri('https://api.test/x')))->toBe([]);
});

it('host em minúsculas, com porta só quando não é a padrão, e sem userinfo', function (): void {
    expect(OutboundEndpoint::host(new Uri('https://user:senha@API.Example.TEST/x')))->toBe('api.example.test')
        ->and(OutboundEndpoint::host(new Uri('https://api.test:443/x')))->toBe('api.test')
        ->and(OutboundEndpoint::host(new Uri('http://api.test:8080/x')))->toBe('api.test:8080');
});
