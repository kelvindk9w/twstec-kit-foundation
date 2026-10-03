<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Twstec\Kit\Foundation\Idempotency\IdempotencyKey;
use Twstec\Kit\Foundation\Idempotency\RequestFingerprint;

// =============================================================================
// FORMA CANÔNICA DA REQUISIÇÃO (o hash que decide "mesma requisição" ou
// "outra requisição com a mesma chave") e FORMATO DA CHAVE.
//
// Direção segura: reordenar chaves ou mudar espaços não muda o hash; mudar um
// valor, um tipo, o caminho, a query ou um arquivo muda.
// =============================================================================

function fingerprintJson(string $body, string $uri = '/api/pedidos', string $method = 'POST'): string
{
    return RequestFingerprint::hash(Request::create($uri, $method, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body));
}

it('ignora a ordem das chaves e os espaços do JSON', function (): void {
    expect(fingerprintJson('{"cliente":"ana","itens":[{"sku":"A","qtd":2}],"total":10}'))
        ->toBe(fingerprintJson("{\n  \"total\": 10,\n  \"itens\": [ {\"qtd\": 2, \"sku\": \"A\"} ],\n  \"cliente\": \"ana\"\n}"));
});

it('distingue valores, tipos e a ordem das listas', function (string $other): void {
    expect(fingerprintJson('{"qtd":1,"itens":["a","b"],"obs":{}}'))->not->toBe(fingerprintJson($other));
})->with([
    'valor diferente' => '{"qtd":2,"itens":["a","b"],"obs":{}}',
    'inteiro x string' => '{"qtd":"1","itens":["a","b"],"obs":{}}',
    'inteiro x decimal' => '{"qtd":1.0,"itens":["a","b"],"obs":{}}',
    'ordem da lista' => '{"qtd":1,"itens":["b","a"],"obs":{}}',
    'objeto x lista vazia' => '{"qtd":1,"itens":["a","b"],"obs":[]}',
    'campo a mais' => '{"qtd":1,"itens":["a","b"],"obs":{},"extra":null}',
]);

it('escreve inteiro maior que 64 bits com os dígitos exatos, sem confundir com string nem arredondar', function (): void {
    $grande = fingerprintJson('{"n":123456789012345678901234567890}');

    expect($grande)->not->toBe(fingerprintJson('{"n":"123456789012345678901234567890"}'))
        ->and($grande)->not->toBe(fingerprintJson('{"n":123456789012345678901234567891}'))
        ->and(RequestFingerprint::canonical(Request::create('/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"n":123456789012345678901234567890}')))
        ->toContain('"n":123456789012345678901234567890');
});

it('inclui o método, o caminho real e a query', function (): void {
    $base = fingerprintJson('{"a":1}', '/api/pedidos/1/cancelar');

    expect($base)->not->toBe(fingerprintJson('{"a":1}', '/api/pedidos/2/cancelar'))
        ->and($base)->not->toBe(fingerprintJson('{"a":1}', '/api/pedidos/1/cancelar', 'PATCH'))
        ->and($base)->not->toBe(fingerprintJson('{"a":1}', '/api/pedidos/1/cancelar?motivo=x'))
        ->and(fingerprintJson('{"a":1}', '/api/p?b=2&a=1'))->toBe(fingerprintJson('{"a":1}', '/api/p?a=1&b=2'));
});

