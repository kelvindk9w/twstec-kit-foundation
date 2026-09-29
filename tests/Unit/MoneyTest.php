<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Money\Money;

// Testes das funções globais monetárias: inteiro canônico,
// formatado só na borda, NUNCA float.

it('formata inteiro de centavos para BRL', function () {
    expect(Money::format(123456))->toContain('1.234,56')
        ->and(Money::format(0))->toContain('0,00')
        ->and(Money::format(149))->toContain('1,49');
});

it('converte string formatada para inteiro de centavos sem float', function () {
    expect(Money::parse('1.234,56'))->toBe(123456)
        ->and(Money::parse('R$ 1,49'))->toBe(149)
        ->and(Money::parse('0,99'))->toBe(99);
});

it('retorno de API traz inteiro canônico + formatado', function () {
    $response = Money::toApiResponse(123456);

    expect($response['amount'])->toBe(123456)
        ->and($response['currency'])->toBe('BRL')
        ->and($response['formatted'])->toContain('1.234,56');
});

it('respeita casas decimais da moeda (JPY não tem centavos)', function () {
    expect(Money::fractionDigits('JPY'))->toBe(0)
        ->and(Money::fractionDigits('BRL'))->toBe(2);
});
