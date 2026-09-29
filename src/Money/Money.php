<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money;

use NumberFormatter;

/**
 * Funções globais de conversão monetária (regra inegociável: dinheiro nunca é float).
 *
 * - Dinheiro é SEMPRE inteiro (menor unidade: centavos em BRL) no banco
 *   (bigint) e internamente. NUNCA float em nenhuma etapa.
 * - Decimal/formatado existe APENAS para exibição e retorno de API.
 * - A API retorna ambos os formatos (inteiro canônico + formatado); o inteiro
 *   é a fonte da verdade.
 * - Preparado para multi-moeda: toda função recebe a moeda; as casas decimais
 *   vêm do padrão ISO 4217 via intl (JPY = 0 casas, BRL/USD = 2...).
 */
final class Money
{
    /**
     * Converte inteiro (menor unidade) para string formatada na moeda/locale
     * da plataforma. Ex.: 123456 → "R$ 1.234,56".
     */
    public static function format(int $amountInMinorUnits, ?string $currency = null, ?string $locale = null): string
    {
        $currency = $currency ?? platform()->currency;
        $locale = $locale ?? platform()->locale;

        $fractionDigits = self::fractionDigits($currency);

        // intdiv + módulo: aritmética 100% inteira, sem float.
        $sign = $amountInMinorUnits < 0 ? -1 : 1;
        $absolute = abs($amountInMinorUnits);
        $divisor = 10 ** $fractionDigits;

        $numeric = $sign * ($absolute / $divisor);

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        $formatted = $formatter->formatCurrency($numeric, $currency);

        return $formatted !== false ? $formatted : (string) $amountInMinorUnits;
    }

    /**
     * Converte string formatada (ex.: "1.234,56" ou "R$ 1.234,56") para
     * inteiro em menor unidade (ex.: 123456). NUNCA passa por float na
     * persistência: o parse do intl retorna o valor e convertemos com
     * arredondamento half-up para a menor unidade.
     */
    public static function parse(string $formattedAmount, ?string $currency = null, ?string $locale = null): int
    {
        $currency = $currency ?? platform()->currency;
        $locale = $locale ?? platform()->locale;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        $parsed = $formatter->parseCurrency($formattedAmount, $parsedCurrency);

        if ($parsed === false) {
            // Fallback: remove símbolo de moeda/espaços (inclui o espaço
            // não-quebrável U+00A0 que o intl usa no formato pt-BR) e faz
            // parse do decimal puro.
            $numericOnly = preg_replace('/[^\d,\.\-]/u', '', $formattedAmount) ?? '';

            $decimalFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
            $parsed = $decimalFormatter->parse($numericOnly);
        }

        if ($parsed === false) {
            throw new \InvalidArgumentException("Valor monetário inválido: [{$formattedAmount}]");
        }

        $fractionDigits = self::fractionDigits($currency);
        $multiplier = 10 ** $fractionDigits;

        // bcmul evita erro de ponto flutuante na multiplicação; round half-up.
        $minorUnits = bcmul((string) $parsed, (string) $multiplier, $fractionDigits + 4);

        return (int) self::bcroundHalfUp($minorUnits);
    }

    /**
     * Retorna os dois formatos para resposta de API:
     * inteiro canônico + string formatada.
     *
     * @return array{amount: int, formatted: string, currency: string}
     */
    public static function toApiResponse(int $amountInMinorUnits, ?string $currency = null): array
    {
        $currency = $currency ?? platform()->currency;

        return [
            'amount' => $amountInMinorUnits,
            'formatted' => self::format($amountInMinorUnits, $currency),
            'currency' => $currency,
        ];
    }

    /**
     * Casas decimais da moeda conforme ISO 4217 (via intl).
     */
    public static function fractionDigits(string $currency): int
    {
        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    /**
     * Arredondamento half-up em string decimal (regra de arredondamento única
     * do domínio — nunca usar round() de float espalhado pelo código).
     */
    private static function bcroundHalfUp(string $numeric): string
    {
        $parts = explode('.', $numeric);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';

        if ($fraction === '') {
            return $integer;
        }

        $firstFractionDigit = (int) $fraction[0];

        if ($firstFractionDigit >= 5) {
            $integer = bcadd($integer, str_starts_with($numeric, '-') ? '-1' : '1');
        }

        return $integer;
    }
}
