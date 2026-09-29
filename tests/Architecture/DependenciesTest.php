<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// ARQUITETURA DO PACOTE foundation — a base do kit não conhece ninguém.
//
// foundation é a camada de baixo: auth, accounts, uploads e o painel de
// administração dependem dela, nunca o contrário. Este arquivo reprova o
// build quando:
//
// 1. qualquer arquivo do pacote (código, config, migrations, traduções e
//    views) nomeia classe do APLICATIVO (App\…) — o pacote precisa funcionar
//    em qualquer aplicação Laravel, não só no starter;
// 2. o pacote usa classe das camadas de cima (os pacotes auth, accounts,
//    uploads, admin) ou de tela (Filament, Livewire);
// 3. aparece um módulo no pacote sem estar declarado aqui;
// 4. surge um CICLO entre módulos do pacote que não seja o grupo coeso
//    declarado.
//
// A leitura do PHP é por tokens: comentários e strings não contam, só nomes
// de classe de verdade (`use`, `new`, `::class`, tipos, `instanceof`…). Nas
// views Blade, que não são PHP puro, vale o nome escrito em qualquer lugar.
// =============================================================================

/**
 * Módulos do pacote (pastas de src/). `Compat` não é módulo: guarda só os
 * apelidos dos nomes antigos.
 */
const FOUNDATION_MODULES = ['Identifiers', 'Money', 'Http', 'Security', 'Logging', 'Localization', 'Settings', 'Mail', 'Support', 'Backup', 'Audit'];

/**
 * Grupo coeso: ciclo aceito porque os módulos são a mesma peça vista de
 * lados diferentes. Segurança ↔ trilha de requisições ↔ HTTP (+ idioma, que
 * usa o redirecionamento seguro do HTTP e é usado pelo limite da borda).
 */
const FOUNDATION_COHESIVE_CYCLES = [
    ['Http', 'Localization', 'Logging', 'Security'],
];

/**
 * Prefixos que o pacote não pode usar: o aplicativo, as camadas de cima e
 * as bibliotecas de tela.
 */
const FOUNDATION_FORBIDDEN_PREFIXES = [
    'App\\',
    'Twstec\\Kit\\Auth\\',
    'Twstec\\Kit\\Accounts\\',
    'Twstec\\Kit\\Uploads\\',
    'Twstec\\Kit\\Admin\\',
    'Filament\\',
    'Livewire\\',
];

/**
 * Pastas do pacote que vão para a aplicação de quem instala.
 */
const FOUNDATION_SHIPPED_DIRECTORIES = ['src', 'config', 'database', 'lang', 'resources'];

function foundationRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Nomes de classe qualificados de um arquivo PHP, lidos dos tokens.
 *
 * @return list<string>
 */
function foundationClassNamesIn(string $contents): array
{
    $names = [];

    foreach (PhpToken::tokenize($contents) as $token) {
        if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $names[ltrim($token->text, '\\')] = true;
        }
    }

    return array_keys($names);
}

/**
 * Nomes proibidos citados por arquivo do pacote.
 *
 * @return array<string, list<string>> caminho relativo => nomes
 */
function foundationForbiddenReferences(): array
{
    $references = [];

    foreach (FOUNDATION_SHIPPED_DIRECTORIES as $directory) {
        foreach ((new Finder)->files()->in(foundationRoot().'/'.$directory)->name('*.php') as $file) {
            $path = str_replace(foundationRoot().'/', '', $file->getRealPath());

            if (str_ends_with($path, '.blade.php')) {
                preg_match_all('/\b(?:App|Filament|Livewire|Twstec\\\\+Kit\\\\+(?:Auth|Accounts|Uploads|Admin))(?:\\\\+[A-Za-z0-9_]+)+/', $file->getContents(), $matches);
                $names = array_values(array_unique(array_map(
                    fn (string $name): string => (string) preg_replace('/\\\\+/', '\\', $name),
                    $matches[0],
                )));
            } else {
                $names = foundationClassNamesIn($file->getContents());
            }

            $forbidden = array_values(array_filter($names, function (string $name): bool {
                foreach (FOUNDATION_FORBIDDEN_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        return true;
                    }
                }

                return false;
            }));

            if ($forbidden !== []) {
                $references[$path] = $forbidden;
            }
        }
    }

    ksort($references);

    return $references;
}

