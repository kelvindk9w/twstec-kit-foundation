<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

/**
 * As políticas do cabeçalho `Referrer-Policy` e a comparação "não é mais
 * frouxa que" — o que deixa uma rota pedir uma política MAIS RESTRITA que a
 * padrão (`security.headers.referrer_policy`) sem nunca poder afrouxá-la.
 *
 * Cada política diz quanto do endereço de origem vai em três casos: para a
 * mesma origem, para outra origem por HTTPS e no rebaixamento (HTTPS →
 * HTTP); 0 = nada, 1 = só a origem, 2 = o endereço inteiro. Uma política não
 * é mais frouxa que outra quando, em cada caso, não manda mais que ela. Duas
 * políticas sem comparação (`same-origin` e `strict-origin`, por exemplo) não
 * se substituem.
 */
final class ReferrerPolicy
{
    public const HEADER = 'Referrer-Policy';

    /**
     * [mesma origem, outra origem (HTTPS), rebaixamento].
     *
     * @var array<string, array{0: int, 1: int, 2: int}>
     */
    private const LEAKS = [
        'no-referrer' => [0, 0, 0],
        'same-origin' => [2, 0, 0],
        'strict-origin' => [1, 1, 0],
        'strict-origin-when-cross-origin' => [2, 1, 0],
        'origin' => [1, 1, 1],
        'origin-when-cross-origin' => [2, 1, 1],
        'no-referrer-when-downgrade' => [2, 2, 0],
        'unsafe-url' => [2, 2, 2],
    ];

    /**
     * A política reconhecida no valor (um token só, sem diferença de
     * maiúsculas), ou null.
     */
    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return array_key_exists($value, self::LEAKS) ? $value : null;
    }

    public static function isValid(?string $value): bool
    {
        return self::normalize($value) !== null;
    }

    /**
     * `$candidate` não manda, em nenhum caso, mais do endereço que
     * `$baseline`? Valor que não é uma política conhecida nunca passa.
     */
    public static function isAtLeastAsStrictAs(?string $candidate, ?string $baseline): bool
    {
        $candidate = self::normalize($candidate);
        $baseline = self::normalize($baseline);

        if ($candidate === null || $baseline === null) {
            return false;
        }

        foreach (self::LEAKS[$candidate] as $case => $leak) {
            if ($leak > self::LEAKS[$baseline][$case]) {
                return false;
            }
        }

        return true;
    }

    /**
     * A política que a resposta leva: a que a rota pediu, se não for mais
     * frouxa que a padrão; senão, a padrão.
     */
    public static function resolve(?string $requested, string $default): string
    {
        return $requested !== null && self::isAtLeastAsStrictAs($requested, $default)
            ? (string) self::normalize($requested)
            : $default;
    }
}
