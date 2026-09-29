<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Localization;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Translation\FileLoader;

/**
 * Traduções de um pacote do kit, sem namespace (`__('security.blocked')`,
 * `__('auth.failed')`…), com a regra de que O APLICATIVO VENCE.
 *
 * A pasta de traduções do pacote entra na lista do carregador ANTES da pasta
 * lang/ do aplicativo. O carregador junta os arquivos de mesmo grupo na ordem
 * da lista (array_replace_recursive), e o último ganha: numa mesma chave vale
 * o texto do aplicativo, e o pacote só preenche o que o aplicativo não
 * definiu — em todo grupo, em todo idioma, inclusive no idioma de reserva
 * (fallback). (O `loadTranslationsFrom` sem namespace do framework põe a
 * pasta DEPOIS da do aplicativo, e aí o pacote venceria.)
 *
 * Todo pacote do kit registra a própria pasta por aqui (foundation, auth…).
 * Entre dois pacotes, vale a ordem de registro: o registrado depois fica mais
 * perto do aplicativo e vence o anterior na mesma chave — e o aplicativo
 * vence todos.
 */
final class PackageTranslations
{
    /**
     * Registra a pasta `$path` do pacote. Roda quando o carregador é
     * resolvido, ou na hora, se ele já foi.
     */
    public static function register(Application $app, string $path): void
    {
        $install = static function (mixed $loader) use ($app, $path): void {
            if (! $loader instanceof FileLoader) {
                // Carregador próprio da aplicação: o único gancho garantido é
                // acrescentar a pasta (o pacote só preenche se o carregador
                // dela não achar a chave antes).
                if (is_object($loader) && method_exists($loader, 'addPath')) {
                    $loader->addPath($path);
                }

                return;
            }

            $paths = self::packageBeforeApplication($loader->paths(), $path, $app->langPath());

            (function (array $paths): void {
                $this->paths = $paths;
            })->call($loader, $paths);
        };

        $app->afterResolving('translation.loader', $install);

        if ($app->resolved('translation.loader')) {
            $install($app->make('translation.loader'));
        }
    }

    /**
     * A lista de pastas do carregador com a do pacote logo antes da do
     * aplicativo (ou no fim, se a do aplicativo não estiver na lista).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public static function packageBeforeApplication(array $paths, string $package, string $application): array
    {
        $paths = array_values(array_filter($paths, fn (string $path): bool => $path !== $package));
        $position = array_search($application, $paths, true);

        array_splice($paths, $position === false ? count($paths) : $position, 0, [$package]);

        return $paths;
    }
}
