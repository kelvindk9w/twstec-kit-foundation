<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money\Exceptions;

use OverflowException;

/**
 * O resultado não cabe no inteiro de 64 bits do PHP (a coluna bigint do
 * banco tem o mesmo limite). O Money recusa em vez de deixar o PHP virar o
 * número em float — que perderia centavos em silêncio.
 */
final class MoneyOverflowException extends OverflowException
{
    public static function result(string $operation, string $value): self
    {
        return new self("Valor monetário fora do limite de 64 bits em {$operation}: {$value}.");
    }
}
