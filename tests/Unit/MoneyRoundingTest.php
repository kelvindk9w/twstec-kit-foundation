<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Money\Arithmetic;
use Twstec\Kit\Foundation\Money\AsMoney;
use Twstec\Kit\Foundation\Money\Money;

mutates(Money::class, Arithmetic::class, AsMoney::class);

// Percentual, fator e divisão com a regra de arredondamento no parâmetro
// (RoundingMode nativo). A tabela abaixo é a referência escrita à mão; as
// propriedades comparam cada resultado com o bcround() do bcmath.

/*
 * Valor exato (em décimos de centavo) → resultado esperado por regra.
 * Colunas: HalfAwayFromZero, HalfTowardsZero, HalfEven, HalfOdd,
 *          TowardsZero, AwayFromZero, NegativeInfinity, PositiveInfinity.
 */
const MONEY_ROUNDING_TABLE = [
    //  décimos    HAFZ HTZ HE  HO  TZ  AFZ  -INF +INF
    25 => [3, 2, 2, 3, 2, 3, 2, 3],
    35 => [4, 3, 4, 3, 3, 4, 3, 4],
    5 => [1, 0, 0, 1, 0, 1, 0, 1],
    24 => [2, 2, 2, 2, 2, 3, 2, 3],
    26 => [3, 3, 3, 3, 2, 3, 2, 3],
    20 => [2, 2, 2, 2, 2, 2, 2, 2],
    0 => [0, 0, 0, 0, 0, 0, 0, 0],
    -5 => [-1, 0, 0, -1, 0, -1, -1, 0],
    -25 => [-3, -2, -2, -3, -2, -3, -3, -2],
    -35 => [-4, -3, -4, -3, -3, -4, -4, -3],
    -24 => [-2, -2, -2, -2, -2, -3, -3, -2],
    -26 => [-3, -3, -3, -3, -2, -3, -3, -2],
];

const MONEY_ROUNDING_COLUMNS = [
    RoundingMode::HalfAwayFromZero,
    RoundingMode::HalfTowardsZero,
    RoundingMode::HalfEven,
    RoundingMode::HalfOdd,
    RoundingMode::TowardsZero,
    RoundingMode::AwayFromZero,
    RoundingMode::NegativeInfinity,
    RoundingMode::PositiveInfinity,
];

/**
 * @return array<string, array{int, RoundingMode, int}>
 */
function moneyRoundingCases(): array
{
    $cases = [];

    foreach (MONEY_ROUNDING_TABLE as $tenths => $expected) {
        foreach (MONEY_ROUNDING_COLUMNS as $column => $mode) {
            $cases[sprintf('%s de %s', $mode->name, number_format($tenths / 10, 1))] = [$tenths, $mode, $expected[$column]];
        }
    }

    return $cases;
}

/**
 * Referência independente: bcround() do bcmath sobre a divisão com 40
 * casas (os denominadores dos testes cabem folgados nessa precisão).
 */
function moneyReference(string $numerator, string $denominator, RoundingMode $mode): int
{
    return (int) bcround(bcdiv($numerator, $denominator, 40), 0, $mode);
}

it('segue a tabela de cada regra nas fronteiras (,5 e negativos) — divisão', function (int $tenths, RoundingMode $mode, int $expected) {
    expect(Money::of($tenths)->dividedBy(10, $mode)->amount())->toBe($expected);
})->with(moneyRoundingCases());

it('segue a tabela de cada regra nas fronteiras — pontos-base, percentual, fator e fração', function (int $tenths, RoundingMode $mode, int $expected) {
    // 10% de N centavos = N décimos de centavo, por todos os caminhos.
    $base = Money::of($tenths);

    expect($base->basisPoints(1000, $mode)->amount())->toBe($expected)
        ->and($base->percentage('10', $mode)->amount())->toBe($expected)
        ->and($base->percentage(10, $mode)->amount())->toBe($expected)
        ->and($base->multipliedByDecimal('0.1', $mode)->amount())->toBe($expected)
        ->and($base->multipliedByFraction(1, 10, $mode)->amount())->toBe($expected)
        ->and(Money::ofDecimal(number_format($tenths / 1000, 3, '.', ''), $mode, 'BRL')->amount())->toBe($expected);
})->with(moneyRoundingCases());

it('half-up e half-even diferem exatamente no empate com quociente par', function () {
    // 2,5 → 3 (metade para longe do zero) e 2 (metade para o par).
    expect(Money::of(25)->dividedBy(10, RoundingMode::HalfAwayFromZero)->amount())->toBe(3)
        ->and(Money::of(25)->dividedBy(10, RoundingMode::HalfEven)->amount())->toBe(2)
        ->and(Money::of(-25)->dividedBy(10, RoundingMode::HalfAwayFromZero)->amount())->toBe(-3)
        ->and(Money::of(-25)->dividedBy(10, RoundingMode::HalfEven)->amount())->toBe(-2);
});

