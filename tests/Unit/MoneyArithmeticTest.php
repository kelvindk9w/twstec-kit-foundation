<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Money\Arithmetic;
use Twstec\Kit\Foundation\Money\AsMoney;
use Twstec\Kit\Foundation\Money\Exceptions\CurrencyMismatchException;
use Twstec\Kit\Foundation\Money\Exceptions\MoneyOverflowException;
use Twstec\Kit\Foundation\Money\Money;

mutates(Money::class, Arithmetic::class, AsMoney::class);

// Objeto de valor Money: inteiro em menor unidade + moeda, imutável, sem
// float. Soma, subtração e comparação exigem a mesma moeda; o que não cabe
// em 64 bits é recusado.

it('cria com a moeda da plataforma ou a informada, normalizada', function () {
    expect(Money::of(1990)->currency())->toBe('BRL')
        ->and(Money::of(1990)->amount())->toBe(1990)
        ->and(Money::of(5, 'usd')->currency())->toBe('USD')
        ->and(Money::zero('EUR')->isZero())->toBeTrue()
        ->and(Money::zero('EUR')->currency())->toBe('EUR');
});

it('recusa código de moeda que não é ISO 4217 de três letras', function (string $code) {
    Money::of(1, $code);
})->with(['R$', 'BR', 'BRLL', '', '12A'])->throws(InvalidArgumentException::class, 'Código de moeda inválido');

it('soma e subtrai na mesma moeda', function () {
    $a = Money::of(1050, 'BRL');
    $b = Money::of(-300, 'BRL');

    expect($a->plus($b)->amount())->toBe(750)
        ->and($a->minus($b)->amount())->toBe(1350)
        ->and($b->minus($a)->amount())->toBe(-1350)
        ->and($a->plus($b)->currency())->toBe('BRL');
});

it('operação entre moedas diferentes lança exceção', function (Closure $operation) {
    $operation(Money::of(100, 'BRL'), Money::of(100, 'USD'));
})->with([
    'plus' => [fn (Money $a, Money $b) => $a->plus($b)],
    'minus' => [fn (Money $a, Money $b) => $a->minus($b)],
    'compareTo' => [fn (Money $a, Money $b) => $a->compareTo($b)],
    'isGreaterThan' => [fn (Money $a, Money $b) => $a->isGreaterThan($b)],
    'isGreaterThanOrEqualTo' => [fn (Money $a, Money $b) => $a->isGreaterThanOrEqualTo($b)],
    'isLessThan' => [fn (Money $a, Money $b) => $a->isLessThan($b)],
    'isLessThanOrEqualTo' => [fn (Money $a, Money $b) => $a->isLessThanOrEqualTo($b)],
    'sum' => [fn (Money $a, Money $b) => Money::sum($a, $b)],
    'min' => [fn (Money $a, Money $b) => Money::min($a, $b)],
    'max' => [fn (Money $a, Money $b) => Money::max($a, $b)],
])->throws(CurrencyMismatchException::class, 'Moedas diferentes');

it('equals compara valor e moeda, sem exceção entre moedas', function () {
    expect(Money::of(100, 'BRL')->equals(Money::of(100, 'BRL')))->toBeTrue()
        ->and(Money::of(100, 'BRL')->equals(Money::of(101, 'BRL')))->toBeFalse()
        ->and(Money::of(100, 'BRL')->equals(Money::of(100, 'USD')))->toBeFalse()
        ->and(Money::of(100, 'BRL')->isSameCurrencyAs(Money::of(1, 'BRL')))->toBeTrue()
        ->and(Money::of(100, 'BRL')->isSameCurrencyAs(Money::of(1, 'USD')))->toBeFalse();
});

it('compara', function () {
    $small = Money::of(-5);
    $big = Money::of(7);

    expect($small->compareTo($big))->toBe(-1)
        ->and($big->compareTo($small))->toBe(1)
        ->and($big->compareTo(Money::of(7)))->toBe(0)
        ->and($big->isGreaterThan($small))->toBeTrue()
        ->and($big->isGreaterThan(Money::of(7)))->toBeFalse()
        ->and($big->isGreaterThanOrEqualTo(Money::of(7)))->toBeTrue()
        ->and($small->isGreaterThanOrEqualTo($big))->toBeFalse()
        ->and($small->isLessThan($big))->toBeTrue()
        ->and($small->isLessThan(Money::of(-5)))->toBeFalse()
        ->and($small->isLessThanOrEqualTo(Money::of(-5)))->toBeTrue()
        ->and($big->isLessThanOrEqualTo($small))->toBeFalse();
});

