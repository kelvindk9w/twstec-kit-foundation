<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Customization;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\View\Factory;

/**
 * As telas Livewire/Blade do kit (starter Livewire): as views de entrada,
 * cadastro, segundo fator, perfil, contas, chaves de API, projetos, webhooks,
 * os layouts, o esqueleto do site, os componentes do design system e as
 * parciais moram neste pacote, em resources/livewire, com os MESMOS nomes de
 * view de sempre (`auth.login`, `livewire.profile`, `<x-button>`…).
 *
 * O APLICATIVO VENCE: a pasta entra como última opção de busca das views
 * (PackageViews), então o arquivo de mesmo caminho em resources/views do
 * aplicativo vale no lugar do pacote. Para mudar uma tela ou um componente,
 * copie só aquele arquivo para resources/views.
 *
 * Quem liga é o starter Livewire (AppServiceProvider), uma vez: o starter
 * React não usa estas views, e um aplicativo React não deve achá-las por
 * engano no lugar das dele.
 */
final class LivewireScreens
{
    /**
     * A pasta das telas no pacote.
     */
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/livewire';
    }

    public static function register(Application $app): void
    {
        PackageViews::register($app, self::path());

        // Também em `view.paths`, depois das do aplicativo: as páginas de erro
        // (`errors::429`…) são procuradas em <cada view.path>/errors na hora
        // de renderizar o erro.
        if ($app->bound('config')) {
            $config = $app->make('config');
            $paths = (array) $config->get('view.paths', []);

            if (! in_array(self::path(), $paths, true)) {
                $config->set('view.paths', [...$paths, self::path()]);
            }
        }

        // O layout das páginas Livewire é `layouts::app` (config
        // livewire.component_layout). O Livewire só cria o namespace
        // `layouts` se resources/views/layouts existir no aplicativo; a pasta
        // do pacote entra depois dele (no booted, quando o Livewire já
        // registrou a do aplicativo), e a do aplicativo continua vencendo.
        $app->booted(static function (Application $app): void {
            $layouts = self::path().'/layouts';
            $factory = $app->make('view');

            if ($factory instanceof Factory && ! in_array($layouts, $factory->getFinder()->getHints()['layouts'] ?? [], true)) {
                $factory->addNamespace('layouts', $layouts);
            }
        });
    }
}
