<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast de valores monetários (inteiro no banco, nunca float).
 *
 * - Banco: bigint com o valor na menor unidade da moeda (centavos em BRL).
 * - Model: sempre int (menor unidade). NUNCA float.
 *
 * Uso no model:
 *   protected function casts(): array
 *   {
 *       return ['valor' => MoneyAsCents::class];
 *   }
 *
 * Para formatar/exibir: Twstec\Kit\Foundation\Money\Money::format($model->valor).
 *
 * @implements CastsAttributes<int, int>
 */
final class MoneyAsCents implements CastsAttributes
{
    /**
     * Banco → model: sempre inteiro (menor unidade).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) $value;
    }

    /**
     * Model → banco: aceita apenas int (menor unidade) ou string numérica
     * inteira. Qualquer float/decimal é REJEITADO — converter antes via
     * Money::parse() ou montar o inteiro explicitamente.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, int>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(
                "Valor monetário float é proibido ({$key}). Use inteiro em menor unidade (centavos)."
            );
        }

        if (is_string($value) && ! preg_match('/^-?\d+$/', $value)) {
            throw new \InvalidArgumentException(
                "Valor monetário deve ser inteiro em menor unidade ({$key}). Recebido: [{$value}]"
            );
        }

        return [$key => (int) $value];
    }
}
