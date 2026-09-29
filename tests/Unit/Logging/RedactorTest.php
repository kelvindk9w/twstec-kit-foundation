<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Logging\Redactor;

// Redaction antes de persistir logs (LGPD).

it('mascara chaves sensíveis por nome exato, case-insensitive', function (string $key) {
    $redacted = (new Redactor)->redactArray([$key => 'valor-super-secreto']);

    expect($redacted[$key])->toBe(Redactor::MASK);
})->with([
    'password', 'PASSWORD', 'Password', 'password_confirmation', 'current_password',
    'transaction_password', 'senha', 'token', 'access_token', 'refresh_token',
    'api_key', 'api_secret', 'secret', 'client_secret', 'authorization',
    'private_key', 'webhook_secret', 'card_number', 'cvv',
]);

it('mascara chaves sensíveis por sufixo', function (string $key) {
    $redacted = (new Redactor)->redactArray([$key => 'segredo']);

    expect($redacted[$key])->toBe(Redactor::MASK);
})->with(['webhook_token', 'hmac_secret', 'smtp_password', 'parceiro_api_key']);

it('não mascara chaves comuns', function () {
    $redacted = (new Redactor)->redactArray(['nome' => 'Maria', 'public_code' => 'CLI-9F4K2Q', 'monkey' => 'valor']);

    expect($redacted['nome'])->toBe('Maria')
        ->and($redacted['public_code'])->toBe('CLI-9F4K2Q')
        ->and($redacted['monkey'])->toBe('valor');
});

it('mascara recursivamente em arrays aninhados', function () {
    $redacted = (new Redactor)->redactArray([
        'usuario' => ['nome' => 'Maria', 'credenciais' => ['password' => '123456', 'perfil' => 'admin']],
    ]);

    expect($redacted['usuario']['credenciais']['password'])->toBe(Redactor::MASK)
        ->and($redacted['usuario']['credenciais']['perfil'])->toBe('admin')
        ->and($redacted['usuario']['nome'])->toBe('Maria');
});

it('mascara CPF parcialmente mantendo 3 primeiros e 2 últimos dígitos', function (string $input, string $expected) {
    expect((new Redactor)->redactString($input))->toBe($expected);
})->with([
    'com pontuação' => ['CPF: 123.456.789-09', 'CPF: 123.***.***-09'],
    'sem pontuação' => ['cpf 12345678909 ok', 'cpf 123******09 ok'],
]);

it('mascara CNPJ parcialmente', function () {
    expect((new Redactor)->redactString('CNPJ 12.345.678/0001-90'))->toBe('CNPJ 12.3**.***/****-90')
        ->and((new Redactor)->redactString('cnpj 12345678000190'))->toBe('cnpj 123*********90');
});

it('mascara e-mail mantendo primeira letra e domínio', function () {
    expect((new Redactor)->redactString('avise kelvin.silva@empresa.com.br'))->toBe('avise k***@empresa.com.br');
});

it('não confunde valores monetários ou ids com documentos', function () {
    expect((new Redactor)->redactString('valor 1990 centavos, pedido 12345'))->toBe('valor 1990 centavos, pedido 12345');
});

it('trunca strings muito longas (logs não são storage de payload)', function () {
    $redacted = (new Redactor)->redactArray(['descricao' => str_repeat('a', 5000)]);

    expect(mb_strlen($redacted['descricao']))->toBeLessThan(1100)
        ->and($redacted['descricao'])->toEndWith('…[truncado]');
});

// Número de cartão (PAN) em texto livre — PCI DSS: só os 4 últimos dígitos
// podem sobreviver. Cartões de teste públicos (Visa/Mastercard/Amex).

it('mascara número de cartão em texto livre mantendo só os 4 últimos dígitos', function (string $input, string $expected) {
    expect((new Redactor)->redactString($input))->toBe($expected);
})->with([
    'visa corrido' => ['cartão 4111111111111111', 'cartão ************1111'],
    'visa com espaços' => ['meu cartão é 4111 1111 1111 1111', 'meu cartão é **** **** **** 1111'],
    'mastercard com hífens' => ['5555-5555-5555-4444 ok', '****-****-****-4444 ok'],
    'amex 15 dígitos' => ['amex 3782 822463 10005', 'amex **** ****** *0005'],
    'grudado em letras' => ['cc:4111111111111111.', 'cc:************1111.'],
    'cartão seguido de outro número' => ['4111 1111 1111 1111 123', '**** **** **** 1111 123'],
    'dois cartões' => ['4111111111111111 e 5555555555554444', '************1111 e ************4444'],
]);

it('não mascara números que não são cartão', function (string $input) {
    expect((new Redactor)->redactString($input))->toBe($input);
})->with([
    'telefone com DDD' => ['ligue (11) 98765-4321'],
    'telefone internacional (13 dígitos, falha no Luhn)' => ['+55 11 98765-4321'],
    '16 dígitos que falham no Luhn' => ['pedido 4111111111111112'],
    'protocolo de 16 dígitos' => ['protocolo 1234567890123456'],
    'número curto' => ['pedido 123456789012'],
    'valor e data' => ['R$ 1.990,00 em 23/09/2026'],
]);

it('CPF e CNPJ seguem com a máscara de documento, não a de cartão', function () {
    expect((new Redactor)->redactString('CPF 123.456.789-09 cartão 4111 1111 1111 1111'))
        ->toBe('CPF 123.***.***-09 cartão **** **** **** 1111')
        ->and((new Redactor)->redactString('cnpj 12345678000190'))->toBe('cnpj 123*********90');
});

it('mascara cartão em valores aninhados e em nomes de campo', function () {
    $redacted = (new Redactor)->redactArray([
        'message' => 'meu cartão é 4111 1111 1111 1111',
        'itens' => [['obs' => 'pague com 5555555555554444']],
        '4111111111111111' => 'chave maliciosa',
    ]);

    expect($redacted['message'])->toBe('meu cartão é **** **** **** 1111')
        ->and($redacted['itens'][0]['obs'])->toBe('pague com ************4444')
        ->and($redacted)->toHaveKey('************1111')
        ->and(json_encode($redacted))->not->toContain('4111111111111111');
});