it('sinal, zero, negativo, valor absoluto e negação', function () {
    expect(Money::of(-3)->sign())->toBe(-1)
        ->and(Money::of(0)->sign())->toBe(0)
        ->and(Money::of(3)->sign())->toBe(1)
        ->and(Money::of(-3)->isNegative())->toBeTrue()
        ->and(Money::of(-1)->isNegative())->toBeTrue()
        ->and(Money::of(1)->isNegative())->toBeFalse()
        ->and(Money::of(1)->isPositive())->toBeTrue()
        ->and(Money::of(-1)->isPositive())->toBeFalse()
        ->and(Money::of(-1)->sign())->toBe(-1)
        ->and(Money::of(1)->sign())->toBe(1)
        ->and(Money::of(0)->isNegative())->toBeFalse()
        ->and(Money::of(3)->isPositive())->toBeTrue()
        ->and(Money::of(0)->isPositive())->toBeFalse()
        ->and(Money::of(0)->isZero())->toBeTrue()
        ->and(Money::of(1)->isZero())->toBeFalse()
        ->and(Money::of(-3)->absolute()->amount())->toBe(3)
        ->and(Money::of(3)->absolute()->amount())->toBe(3)
        ->and(Money::of(3)->negated()->amount())->toBe(-3)
        ->and(Money::of(-3)->negated()->amount())->toBe(3)
        ->and(Money::of(0)->negated()->amount())->toBe(0);
});

it('mínimo, máximo e soma de vários', function () {
    $values = [Money::of(30), Money::of(-10), Money::of(50), Money::of(50)];

    expect(Money::min(...$values)->amount())->toBe(-10)
        ->and(Money::max(...$values)->amount())->toBe(50)
        ->and(Money::sum(...$values)->amount())->toBe(120)
        ->and(Money::sum(Money::of(9))->amount())->toBe(9)
        ->and(Money::min(Money::of(2), Money::of(1))->amount())->toBe(1)
        ->and(Money::max(Money::of(1), Money::of(2))->amount())->toBe(2);
});

it('multiplica por inteiro, exato', function () {
    expect(Money::of(1999)->multipliedBy(3)->amount())->toBe(5997)
        ->and(Money::of(1999)->multipliedBy(-2)->amount())->toBe(-3998)
        ->and(Money::of(1999)->multipliedBy(0)->amount())->toBe(0);
});

