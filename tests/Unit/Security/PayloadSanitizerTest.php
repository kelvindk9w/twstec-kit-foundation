<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Security\PayloadSanitizer;

// Sanitização de payload para persistência: nunca executável.

it('escapa HTML tornando o payload não executável', function () {
    $sanitized = (new PayloadSanitizer)->sanitize(['comment' => '<script>alert(1)</script>']);

    expect($sanitized['comment'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($sanitized['comment'])->not->toContain('<script>');
});

it('remove null bytes', function () {
    $sanitized = (new PayloadSanitizer)->sanitize(['arquivo' => "shell\0.php"]);

    expect($sanitized['arquivo'])->toBe('shell.php');
});

it('sanitiza recursivamente chaves e valores', function () {
    $sanitized = (new PayloadSanitizer)->sanitize([
        'nivel1' => ['nivel2' => ['<img src=x onerror=alert(1)>' => 'valor']],
    ]);

    $chave = array_key_first($sanitized['nivel1']['nivel2']);

    expect($chave)->not->toContain('<img')
        ->and($chave)->toContain('&lt;img');
});

it('trunca strings gigantes', function () {
    $sanitized = (new PayloadSanitizer)->sanitize(['dump' => str_repeat('x', 10_000)]);

    expect(mb_strlen($sanitized['dump']))->toBe(2000);
});

it('preserva escalares não-string e marca objetos', function () {
    $sanitized = (new PayloadSanitizer)->sanitize([
        'valor' => 1990,
        'ativo' => true,
        'nulo' => null,
        'objeto' => new stdClass,
    ]);

    expect($sanitized['valor'])->toBe(1990)
        ->and($sanitized['ativo'])->toBeTrue()
        ->and($sanitized['nulo'])->toBeNull()
        ->and($sanitized['objeto'])->toBe('[stdClass]');
});
