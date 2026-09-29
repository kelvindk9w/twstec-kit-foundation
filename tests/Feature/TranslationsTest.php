<?php

declare(strict_types=1);

// As traduções do pacote: mesmas chaves nos três idiomas, e resolvidas pelos
// nomes de sempre (sem namespace), somadas às do aplicativo grupo a grupo.

const FOUNDATION_LOCALES = ['pt_BR', 'en', 'es'];

function foundationLangPath(string $relative): string
{
    return dirname(__DIR__, 2).'/lang/'.$relative;
}

it('todos os idiomas têm os mesmos arquivos e chaves do pt-BR', function (): void {
    $reference = [];

    foreach (glob(foundationLangPath('pt_BR/*.php')) ?: [] as $file) {
        $reference[basename($file)] = collect(require $file)->dot()->keys()->sort()->values();
    }

    expect($reference)->not->toBeEmpty();

    foreach (['en', 'es'] as $locale) {
        foreach ($reference as $file => $keys) {
            $path = foundationLangPath("{$locale}/{$file}");

            expect($path)->toBeFile();

            $actual = collect(require $path)->dot()->keys()->sort()->values();

            expect($actual->all())->toBe($keys->all(), "lang/{$locale}/{$file} diverge do pt-BR");
        }
    }
});

it('resolve cada chave do pacote pelo nome de sempre, nos três idiomas', function (string $locale): void {
    foreach (glob(foundationLangPath('pt_BR/*.php')) ?: [] as $file) {
        $group = basename($file, '.php');

        foreach (collect(require $file)->dot()->keys() as $key) {
            expect(app('translator')->hasForLocale("{$group}.{$key}", $locale))
                ->toBeTrue("Falta {$group}.{$key} em {$locale}");
        }
    }
})->with(FOUNDATION_LOCALES);