it('é imutável: toda operação devolve outro objeto', function () {
    $original = Money::of(1000);

    $original->plus(Money::of(1));
    $original->minus(Money::of(1));
    $original->multipliedBy(3);
    $original->negated();
    $original->basisPoints(250, RoundingMode::HalfEven);
    $original->split(3);

    expect($original->amount())->toBe(1000)
        ->and($original->plus(Money::of(0)))->not->toBe($original)
        ->and((new ReflectionClass(Money::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionMethod(Money::class, '__construct'))->isPrivate())->toBeTrue();
});

it('recusa resultado fora dos 64 bits em vez de virar float', function (Closure $operation) {
    $operation();
})->with([
    'soma' => [fn () => Money::of(PHP_INT_MAX)->plus(Money::of(1))],
    'subtração' => [fn () => Money::of(PHP_INT_MIN)->minus(Money::of(1))],
    'negação do mínimo' => [fn () => Money::of(PHP_INT_MIN)->negated()],
    'absoluto do mínimo' => [fn () => Money::of(PHP_INT_MIN)->absolute()],
    'multiplicação' => [fn () => Money::of(PHP_INT_MAX)->multipliedBy(2)],
    'fator decimal' => [fn () => Money::of(PHP_INT_MAX)->multipliedByDecimal('1.5', RoundingMode::HalfEven)],
    'fração' => [fn () => Money::of(PHP_INT_MAX)->multipliedByFraction(3, 2, RoundingMode::HalfEven)],
    'percentual' => [fn () => Money::of(PHP_INT_MAX)->basisPoints(10001, RoundingMode::HalfEven)],
    'decimal' => [fn () => Money::ofDecimal('92233720368547758.08', RoundingMode::HalfEven, 'BRL')],
    'soma de vários' => [fn () => Money::sum(Money::of(PHP_INT_MAX), Money::of(PHP_INT_MAX))],
])->throws(MoneyOverflowException::class, 'fora do limite de 64 bits');

it('aceita os extremos que cabem, mesmo com passo intermediário maior que 64 bits', function () {
    // PHP_INT_MAX × 10000 estoura o int, mas o resultado final cabe.
    expect(Money::of(PHP_INT_MAX)->basisPoints(10000, RoundingMode::HalfEven)->amount())->toBe(PHP_INT_MAX)
        ->and(Money::of(PHP_INT_MAX)->multipliedByFraction(PHP_INT_MAX, PHP_INT_MAX, RoundingMode::TowardsZero)->amount())->toBe(PHP_INT_MAX)
        ->and(Money::of(PHP_INT_MIN)->plus(Money::of(0))->amount())->toBe(PHP_INT_MIN)
        ->and(Money::of(PHP_INT_MAX)->minus(Money::of(PHP_INT_MAX))->amount())->toBe(0)
        ->and(Money::of(PHP_INT_MIN + 1)->negated()->amount())->toBe(PHP_INT_MAX)
        ->and(Money::of(PHP_INT_MIN)->split(3))->toHaveCount(3)
        ->and(Money::ofDecimal('92233720368547758.07', RoundingMode::HalfEven, 'BRL')->amount())->toBe(PHP_INT_MAX)
        ->and(Money::ofDecimal('-92233720368547758.08', RoundingMode::HalfEven, 'BRL')->amount())->toBe(PHP_INT_MIN);
});

it('cria a partir de decimal exato em string, com a regra de arredondamento', function () {
    expect(Money::ofDecimal('19.90', RoundingMode::HalfEven, 'BRL')->amount())->toBe(1990)
        ->and(Money::ofDecimal('10', RoundingMode::HalfEven, 'BRL')->amount())->toBe(1000)
        ->and(Money::ofDecimal('-0.5', RoundingMode::HalfEven, 'BRL')->amount())->toBe(-50)
        ->and(Money::ofDecimal('0.005', RoundingMode::HalfEven, 'BRL')->amount())->toBe(0)
        ->and(Money::ofDecimal('0.005', RoundingMode::HalfAwayFromZero, 'BRL')->amount())->toBe(1)
        ->and(Money::ofDecimal('0.015', RoundingMode::HalfEven, 'BRL')->amount())->toBe(2)
        ->and(Money::ofDecimal('-0.005', RoundingMode::HalfAwayFromZero, 'BRL')->amount())->toBe(-1)
        ->and(Money::ofDecimal('1234.5', RoundingMode::HalfEven, 'JPY')->amount())->toBe(1234)
        ->and(Money::ofDecimal('1.2345', RoundingMode::HalfEven, 'BHD')->amount())->toBe(1234)
        ->and(Money::ofDecimal('0007.10', RoundingMode::HalfEven, 'BRL')->amount())->toBe(710);
});

it('recusa decimal que não é exato em string', function (string $decimal) {
    Money::ofDecimal($decimal, RoundingMode::HalfEven, 'BRL');
})->with(['1,50', '1.5e3', '.5', '5.', 'abc', '', ' 1', '--1', '1.2.3', 'INF'])->throws(InvalidArgumentException::class, 'decimal exato');

it('casas decimais da moeda vêm da config quando declaradas', function () {
    config(['platform.money.fraction_digits' => ['XPT' => 3, 'JPY' => 2]]);

    expect(Money::fractionDigits('XPT'))->toBe(3)
        ->and(Money::fractionDigits('xpt'))->toBe(3)
        ->and(Money::fractionDigits('JPY'))->toBe(2)
        ->and(Money::fractionDigits('BRL'))->toBe(2)
        ->and(Money::ofDecimal('1.5', RoundingMode::HalfEven, 'XPT')->amount())->toBe(1500)
        ->and(Money::of(1500, 'XPT')->formatted('en_US'))->toContain('1.500');
});

it('aceita casas decimais configuradas nos limites 0 e 18', function () {
    config(['platform.money.fraction_digits' => ['XAA' => 0, 'XBB' => 18]]);

    expect(Money::fractionDigits('XAA'))->toBe(0)
        ->and(Money::fractionDigits('XBB'))->toBe(18);
});

it('formata moeda com mais de três casas sem agrupar os decimais', function () {
    config(['platform.money.fraction_digits' => ['XPT' => 4]]);

    expect(Money::format(123456789, 'XPT', 'en_US'))->toEndWith('12,345.6789')
        ->and(Money::format(-5, 'XPT', 'en_US'))->toContain('0.0005')
        ->and(Money::format(-5, 'XPT', 'en_US'))->toStartWith('-');
});

it('recusa casas decimais configuradas fora de 0 a 18', function (mixed $digits) {
    config(['platform.money.fraction_digits' => ['XPT' => $digits]]);

    Money::fractionDigits('XPT');
})->with([-1, 19, '2', 2.0])->throws(InvalidArgumentException::class, 'inteiro de 0 a 18');

it('a regra configurada é um caso do RoundingMode', function () {
    expect(Money::defaultRounding())->toBe(RoundingMode::HalfAwayFromZero);

    foreach (RoundingMode::cases() as $mode) {
        config(['platform.money.rounding' => $mode->name]);

        expect(Money::defaultRounding())->toBe($mode);
    }

    // Sem valor (chave nula), o padrão do kit.
    config(['platform.money.rounding' => null]);
    expect(Money::defaultRounding())->toBe(RoundingMode::HalfAwayFromZero);
});

it('recusa regra configurada que não é caso do RoundingMode', function (mixed $value) {
    config(['platform.money.rounding' => $value]);

    Money::defaultRounding();
})->with(['half_up', 'halfeven', '', [['HalfEven']]])->throws(InvalidArgumentException::class, 'platform.money.rounding inválido');

it('a recusa da regra configurada diz o valor recebido e o que usar', function () {
    config(['platform.money.rounding' => 'half_up']);
    expect(fn () => Money::defaultRounding())->toThrow(InvalidArgumentException::class, 'platform.money.rounding inválido: [half_up]. Use um caso de RoundingMode (HalfAwayFromZero, HalfEven, TowardsZero, NegativeInfinity...).');

    config(['platform.money.rounding' => ['HalfEven']]);
    expect(fn () => Money::defaultRounding())->toThrow(InvalidArgumentException::class, 'platform.money.rounding inválido: [array]. Use um caso de RoundingMode');
});

it('exibe e serializa com o inteiro canônico + formatado', function () {
    $money = Money::of(123456, 'BRL');

    expect($money->formatted())->toContain('1.234,56')
        ->and($money->formatted('en_US'))->toBe('R$1,234.56')
        ->and($money->toArray())->toBe(['amount' => 123456, 'formatted' => Money::format(123456, 'BRL'), 'currency' => 'BRL'])
        ->and(json_encode($money))->toBe(json_encode(['amount' => 123456, 'formatted' => Money::format(123456, 'BRL'), 'currency' => 'BRL']));
});

it('soma é comutativa e associativa; subtrair desfaz somar (propriedade)', function () {
    mt_srand(20260930);

    for ($i = 0; $i < 500; $i++) {
        $a = Money::of(mt_rand(-10 ** 12, 10 ** 12));
        $b = Money::of(mt_rand(-10 ** 12, 10 ** 12));
        $c = Money::of(mt_rand(-10 ** 12, 10 ** 12));

        expect($a->plus($b)->equals($b->plus($a)))->toBeTrue()
            ->and($a->plus($b)->plus($c)->equals($a->plus($b->plus($c))))->toBeTrue()
            ->and($a->plus($b)->minus($b)->equals($a))->toBeTrue()
            ->and($a->multipliedBy(3)->equals($a->plus($a)->plus($a)))->toBeTrue()
            ->and($a->negated()->negated()->equals($a))->toBeTrue();
    }
});