/**
 * Módulo de um caminho (`src/<Módulo>/…`) ou de uma classe do pacote.
 */
function foundationModuleOf(string $pathOrClass): ?string
{
    if (preg_match('#^src/([^/]+)/#', $pathOrClass, $match) === 1) {
        return $match[1];
    }

    if (preg_match('/^Twstec\\\\Kit\\\\Foundation\\\\([^\\\\]+)\\\\/', $pathOrClass, $match) === 1) {
        return $match[1];
    }

    return null;
}

/**
 * Grafo de dependências entre módulos do pacote: módulo => módulos que usa.
 *
 * @return array<string, list<string>>
 */
function foundationModuleGraph(): array
{
    $graph = [];

    foreach ((new Finder)->files()->in(foundationRoot().'/src')->name('*.php') as $file) {
        $from = foundationModuleOf(str_replace(foundationRoot().'/', '', $file->getRealPath()));

        if ($from === null || ! in_array($from, FOUNDATION_MODULES, true)) {
            continue;
        }

        $graph[$from] ??= [];

        foreach (foundationClassNamesIn($file->getContents()) as $name) {
            $to = foundationModuleOf($name);

            if ($to !== null && $to !== $from && in_array($to, FOUNDATION_MODULES, true) && ! in_array($to, $graph[$from], true)) {
                $graph[$from][] = $to;
            }
        }
    }

    ksort($graph);

    return $graph;
}

/**
 * Componentes fortemente conexos com mais de um módulo (os ciclos), pelo
 * algoritmo de Tarjan. Cada grupo vem ordenado, e a lista também.
 *
 * @param  array<string, list<string>>  $graph
 * @return list<list<string>>
 */
function foundationCycles(array $graph): array
{
    $index = 0;
    $indices = [];
    $lowlinks = [];
    $stack = [];
    $onStack = [];
    $components = [];

    $connect = function (string $node) use (&$connect, &$index, &$indices, &$lowlinks, &$stack, &$onStack, &$components, $graph): void {
        $indices[$node] = $lowlinks[$node] = $index++;
        $stack[] = $node;
        $onStack[$node] = true;

        foreach ($graph[$node] ?? [] as $next) {
            if (! isset($indices[$next])) {
                $connect($next);
                $lowlinks[$node] = min($lowlinks[$node], $lowlinks[$next]);
            } elseif ($onStack[$next] ?? false) {
                $lowlinks[$node] = min($lowlinks[$node], $indices[$next]);
            }
        }

        if ($lowlinks[$node] === $indices[$node]) {
            $component = [];

            do {
                $member = array_pop($stack);
                $onStack[$member] = false;
                $component[] = $member;
            } while ($member !== $node);

            if (count($component) > 1) {
                sort($component);
                $components[] = $component;
            }
        }
    };

    foreach (array_keys($graph) as $node) {
        if (! isset($indices[$node])) {
            $connect($node);
        }
    }

    usort($components, fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

    return $components;
}

it('não nomeia nada do aplicativo nem das camadas de cima', function (): void {
    $violations = [];

    foreach (foundationForbiddenReferences() as $path => $names) {
        foreach ($names as $name) {
            $violations[] = "{$path} usa {$name}";
        }
    }

    expect($violations)->toBe([]);
});

it('declara todo módulo do pacote, uma vez só', function (): void {
    expect(FOUNDATION_MODULES)->toHaveCount(count(array_unique(FOUNDATION_MODULES)));

    $modules = [];

    foreach ((new Finder)->directories()->in(foundationRoot().'/src')->depth(0) as $directory) {
        if ($directory->getFilename() !== 'Compat') {
            $modules[] = $directory->getFilename();
        }
    }

    $declared = FOUNDATION_MODULES;
    sort($modules);
    sort($declared);

    expect($modules)->toBe($declared);
});

it('só aceita ciclos entre módulos do grupo coeso', function (): void {
    $expected = array_map(function (array $group): array {
        sort($group);

        return $group;
    }, FOUNDATION_COHESIVE_CYCLES);

    usort($expected, fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

    expect(foundationCycles(foundationModuleGraph()))->toBe($expected);
});
