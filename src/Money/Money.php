<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Money;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use NumberFormatter;
use RoundingMode;
use Twstec\Kit\Foundation\Money\Exceptions\CurrencyMismatchException;

/**
 * Dinheiro (regra inegociável: dinheiro nunca é float).
 *
 * - Dinheiro é SEMPRE inteiro na menor unidade da moeda (centavos em BRL), no
 *   banco (bigint) e em todo cálculo. NUNCA float, em nenhum passo — uma
 *   trava de arquitetura do pacote reprova float no módulo.
 * - Decimal/formatado existe APENAS para exibição e retorno de API.
 * - Multi-moeda: todo valor carrega a moeda; as casas decimais vêm do ISO 4217
 *   (intl: JPY = 0, BRL/USD = 2, BHD = 3) ou de `platform.money.fraction_digits`.
 *
 * Dois jeitos de usar, lado a lado:
 *
 * 1. **Funções estáticas** (as de sempre) sobre o inteiro: `Money::format()`,
 *    `Money::parse()`, `Money::toApiResponse()`, `Money::fractionDigits()`.
 * 2. **Objeto de valor imutável** para CALCULAR: `Money::of(1990, 'BRL')`.
 *    Soma, subtração e comparação exigem a mesma moeda
 *    (CurrencyMismatchException); tudo o que pode gerar fração de centavo
 *    (percentual, fator decimal, divisão) exige a regra de arredondamento no
 *    parâmetro — `RoundingMode` nativo do PHP, sem padrão escondido
 *    (`Money::defaultRounding()` lê a regra configurada, quando o projeto
 *    quer uma só); o rateio (`allocate`/`split`) não arredonda: distribui o
 *    resto e a soma das partes é sempre o total. Resultado fora dos 64 bits
 *    → MoneyOverflowException, nunca float.
 *
 * Exemplos e a tabela das regras de arredondamento: docs/convencoes.md.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(
        private int $minorUnits,
        private string $currency,
    ) {}

    // -------------------------------------------------------------------------
    // Criação
    // -------------------------------------------------------------------------

    /**
     * Valor em menor unidade (centavos em BRL): `Money::of(1990)` = R$ 19,90.
     * Sem moeda, a da plataforma (`platform.currency`).
     */
    public static function of(int $minorUnits, ?string $currency = null): self
    {
        return new self($minorUnits, self::currencyCode($currency));
    }

    public static function zero(?string $currency = null): self
    {
        return self::of(0, $currency);
    }

    /**
     * Valor a partir de um decimal EXATO em string, com ponto ("19.90",
     * "-0.5", "10") — entrada de API ou de planilha. Mais casas que as da
     * moeda são arredondadas pela regra dada; float não é aceito.
     */
    public static function ofDecimal(string $amount, RoundingMode $rounding, ?string $currency = null): self
    {
        $currency = self::currencyCode($currency);
        [$numerator, $denominator] = Arithmetic::decimalToFraction($amount, 'O valor');
        $scaled = Arithmetic::mul($numerator, (string) (10 ** self::fractionDigits($currency)));

        return new self(Arithmetic::toInt(Arithmetic::divide($scaled, $denominator, $rounding), 'ofDecimal'), $currency);
    }

    // -------------------------------------------------------------------------
    // Leitura
    // -------------------------------------------------------------------------

    /**
     * O inteiro canônico, em menor unidade (o que vai para o banco).
     */
    public function amount(): int
    {
        return $this->minorUnits;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    // -------------------------------------------------------------------------
    // Aritmética (sempre um objeto novo; o original não muda)
    // -------------------------------------------------------------------------

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other, 'plus');

        return $this->with(Arithmetic::add((string) $this->minorUnits, (string) $other->minorUnits), 'plus');
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other, 'minus');

        return $this->with(Arithmetic::sub((string) $this->minorUnits, (string) $other->minorUnits), 'minus');
    }

    public function negated(): self
    {
        return $this->with(Arithmetic::sub('0', (string) $this->minorUnits), 'negated');
    }

    public function absolute(): self
    {
        return $this->with(Arithmetic::abs((string) $this->minorUnits), 'absolute');
    }

    /**
     * Multiplicação por inteiro (quantidade de itens, parcelas...): exata.
     */
    public function multipliedBy(int $factor): self
    {
        return $this->with(Arithmetic::mul((string) $this->minorUnits, (string) $factor), 'multipliedBy');
    }

    /**
     * Multiplicação por fator decimal EXATO em string ("1.0375", "0.5").
     */
    public function multipliedByDecimal(string $factor, RoundingMode $rounding): self
    {
        [$numerator, $denominator] = Arithmetic::decimalToFraction($factor, 'O fator');

        return $this->ratio($numerator, $denominator, $rounding, 'multipliedByDecimal');
    }

    /**
     * Multiplicação por fração `numerator / denominator` (ex.: 1/3 de um
     * valor, 7 dias de 30), com um único arredondamento no fim.
     */
    public function multipliedByFraction(int $numerator, int $denominator, RoundingMode $rounding): self
    {
        return $this->ratio((string) $numerator, (string) $denominator, $rounding, 'multipliedByFraction');
    }

    public function dividedBy(int $divisor, RoundingMode $rounding): self
    {
        return $this->ratio('1', (string) $divisor, $rounding, 'dividedBy');
    }

    /**
     * Percentual em pontos-base: 1 bp = 0,01% (399 = 3,99%; 10000 = 100%).
     * Devolve a PARCELA (o valor da taxa), não o valor com a taxa somada.
     */
    public function basisPoints(int $basisPoints, RoundingMode $rounding): self
    {
        return $this->ratio((string) $basisPoints, '10000', $rounding, 'basisPoints');
    }

    /**
     * Percentual como decimal EXATO em string ("3.99" = 3,99%) ou inteiro
     * (5 = 5%). Devolve a PARCELA, como basisPoints().
     */
    public function percentage(string|int $percent, RoundingMode $rounding): self
    {
        [$numerator, $denominator] = Arithmetic::decimalToFraction((string) $percent, 'O percentual');

        return $this->ratio($numerator, Arithmetic::mul($denominator, '100'), $rounding, 'percentage');
    }

    // -------------------------------------------------------------------------
    // Rateio (a soma das partes é SEMPRE o total — nenhum centavo some)
    // -------------------------------------------------------------------------

    /**
     * Divide o valor na proporção dos pesos inteiros (>= 0, ao menos um > 0).
     *
     * Cada parte recebe o piso de `|valor| × peso / soma dos pesos`; os
     * centavos que sobram (menos que a quantidade de partes) vão, um a um,
     * para as partes com o MAIOR resto da divisão — empate, a que vem antes na
     * lista. Peso zero nunca recebe centavo. Valor negativo: o rateio do valor
     * absoluto, com o sinal em cada parte (simétrico).
     *
     * `Money::of(100)->allocate([1, 1, 1])` → [34, 33, 33].
     *
     * @param  list<int>  $weights
     * @return list<self>
     */
    public function allocate(array $weights): array
    {
        if ($weights === [] || ! array_is_list($weights)) {
            throw new InvalidArgumentException('O rateio precisa de uma lista de pesos.');
        }

        $total = '0';

        foreach ($weights as $weight) {
            if (! is_int($weight) || $weight < 0) {
                throw new InvalidArgumentException('Os pesos do rateio são inteiros maiores ou iguais a zero.');
            }

            $total = Arithmetic::add($total, (string) $weight);
        }

        if ($total === '0') {
            throw new InvalidArgumentException('O rateio precisa de ao menos um peso maior que zero.');
        }

        $absolute = Arithmetic::abs((string) $this->minorUnits);
        $shares = [];
        $remainders = [];
        $distributed = '0';

        foreach ($weights as $index => $weight) {
            $product = Arithmetic::mul($absolute, (string) $weight);
            $shares[$index] = Arithmetic::quotient($product, $total);
            $remainders[$index] = Arithmetic::remainder($product, $total);
            $distributed = Arithmetic::add($distributed, $shares[$index]);
        }

        // Maior resto primeiro; empate, a posição menor. Determinístico.
        $order = array_keys($weights);
        usort($order, fn (int $a, int $b): int => Arithmetic::compare($remainders[$b], $remainders[$a]) ?: $a <=> $b);

        $leftover = Arithmetic::toInt(Arithmetic::sub($absolute, $distributed), 'allocate');

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i]] = Arithmetic::add($shares[$order[$i]], '1');
        }

        $negative = $this->minorUnits < 0;

        return array_map(
            fn (string $share): self => $this->with($negative ? Arithmetic::sub('0', $share) : $share, 'allocate'),
            $shares,
        );
    }

    /**
     * Divide em `$parts` partes iguais; os centavos que sobram vão para as
     * PRIMEIRAS partes. `Money::of(1000)->split(3)` → [334, 333, 333].
     *
     * @return list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('A divisão precisa de ao menos uma parte.');
        }

        return $this->allocate(array_fill(0, $parts, 1));
    }

    // -------------------------------------------------------------------------
    // Comparação e sinal
    // -------------------------------------------------------------------------

    /**
     * -1, 0 ou 1. Moedas diferentes → CurrencyMismatchException.
     */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other, 'compareTo');

        return $this->minorUnits <=> $other->minorUnits;
    }

    /**
     * Mesmo valor E mesma moeda (moedas diferentes: false, sem exceção).
     */
    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->minorUnits === $other->minorUnits;
    }

    public function isSameCurrencyAs(self $other): bool
    {
        return $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->minorUnits === 0;
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    /**
     * -1, 0 ou 1.
     */
    public function sign(): int
    {
        return $this->minorUnits <=> 0;
    }

    public static function sum(self $first, self ...$others): self
    {
        return array_reduce($others, fn (self $carry, self $item): self => $carry->plus($item), $first);
    }

    public static function min(self $first, self ...$others): self
    {
        return array_reduce($others, fn (self $carry, self $item): self => $item->isLessThan($carry) ? $item : $carry, $first);
    }

    public static function max(self $first, self ...$others): self
    {
        return array_reduce($others, fn (self $carry, self $item): self => $item->isGreaterThan($carry) ? $item : $carry, $first);
    }

    // -------------------------------------------------------------------------
    // Exibição (a borda: nada daqui volta para cálculo)
    // -------------------------------------------------------------------------

    public function formatted(?string $locale = null): string
    {
        return self::format($this->minorUnits, $this->currency, $locale);
    }

    /**
     * @return array{amount: int, formatted: string, currency: string}
     */
    public function toArray(): array
    {
        return self::toApiResponse($this->minorUnits, $this->currency);
    }

    /**
     * @return array{amount: int, formatted: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // -------------------------------------------------------------------------
    // Funções estáticas sobre o inteiro (API de sempre)
    // -------------------------------------------------------------------------

    /**
     * Converte inteiro (menor unidade) para string formatada na moeda/locale
     * da plataforma. Ex.: 123456 → "R$ 1.234,56".
     *
     * Sem float: o intl formata só a parte inteira (int) no padrão monetário
     * do locale, e os centavos entram depois do último dígito, com o
     * separador decimal monetário e os dígitos do próprio locale.
     */
    public static function format(int $amountInMinorUnits, ?string $currency = null, ?string $locale = null): string
    {
        $currency = self::currencyCode($currency);
        $locale = $locale ?? platform()->locale;
        $fractionDigits = self::fractionDigits($currency);
        $divisor = 10 ** $fractionDigits;

        $absolute = Arithmetic::abs((string) $amountInMinorUnits);
        $integerPart = Arithmetic::quotient($absolute, (string) $divisor);
        $fractionPart = Arithmetic::remainder($absolute, (string) $divisor);
        $negative = $amountInMinorUnits < 0;

        $currencyFormatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $currencyFormatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);
        $currencyFormatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

        $digitFormatter = new NumberFormatter($locale, NumberFormatter::DECIMAL);
        $digitFormatter->setAttribute(NumberFormatter::GROUPING_USED, 0);

        // -0 não existe em int: o negativo menor que uma unidade (-R$ 0,50)
        // formata -1 e troca o dígito pelo zero do locale.
        $formatted = $negative && $integerPart === '0'
            ? self::replaceLastDigit((string) $currencyFormatter->format(-1), (string) $digitFormatter->format(0))
            : (string) $currencyFormatter->format((int) ($negative ? '-'.$integerPart : $integerPart));

        if ($fractionDigits === 0) {
            return $formatted;
        }

        $digitFormatter->setAttribute(NumberFormatter::MIN_INTEGER_DIGITS, $fractionDigits);
        $fraction = $currencyFormatter->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL)
            .$digitFormatter->format((int) $fractionPart);

        return self::insertAfterLastDigit($formatted, $fraction);
    }

    /**
     * Converte string formatada (ex.: "1.234,56" ou "R$ 1.234,56") para
     * inteiro em menor unidade (ex.: 123456), sem float: os dígitos são lidos
     * como texto, com o separador decimal monetário do locale, e o excesso de
     * casas é arredondado half-up (metade para longe do zero).
     */
    public static function parse(string $formattedAmount, ?string $currency = null, ?string $locale = null): int
    {
        $currency = self::currencyCode($currency);
        $locale = $locale ?? platform()->locale;

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $separator = $formatter->getSymbol(NumberFormatter::MONETARY_SEPARATOR_SYMBOL);

        // Sinal: hífen, o sinal de menos tipográfico (U+2212) ou parênteses
        // de contabilidade. Símbolo da moeda, espaços (inclusive o U+00A0 do
        // pt-BR) e separadores de milhar caem fora.
        // Dígitos de outros sistemas (árabe-índicos, devanágari...) viram ASCII.
        $trimmed = (string) preg_replace_callback('/\p{Nd}/u', fn (array $digit): string => (string) \IntlChar::charDigitValue($digit[0]), trim($formattedAmount));
        $negative = preg_match('/[-\x{2212}]/u', $trimmed) === 1
            || (str_starts_with($trimmed, '(') && str_ends_with($trimmed, ')'));
        $numeric = (string) preg_replace('/[^0-9'.preg_quote($separator, '/').']/u', '', $trimmed);
        $parts = explode($separator, $numeric);

        if (count($parts) > 2 || preg_match('/^\d+$/', $parts[0].($parts[1] ?? '')) !== 1) {
            throw new InvalidArgumentException("Valor monetário inválido: [{$formattedAmount}]");
        }

        $decimal = ($negative ? '-' : '').($parts[0] === '' ? '0' : $parts[0]).(isset($parts[1]) && $parts[1] !== '' ? '.'.$parts[1] : '');

        return self::ofDecimal($decimal, RoundingMode::HalfAwayFromZero, $currency)->amount();
    }

    /**
     * Retorna os dois formatos para resposta de API:
     * inteiro canônico + string formatada.
     *
     * @return array{amount: int, formatted: string, currency: string}
     */
    public static function toApiResponse(int $amountInMinorUnits, ?string $currency = null): array
    {
        $currency = self::currencyCode($currency);

        return [
            'amount' => $amountInMinorUnits,
            'formatted' => self::format($amountInMinorUnits, $currency),
            'currency' => $currency,
        ];
    }

    /**
     * Casas decimais da moeda: `platform.money.fraction_digits` (moeda
     * própria ou regra local) ou, na falta, o ISO 4217 via intl.
     */
    public static function fractionDigits(string $currency): int
    {
        $configured = config('platform.money.fraction_digits.'.strtoupper($currency));

        if ($configured !== null) {
            if (! is_int($configured) || $configured < 0 || $configured > 18) {
                throw new InvalidArgumentException("platform.money.fraction_digits.{$currency} deve ser um inteiro de 0 a 18.");
            }

            return $configured;
        }

        $formatter = new NumberFormatter('en', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $currency);

        return $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
    }

    /**
     * A regra de arredondamento configurada (`platform.money.rounding`, nome
     * de um caso do enum nativo RoundingMode). Para o projeto que quer uma
     * regra só: `$valor->basisPoints(399, Money::defaultRounding())` — a
     * chamada continua dizendo que arredonda.
     */
    public static function defaultRounding(): RoundingMode
    {
        $name = config('platform.money.rounding') ?? 'HalfAwayFromZero';

        foreach (RoundingMode::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new InvalidArgumentException('platform.money.rounding inválido: ['.(is_string($name) ? $name : get_debug_type($name)).']. Use um caso de RoundingMode (HalfAwayFromZero, HalfEven, TowardsZero, NegativeInfinity...).');
    }

    // -------------------------------------------------------------------------
    // Internos
    // -------------------------------------------------------------------------

    private function ratio(string $numerator, string $denominator, RoundingMode $rounding, string $operation): self
    {
        $product = Arithmetic::mul((string) $this->minorUnits, $numerator);

        return $this->with(Arithmetic::divide($product, $denominator, $rounding), $operation);
    }

    private function with(string $minorUnits, string $operation): self
    {
        return new self(Arithmetic::toInt($minorUnits, $operation), $this->currency);
    }

    private function assertSameCurrency(self $other, string $operation): void
    {
        if ($this->currency !== $other->currency) {
            throw CurrencyMismatchException::between($this->currency, $other->currency, $operation);
        }
    }

    private static function currencyCode(?string $currency): string
    {
        $currency = strtoupper($currency ?? platform()->currency);

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Código de moeda inválido: [{$currency}]. Use o código ISO 4217 de três letras.");
        }

        return $currency;
    }

    private static function insertAfterLastDigit(string $formatted, string $insert): string
    {
        [$offset, $length] = self::lastDigit($formatted);

        return substr($formatted, 0, $offset + $length).$insert.substr($formatted, $offset + $length);
    }

    private static function replaceLastDigit(string $formatted, string $replacement): string
    {
        [$offset, $length] = self::lastDigit($formatted);

        return substr($formatted, 0, $offset).$replacement.substr($formatted, $offset + $length);
    }

    /**
     * Posição (em bytes) e tamanho do último dígito — de qualquer sistema de
     * numeração — do texto que o intl formatou.
     *
     * @return array{0: int, 1: int}
     */
    private static function lastDigit(string $formatted): array
    {
        if (preg_match('/\p{Nd}(?!.*\p{Nd})/su', $formatted, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new LogicException("O intl formatou um valor sem dígitos: [{$formatted}].");
        }

        return [$match[0][1], strlen($match[0][0])];
    }
}
