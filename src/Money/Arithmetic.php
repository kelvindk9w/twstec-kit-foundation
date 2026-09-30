<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money;

use InvalidArgumentException;
use LogicException;
use RoundingMode;
use Twstec\Kit\Foundation\Money\Exceptions\MoneyOverflowException;

/**
 * Aritmética inteira exata do Money (uso interno).
 *
 * Os passos intermediários correm em bcmath, sobre inteiros em string e
 * sempre com escala 0 (definida AQUI, num lugar só — a escala global
 * `bcmath.scale` do php.ini não interfere): `valor × numerador` pode passar
 * de 64 bits mesmo quando o resultado final cabe, e o PHP transformaria esse
 * int estourado em float sem avisar. Só o resultado volta a ser int — e só se
 * couber (senão, exceção).
 *
 * Por que bcmath e não brick/math: `ext-bcmath` já é exigência do pacote, e
 * o brick/math só chega por dependência indireta do framework, numa faixa de
 * versões larga (0.x → 1.x) cuja API mudou no caminho. Nenhum float em nenhum
 * passo — uma trava de arquitetura do pacote confere o módulo inteiro.
 *
 * @internal
 */
final class Arithmetic
{
    private const int SCALE = 0;

    private const string INT_MAX = '9223372036854775807';

    private const string INT_MIN = '-9223372036854775808';

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, self::SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::SCALE);
    }

    /**
     * Quociente truncado em direção ao zero.
     */
    public static function quotient(string $a, string $b): string
    {
        return bcdiv($a, $b, self::SCALE);
    }

    /**
     * Resto com o sinal do dividendo.
     */
    public static function remainder(string $a, string $b): string
    {
        return bcmod($a, $b, self::SCALE);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    /**
     * Inteiro em string → int, recusando o que não cabe em 64 bits (e o que
     * não é inteiro — nunca acontece com a escala 0; se acontecer, é defeito).
     */
    public static function toInt(string $value, string $operation): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new LogicException("Resultado monetário não inteiro em {$operation}: {$value}.");
        }

        if (self::compare($value, self::INT_MAX) > 0 || self::compare($value, self::INT_MIN) < 0) {
            throw MoneyOverflowException::result($operation, $value);
        }

        return (int) $value;
    }

    /**
     * Divisão inteira exata de `numerator / denominator` com a regra de
     * arredondamento dada. Nenhum valor intermediário é aproximado: a decisão
     * sai do quociente truncado e do resto.
     */
    public static function divide(string $numerator, string $denominator, RoundingMode $rounding): string
    {
        if (self::compare($denominator, '0') === 0) {
            throw new InvalidArgumentException('Divisão de valor monetário por zero.');
        }

        $quotient = self::quotient($numerator, $denominator);
        $remainder = self::remainder($numerator, $denominator);

        // Resto zero = divisão exata, nada a arredondar.
        if (self::compare($remainder, '0') === 0) {
            return $quotient;
        }

        $negative = str_starts_with($numerator, '-') !== str_starts_with($denominator, '-');
        $awayFromZero = self::add($quotient, $negative ? '-1' : '1');

        // Posição do resto em relação à metade: 2·|resto| comparado a |divisor|.
        $half = self::compare(self::mul(self::abs($remainder), '2'), self::abs($denominator));
        $quotientIsEven = self::remainder($quotient, '2') === '0';

        return match ($rounding) {
            RoundingMode::TowardsZero => $quotient,
            RoundingMode::AwayFromZero => $awayFromZero,
            RoundingMode::NegativeInfinity => $negative ? $awayFromZero : $quotient,
            RoundingMode::PositiveInfinity => $negative ? $quotient : $awayFromZero,
            RoundingMode::HalfAwayFromZero => $half >= 0 ? $awayFromZero : $quotient,
            RoundingMode::HalfTowardsZero => $half > 0 ? $awayFromZero : $quotient,
            RoundingMode::HalfEven => $half > 0 || ($half === 0 && ! $quotientIsEven) ? $awayFromZero : $quotient,
            RoundingMode::HalfOdd => $half > 0 || ($half === 0 && $quotientIsEven) ? $awayFromZero : $quotient,
        };
    }

    /**
     * Número decimal exato em string ("2.49", "-0.5", "3") → [numerador,
     * denominador] inteiros (em string), sem passar por float.
     *
     * @return array{0: string, 1: string}
     */
    public static function decimalToFraction(string $decimal, string $what): array
    {
        if (preg_match('/^(-?\d+)(?:\.(\d+))?$/', $decimal, $match) !== 1) {
            throw new InvalidArgumentException("{$what} deve ser um número decimal exato em string, com ponto (ex.: \"2.49\"). Recebido: [{$decimal}]");
        }

        $fraction = $match[2] ?? '';

        return [$match[1].$fraction, '1'.str_repeat('0', strlen($fraction))];
    }

    public static function abs(string $value): string
    {
        return ltrim($value, '-');
    }
}