it('normaliza formulário pelos campos interpretados, com as chaves em ordem', function (): void {
    $form = fn (string $body): string => RequestFingerprint::hash(Request::create('/api/pedidos', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body));
    $parsed = function (array $fields): string {
        $request = Request::create('/api/pedidos', 'POST', $fields, [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        return RequestFingerprint::hash($request);
    };

    expect($parsed(['cliente' => 'ana', 'itens' => ['a', 'b']]))->toBe($parsed(['itens' => ['a', 'b'], 'cliente' => 'ana']))
        ->and($parsed(['cliente' => 'ana']))->not->toBe($parsed(['cliente' => 'bia']))
        // Formulário e JSON com os "mesmos" campos são requisições diferentes.
        ->and($parsed(['cliente' => 'ana']))->not->toBe(fingerprintJson('{"cliente":"ana"}'))
        ->and($form('a=1'))->toBe($form('a=1'));
});

it('leva em conta o CONTEÚDO dos arquivos enviados, não só o nome', function (): void {
    $comArquivo = function (string $conteudo): string {
        $arquivo = UploadedFile::fake()->createWithContent('nota.txt', $conteudo);
        $request = Request::create('/api/notas', 'POST', ['titulo' => 'n'], [], ['arquivo' => $arquivo], ['CONTENT_TYPE' => 'multipart/form-data']);

        return RequestFingerprint::hash($request);
    };

    expect($comArquivo('conteúdo A'))->toBe($comArquivo('conteúdo A'))
        ->and($comArquivo('conteúdo A'))->not->toBe($comArquivo('conteúdo B'));
});

it('usa os bytes para conteúdo que não é JSON nem formulário (e para JSON inválido)', function (): void {
    $raw = fn (string $type, string $body): string => RequestFingerprint::hash(Request::create('/api/x', 'POST', [], [], [], ['CONTENT_TYPE' => $type], $body));

    expect($raw('text/plain', 'abc'))->toBe($raw('text/plain', 'abc'))
        ->and($raw('text/plain', 'abc'))->not->toBe($raw('text/plain', 'abd'))
        ->and($raw('text/plain', 'abc'))->not->toBe($raw('text/csv', 'abc'))
        ->and($raw('application/json', '{"a":'))->not->toBe($raw('application/json', '{"a": '))
        ->and(RequestFingerprint::canonical(Request::create('/api/x', 'POST', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'segredo-do-corpo')))
        ->not->toContain('segredo-do-corpo');
});

it('aceita a chave no formato documentado e recusa o resto', function (string $raw, bool $valid): void {
    expect(IdempotencyKey::parse($raw) !== null)->toBe($valid);
})->with([
    'uuid' => ['3f1c2b9a-7d4e-4c1b-9a8f-2e6d5c4b3a21', true],
    'entre aspas (Structured Field)' => ['"3f1c2b9a-7d4e-4c1b-9a8f-2e6d5c4b3a21"', true],
    'base64url com pontuação aceita' => ['pedido_2026.10.02:abc~x+y/z=', true],
    'curta demais' => ['abc123', false],
    'com espaço' => ['pedido 2026 10 02 abc', false],
    'com aspas no meio' => ['pedido"2026"1002abc', false],
    'com acento' => ['pedido-número-0001-abc', false],
    'longa demais' => [str_repeat('a', 256), false],
]);

it('nunca guarda a chave: só o hash e uma impressão curta para o log', function (): void {
    $key = IdempotencyKey::parse('3f1c2b9a-7d4e-4c1b-9a8f-2e6d5c4b3a21');

    expect($key?->hash())->toBe(hash('sha256', '3f1c2b9a-7d4e-4c1b-9a8f-2e6d5c4b3a21'))
        ->and($key?->fingerprint())->toHaveLength(12)
        ->and(str_contains((string) $key?->fingerprint(), '3f1c2b9a'))->toBeFalse();
});

it('leva em conta os arquivos em QUALQUER tipo de corpo (não só no multipart)', function (): void {
    $comArquivo = function (string $conteudo): string {
        $arquivo = UploadedFile::fake()->createWithContent('nota.txt', $conteudo);
        $request = Request::create('/api/notas', 'POST', [], [], ['arquivo' => $arquivo], ['CONTENT_TYPE' => 'application/json'], '{"titulo":"n"}');

        return RequestFingerprint::hash($request);
    };

    expect($comArquivo('conteúdo A'))->toBe($comArquivo('conteúdo A'))
        ->and($comArquivo('conteúdo A'))->not->toBe($comArquivo('conteúdo B'));
});
