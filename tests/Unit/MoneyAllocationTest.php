<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Money\Arithmetic;
use Twstec\Kit\Foundation\Money\AsMoney;
use Twstec\Kit\Foundation\Money\Money;

mutates(Money::class, Arithmetic::class, AsMoney::class);

// Rateio: a soma das partes é SEMPRE o total, e o resto vai, um centavo por
// vez, para as partes com o maior resto da divisão (empate: a que vem antes).

/**
 * @param  list<Money>  $parts
 * @return list<int>
 */
function moneyAmounts(array $parts): array
{
    return array_map(fn (Money $part): int => $part->amount(), $parts);
}

it('divide em partes iguais, com os centavos que sobram nas primeiras', function () {
    expect(moneyAmounts(Money::of(1000)->split(3)))->toBe([334, 333, 333])
        ->and(moneyAmounts(Money::of(100)->split(3)))->toBe([34, 33, 33])
        ->and(moneyAmounts(Money::of(101)->split(3)))->toBe([34, 34, 33])
        ->and(moneyAmounts(Money::of(99)->split(3)))->toBe([33, 33, 33])
        ->and(moneyAmounts(Money::of(2)->split(5)))->toBe([1, 1, 0, 0, 0])
        ->and(moneyAmounts(Money::of(7)->split(1)))->toBe([7])
        ->and(moneyAmounts(Money::of(0)->split(4)))->toBe([0, 0, 0, 0]);
});

it('valor negativo: o rateio do absoluto, com o sinal em cada parte', function () {
    expect(moneyAmounts(Money::of(-1000)->split(3)))->toBe([-334, -333, -333])
        ->and(moneyAmounts(Money::of(-1)->split(2)))->toBe([-1, 0])
        ->and(moneyAmounts(Money::of(1)->split(2)))->toBe([1, 0])
        ->and(moneyAmounts(Money::of(-5)->allocate([1, 3])))->toBe([-1, -4]);
});

it('divide pelos pesos, com o centavo para o maior resto (empate: o primeiro)', function () {
    // 10 × 70/100 = 7 e 10 × 30/100 = 3: exato.
    expect(moneyAmounts(Money::of(10)->allocate([70, 30])))->toBe([7, 3])
        // 5 × 1/2 = 2,5 e 2,5: empate, o primeiro leva o centavo.
        ->and(moneyAmounts(Money::of(5)->allocate([1, 1])))->toBe([3, 2])
        // 100 × 1/6 = 16,67; × 2/6 = 33,33; × 3/6 = 50 → sobra 1, maior resto é o primeiro.
        ->and(moneyAmounts(Money::of(100)->allocate([1, 2, 3])))->toBe([17, 33, 50])
        // 100 × 3/6 = 50; × 2/6 = 33,33; × 1/6 = 16,67 → o maior resto é o último.
        ->and(moneyAmounts(Money::of(100)->allocate([3, 2, 1])))->toBe([50, 33, 17])
        // Peso zero nunca recebe centavo.
        ->and(moneyAmounts(Money::of(1)->allocate([0, 1, 0])))->toBe([0, 1, 0])
        ->and(moneyAmounts(Money::of(3)->allocate([0, 2, 0, 2])))->toBe([0, 2, 0, 1]);
});

it('as partes guardam a moeda do total', function () {
    foreach (Money::of(1000, 'USD')->split(3) as $part) {
        expect($part->currency())->toBe('USD');
    }
});

it('recusa pesos inválidos', function (array $weights, string $message) {
    expect(fn () => Money::of(100)->allocate($weights))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'lista vazia' => [[], 'lista de pesos'],
    'chaves não sequenciais' => [[1 => 1, 2 => 1], 'lista de pesos'],
    'todos zero' => [[0, 0], 'ao menos um peso maior que zero'],
    'negativo' => [[3, -1], 'maiores ou iguais a zero'],
    'negativo pequeno' => [[1, -1], 'maiores ou iguais a zero'],
    'não inteiro' => [[1, '2'], 'maiores ou iguais a zero'],
    'decimal' => [[1, 0.5], 'maiores ou iguais a zero'],
]);

it('recusa dividir em menos de uma parte', function (int $parts) {
    Money::of(100)->split($parts);
})->with([0, -1])->throws(InvalidArgumentException::class, 'ao menos uma parte');

it('funciona nos extremos de 64 bits', function () {
    $parts = Money::of(PHP_INT_MAX)->allocate([PHP_INT_MAX, PHP_INT_MAX, 1]);

    expect(bcadd(bcadd((string) $parts[0]->amount(), (string) $parts[1]->amount()), (string) $parts[2]->amount()))->toBe((string) PHP_INT_MAX)
        ->and(moneyAmounts(Money::of(PHP_INT_MIN)->split(2)))->toBe([intdiv(PHP_INT_MIN, 2), intdiv(PHP_INT_MIN, 2)]);
});

it('a soma do rateio é sempre o total; cada parte fica no piso ou teto da cota exata (propriedade)', function () {
    mt_srand(19700101);

    for ($i = 0; $i < 1000; $i++) {
        $amount = mt_rand(-10 ** 13, 10 ** 13);
        $weights = array_map(fn (): int => mt_rand(0, 3) === 0 ? 0 : mt_rand(1, 10 ** 6), range(1, mt_rand(1, 12)));

        if (array_sum($weights) === 0) {
            $weights[0] = 1;
        }

        $parts = Money::of($amount)->allocate($weights);
        $total = (string) array_sum($weights);
        $sum = '0';

        expect($parts)->toHaveCount(count($weights));

        foreach ($parts as $index => $part) {
            $sum = bcadd($sum, (string) $part->amount());
            $exact = bcdiv(bcmul((string) abs($amount), (string) $weights[$index]), $total, 0);
            $share = (string) abs($part->amount());

            expect(bccomp($share, $exact) >= 0 && bccomp($share, bcadd($exact, '1')) <= 0)->toBeTrue("parte {$index} fora da cota: {$amount} / ".json_encode($weights))
                ->and($part->sign() === 0 || $part->sign() === ($amount <=> 0))->toBeTrue('sinal trocado');

            if ($weights[$index] === 0) {
                expect($part->amount())->toBe(0);
            }
        }

        expect($sum)->toBe((string) $amount, "{$amount} / ".json_encode($weights));
    }
});

it('split: a soma é o total e as partes diferem no máximo em um centavo, maiores primeiro (propriedade)', function () {
    mt_srand(31337);

    for ($i = 0; $i < 1000; $i++) {
        $amount = mt_rand(-10 ** 13, 10 ** 13);
        $amounts = moneyAmounts(Money::of($amount)->split(mt_rand(1, 50)));
        $absolute = array_map(abs(...), $amounts);

        expect(array_sum($amounts))->toBe($amount)
            ->and(max($absolute) - min($absolute))->toBeLessThanOrEqual(1);

        $sorted = $absolute;
        rsort($sorted);
        expect($absolute)->toBe($sorted);
    }
});

it('o rateio é determinístico: mesma entrada, mesmas partes', function () {
    mt_srand(7);

    for ($i = 0; $i < 100; $i++) {
        $amount = mt_rand(-10 ** 9, 10 ** 9);
        $weights = array_map(fn (): int => mt_rand(0, 100), range(1, 7));
        $weights[3] = max(1, $weights[3]);

        expect(moneyAmounts(Money::of($amount)->allocate($weights)))
            ->toBe(moneyAmounts(Money::of($amount)->allocate($weights)));
    }
});
