<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Cast Eloquent do objeto Money: grava o VALOR (bigint, menor unidade) e a
 * MOEDA (coluna de três letras) juntos.
 *
 * Uso no model:
 *   protected function casts(): array
 *   {
 *       return [
 *           'total' => AsMoney::class,                    // moeda na coluna `currency`
 *           'fee' => AsMoney::class.':fee_currency',      // moeda em outra coluna
 *       ];
 *   }
 *
 * - Leitura: `$model->total` é um Money (ou null). Valor sem moeda na linha é
 *   recusado — dinheiro sem moeda é ambíguo.
 * - Gravação: só Money (ou null). Inteiro solto é recusado aqui (a moeda
 *   ficaria implícita); para o inteiro puro, o cast de sempre: MoneyAsCents.
 * - Dois atributos com a MESMA coluna de moeda gravam a moeda de quem veio por
 *   último: use uma coluna por atributo quando as moedas puderem diferir.
 *
 * @implements CastsAttributes<Money, Money>
 */
final class AsMoney implements CastsAttributes
{
    public function __construct(private readonly string $currencyColumn = 'currency') {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currency = $attributes[$this->currencyColumn] ?? null;

        if (! is_string($currency) || $currency === '') {
            throw new LogicException("Valor monetário [{$key}] sem moeda na coluna [{$this->currencyColumn}].");
        }

        if (is_int($value)) {
            return Money::of($value, $currency);
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return Money::of(Arithmetic::toInt($value, "leitura de {$key}"), $currency);
        }

        throw new LogicException("Valor monetário [{$key}] no banco não é inteiro em menor unidade.");
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, int|string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                "O atributo [{$key}] recebe um Money (valor e moeda). Use Money::of(\$centavos, 'BRL'); para inteiro puro, o cast MoneyAsCents.",
            );
        }

        return [$key => $value->amount(), $this->currencyColumn => $value->currency()];
    }
}
