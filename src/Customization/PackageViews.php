<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Customization;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\FileViewFinder;

/**
 * Views de um pacote do kit com os NOMES de sempre (`mail.messages.<slug>`),
 * com a regra de que O APLICATIVO VENCE.
 *
 * A pasta de views do pacote entra como última opção de busca: o arquivo de
 * mesmo nome em resources/views do aplicativo prevalece; sem ele, vale o do
 * pacote. Assim o corpo de um e-mail do kit chega atualizado pelo Composer, e
 * quem quiser mudá-lo copia só aquele arquivo para resources/views.
 *
 * As pastas ficam registradas para o `kit:overrides` comparar com as cópias
 * do aplicativo (Console\ListOverrides).
 */
final class PackageViews
{
    /**
     * @var list<string>
     */
    private static array $paths = [];

    public static function register(Application $app, string $path): void
    {
        if (! in_array($path, self::$paths, true)) {
            self::$paths[] = $path;
        }

        $install = static function (mixed $factory) use ($path): void {
            if (! $factory instanceof ViewFactory) {
                return;
            }

            $finder = $factory->getFinder();

            if (! $finder instanceof FileViewFinder || ! in_array($path, $finder->getPaths(), true)) {
                $factory->addLocation($path);
            }
        };

        $app->afterResolving('view', $install);

        if ($app->resolved('view')) {
            $install($app->make('view'));
        }
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        return self::$paths;
    }
}
