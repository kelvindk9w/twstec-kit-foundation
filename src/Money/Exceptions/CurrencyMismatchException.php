<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money\Exceptions;

use InvalidArgumentException;

/**
 * Operação entre valores de moedas diferentes (somar BRL com USD, comparar,
 * escolher o maior...). Nunca há conversão implícita: converter é regra de
 * negócio (cotação, data, fonte), e fica fora do Money.
 */
final class CurrencyMismatchException extends InvalidArgumentException
{
    public static function between(string $expected, string $given, string $operation): self
    {
        return new self("Moedas diferentes em {$operation}: {$expected} e {$given}. Converta antes; o Money não converte moeda.");
    }
}
