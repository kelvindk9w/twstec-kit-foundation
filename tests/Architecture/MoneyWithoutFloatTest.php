<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// DINHEIRO NUNCA É FLOAT — no módulo Money inteiro (src/Money), nem num passo
// intermediário nem na exibição.
//
// Leitura por tokens do PHP (comentários e strings não contam). Reprova:
// - o tipo e o cast `float`/`double`, literal decimal (1.5, 1e3);
// - o operador `/` (em PHP, int / int pode virar float — o módulo divide por
//   bcmath ou intdiv);
// - as funções que devolvem ou exigem float (round, floor, ceil, fdiv, fmod,
//   floatval, number_format...) e as do intl que passam o valor por float
//   (formatCurrency, parseCurrency).
// =============================================================================

const MONEY_FLOAT_FUNCTIONS = ['float', 'double', 'floatval', 'doubleval', 'round', 'floor', 'ceil', 'fdiv', 'fmod', 'number_format', 'formatcurrency', 'parsecurrency'];

/**
 * @return list<string> descrições das violações
 */
function moneyFloatViolations(string $code): array
{
    $violations = [];

    foreach (PhpToken::tokenize($code) as $token) {
        $text = strtolower($token->text);

        if ($token->is([T_DNUMBER, T_DOUBLE_CAST, T_DIV_EQUAL])
            || $token->text === '/'
            || ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && in_array(ltrim($text, '\\'), MONEY_FLOAT_FUNCTIONS, true))) {
            $violations[] = "linha {$token->line}: {$token->text}";
        }
    }

    return $violations;
}

it('o módulo Money não usa float em nenhum passo', function (): void {
    $files = (new Finder)->files()->in(dirname(__DIR__, 2).'/src/Money')->name('*.php');
    $violations = [];

    expect(iterator_count($files))->toBeGreaterThanOrEqual(4);

    foreach ($files as $file) {
        foreach (moneyFloatViolations($file->getContents()) as $violation) {
            $violations[] = $file->getRelativePathname().' '.$violation;
        }
    }

    expect($violations)->toBe([]);
});

it('a trava não é cega: pega cada forma de float e ignora comentário e string', function (): void {
    $caught = static fn (string $snippet): int => count(moneyFloatViolations("<?php\n".$snippet));

    expect($caught('$a = 1.5;'))->toBe(1)
        ->and($caught('$a = 1e3;'))->toBe(1)
        ->and($caught('$a = (float) $b;'))->toBe(1)
        ->and($caught('$a = (double) $b;'))->toBe(1)
        ->and($caught('function f(float $x): int {}'))->toBe(1)
        ->and($caught('$a = $b / 100;'))->toBe(1)
        ->and($caught('$a /= 100;'))->toBe(1)
        ->and($caught('$a = round($b);'))->toBe(1)
        ->and($caught('$a = \\floor($b);'))->toBe(1)
        ->and($caught('$a = $f->formatCurrency($b, "BRL");'))->toBe(1)
        ->and($caught('$a = $f->parseCurrency($b, $c);'))->toBe(1)
        ->and($caught('$a = intdiv($b, 100); // 1.5 / float'))->toBe(0)
        ->and($caught('$a = "1.5 / float";'))->toBe(0)
        ->and($caught('/* round(1.5) */ $a = 1;'))->toBe(0);
});
