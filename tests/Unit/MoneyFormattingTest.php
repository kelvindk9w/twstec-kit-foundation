<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Money\Arithmetic;
use Twstec\Kit\Foundation\Money\AsMoney;
use Twstec\Kit\Foundation\Money\Money;

mutates(Money::class, Arithmetic::class, AsMoney::class);

// Formatação e leitura sem float: o resultado tem de ser IDÊNTICO ao do
// intl com float (o jeito antigo) em toda faixa em que o float ainda é exato,
// em vários locales e moedas — e continuar exato onde o float já erraria.

/**
 * O jeito antigo (float), só como referência de teste.
 */
function moneyFloatFormat(int $amount, string $currency, string $locale): string
{
    $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

    return (string) $formatter->formatCurrency($amount / 10 ** Money::fractionDigits($currency), $currency);
}

it('formata igual ao intl em vários locales e moedas (propriedade)', function (string $locale, string $currency) {
    mt_srand(crc32($locale.$currency));
    $values = [0, 1, -1, 5, -5, 50, -50, 99, -99, 100, -100, 123456, -123456, 100000000, -100000000];

    for ($i = 0; $i < 150; $i++) {
        $values[] = mt_rand(-10 ** 13, 10 ** 13);
    }

    foreach ($values as $amount) {
        expect(Money::format($amount, $currency, $locale))->toBe(moneyFloatFormat($amount, $currency, $locale), "{$amount} {$currency} {$locale}");
    }
})->with([
    ['pt_BR', 'BRL'],
    ['pt_BR', 'USD'],
    ['en_US', 'USD'],
    ['en_US', 'BRL'],
    ['de_DE', 'EUR'],
    ['fr_FR', 'EUR'],
    ['de_CH', 'CHF'],
    ['en_IN', 'INR'],
    ['ja_JP', 'JPY'],
    ['es_ES', 'EUR'],
    ['ar_BH', 'BHD'],
    ['ar_EG', 'EGP'],
    ['en_US', 'KWD'],
]);

it('formata exato além da faixa em que o float é exato', function () {
    expect(Money::format(PHP_INT_MAX, 'BRL', 'en_US'))->toBe('R$92,233,720,368,547,758.07')
        ->and(Money::format(PHP_INT_MIN, 'BRL', 'en_US'))->toBe('-R$92,233,720,368,547,758.08')
        ->and(Money::format(PHP_INT_MAX, 'JPY', 'en_US'))->toBe('¥9,223,372,036,854,775,807');
});

it('lê de volta o que formatou, sem float (propriedade)', function (string $locale, string $currency) {
    mt_srand(crc32('parse'.$locale.$currency));
    $values = [0, 1, -1, 50, -50, 123456, -123456, PHP_INT_MAX, PHP_INT_MIN];

    for ($i = 0; $i < 150; $i++) {
        $values[] = mt_rand(-10 ** 15, 10 ** 15);
    }

    foreach ($values as $amount) {
        expect(Money::parse(Money::format($amount, $currency, $locale), $currency, $locale))->toBe($amount, "{$amount} {$currency} {$locale}");
    }
})->with([
    ['pt_BR', 'BRL'],
    ['en_US', 'USD'],
    ['de_DE', 'EUR'],
    ['fr_FR', 'EUR'],
    ['de_CH', 'CHF'],
    ['ja_JP', 'JPY'],
    ['ar_BH', 'BHD'],
    ['ar_EG', 'EGP'],
]);

it('lê as entradas de sempre como antes', function () {
    expect(Money::parse('1.234,56'))->toBe(123456)
        ->and(Money::parse('R$ 1,49'))->toBe(149)
        ->and(Money::parse('R$ 1.234,56'))->toBe(123456)
        ->and(Money::parse('0,99'))->toBe(99)
        ->and(Money::parse('1234'))->toBe(123400)
        ->and(Money::parse(',5'))->toBe(50)
        ->and(Money::parse('10,'))->toBe(1000)
        ->and(Money::parse('-R$ 0,50'))->toBe(-50)
        ->and(Money::parse('−R$ 2,00'))->toBe(-200)
        ->and(Money::parse('(R$ 2,00)'))->toBe(-200)
        ->and(Money::parse('  (R$ 2,00)  '))->toBe(-200)
        ->and(Money::parse('R$ 2,00 (BRL)'))->toBe(200)
        ->and(Money::parse('(à vista) R$ 2,00'))->toBe(200)
        ->and(Money::parse('$1,234.56', 'USD', 'en_US'))->toBe(123456)
        ->and(Money::parse('¥1,235', 'JPY', 'en_US'))->toBe(1235);
});

it('arredonda casas a mais na leitura com metade para longe do zero', function () {
    expect(Money::parse('1,005'))->toBe(101)
        ->and(Money::parse('1,004'))->toBe(100)
        ->and(Money::parse('-1,005'))->toBe(-101)
        ->and(Money::parse('0,0049999'))->toBe(0);
});

it('recusa o que não é valor monetário', function (string $input) {
    Money::parse($input);
})->with(['abc', '', 'R$', '1,2,3', ' - '])->throws(InvalidArgumentException::class, 'Valor monetário inválido');