it('empate: par e ímpar conforme o último dígito do quociente (0 a 9, com sinal)', function (int $integer) {
    // integer + 0,5: HalfEven fica no par, HalfOdd no ímpar.
    $even = $integer % 2 === 0;
    $amount = Money::of($integer * 10 + ($integer < 0 ? -5 : 5));
    $away = $integer < 0 ? $integer - 1 : $integer + 1;

    expect($amount->dividedBy(10, RoundingMode::HalfEven)->amount())->toBe($even ? $integer : $away)
        ->and($amount->dividedBy(10, RoundingMode::HalfOdd)->amount())->toBe($even ? $away : $integer);
})->with([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, -1, -2, -3, -4, -5, -6, -7, -8, -9, -10]);

it('divisor negativo leva o sinal ao resultado com a mesma regra', function () {
    expect(Money::of(25)->dividedBy(-10, RoundingMode::NegativeInfinity)->amount())->toBe(-3)
        ->and(Money::of(25)->dividedBy(-10, RoundingMode::PositiveInfinity)->amount())->toBe(-2)
        ->and(Money::of(-25)->dividedBy(-10, RoundingMode::HalfEven)->amount())->toBe(2)
        ->and(Money::of(-35)->dividedBy(-10, RoundingMode::HalfOdd)->amount())->toBe(3)
        ->and(Money::of(10)->multipliedByFraction(-1, 4, RoundingMode::HalfEven)->amount())->toBe(-2)
        ->and(Money::of(10)->multipliedByFraction(1, -4, RoundingMode::HalfAwayFromZero)->amount())->toBe(-3);
});

it('percentual devolve a parcela: taxa de 3,99% + parcela fixa de R$ 0,39', function () {
    $sale = Money::of(10000, 'BRL'); // R$ 100,00
    $fee = $sale->basisPoints(399, RoundingMode::HalfEven)->plus(Money::of(39, 'BRL'));

    expect($fee->amount())->toBe(438)
        ->and($sale->percentage('3.99', RoundingMode::HalfEven)->amount())->toBe(399)
        ->and($sale->minus($fee)->amount())->toBe(9562)
        ->and(Money::of(1999)->percentage('2.5', RoundingMode::HalfEven)->amount())->toBe(50)
        ->and(Money::of(1999)->percentage('2.5', RoundingMode::TowardsZero)->amount())->toBe(49);
});

it('recusa divisão por zero', function (Closure $operation) {
    $operation();
})->with([
    'dividedBy' => [fn () => Money::of(1)->dividedBy(0, RoundingMode::HalfEven)],
    'fração' => [fn () => Money::of(1)->multipliedByFraction(1, 0, RoundingMode::HalfEven)],
])->throws(InvalidArgumentException::class, 'por zero');

it('recusa percentual e fator que não são decimais exatos em string', function (Closure $operation) {
    $operation();
})->with([
    'vírgula' => [fn () => Money::of(1)->percentage('3,99', RoundingMode::HalfEven)],
    'notação científica' => [fn () => Money::of(1)->multipliedByDecimal('1e2', RoundingMode::HalfEven)],
    'vazio' => [fn () => Money::of(1)->multipliedByDecimal('', RoundingMode::HalfEven)],
])->throws(InvalidArgumentException::class, 'decimal exato');

it('nenhum resultado difere da referência bcmath (propriedade, todas as regras)', function () {
    mt_srand(424242);

    for ($i = 0; $i < 400; $i++) {
        $amount = mt_rand(-10 ** 15, 10 ** 15);
        $bps = mt_rand(-20000, 20000);
        $numerator = mt_rand(-10 ** 6, 10 ** 6);
        $denominator = mt_rand(1, 10 ** 6) * (mt_rand(0, 1) === 1 ? 1 : -1);
        $factor = sprintf('%s%d.%04d', mt_rand(0, 1) === 1 ? '-' : '', mt_rand(0, 50), mt_rand(0, 9999));
        [$factorNumerator] = [str_replace('.', '', $factor)];
        $money = Money::of($amount);

        foreach (RoundingMode::cases() as $mode) {
            $context = "valor {$amount}, {$mode->name}";

            expect($money->basisPoints($bps, $mode)->amount())
                ->toBe(moneyReference(bcmul((string) $amount, (string) $bps), '10000', $mode), "{$context}, {$bps} bp")
                ->and($money->multipliedByFraction($numerator, $denominator, $mode)->amount())
                ->toBe(moneyReference(bcmul((string) $amount, (string) $numerator), (string) $denominator, $mode), "{$context}, {$numerator}/{$denominator}")
                ->and($money->dividedBy($denominator, $mode)->amount())
                ->toBe(moneyReference((string) $amount, (string) $denominator, $mode), "{$context}, / {$denominator}")
                ->and($money->multipliedByDecimal($factor, $mode)->amount())
                ->toBe(moneyReference(bcmul((string) $amount, $factorNumerator), '10000', $mode), "{$context}, × {$factor}");
        }
    }
});
