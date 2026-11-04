<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Customization;

/**
 * As telas React do kit (starter React): páginas, layouts, componentes, hooks,
 * tipos e o `lib/` moram neste pacote, em resources/react, com a MESMA árvore
 * do resources/js do aplicativo — e O APLICATIVO VENCE: o arquivo de mesmo
 * caminho em resources/js vale no lugar do pacote.
 *
 * No navegador, quem aplica a regra é o Vite (vite/react.js para os `@/…` e
 * resources/react/lib/pages.ts para as páginas). Aqui no servidor, a mesma
 * regra para o que o PHP precisa saber: qual arquivo de página pedir ao
 * manifesto do Vite (o documento raiz pré-carrega a página aberta) e onde
 * estão as telas, para o `kit:overrides` comparar com as cópias.
 */
final class ReactScreens
{
    /**
     * Onde as telas ficam, a partir da raiz do aplicativo (vendor/). É o
     * caminho que o Vite grava no manifesto.
     */
    public const PATH = 'vendor/twstec/kit-foundation/resources/react';

    /**
     * A pasta das telas no pacote (a de verdade, para ler os arquivos).
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/react';
    }

    /**
     * A entrada do Vite da página `$component` (`auth/login`, `dashboard`):
     * a do aplicativo, se ele tiver o arquivo; senão, a do kit.
     */
    public static function pageEntry(string $component): string
    {
        $application = "resources/js/pages/{$component}.tsx";

        return is_file(base_path($application)) ? $application : self::PATH."/pages/{$component}.tsx";
    }
}
