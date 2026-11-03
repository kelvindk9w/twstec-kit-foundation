<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Customization;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;

/**
 * Configuração de um pacote do kit com a regra de que O APLICATIVO VENCE.
 *
 * O padrão mora no pacote (config/<arquivo>.php dele). O aplicativo só tem
 * config/<arquivo>.php se quiser mudar algo, e escreve só as CHAVES DE
 * PRIMEIRO NÍVEL que muda: cada uma vence a do pacote inteira; as que ele não
 * escreve vêm do pacote. (É a regra do `mergeConfigFrom` do framework.)
 *
 * Por que inteira, e não chave a chave em qualquer profundidade: boa parte da
 * configuração do kit é lista de permissões em forma de mapa (tipos de upload
 * aceitos, campos editáveis no /admin, escopos das chaves de API). Com a
 * junção profunda, o aplicativo que restringiu um desses mapas — só PDF nos
 * uploads, por exemplo — ganharia de volta, em silêncio, o que tinha tirado.
 * Para mudar um valor solto, o caminho é a variável do .env que todo valor do
 * kit tem; o arquivo é para mudar um bloco.
 *
 * Um módulo opcional que acrescenta itens à configuração de OUTRO pacote (as
 * credenciais temporárias da autenticação na lista de models que a auditoria
 * ignora, a permissão de upload nas chaves de API…) usa contribute(): os itens
 * entram depois de todos os register(), antes de qualquer boot(), sem cópia no
 * aplicativo — salvo quando o aplicativo escreveu aquela chave de primeiro
 * nível: aí a lista é dele, inteira (como sempre foi com as cópias).
 *
 * Com a configuração em cache (`config:cache`), nada disso roda: o cache já
 * guarda o resultado final.
 *
 * Os padrões e as contribuições ficam registrados para o `kit:overrides`
 * comparar com o config/ do aplicativo (Console\ListOverrides).
 */
final class PackageConfig
{
    /**
     * Arquivo padrão de cada chave de configuração, por pacote.
     *
     * @var array<string, string>
     */
    private static array $defaults = [];

    /**
     * Contribuições de módulos, por chave em notação de ponto.
     *
     * @var array<string, list<array<array-key, mixed>>>
     */
    private static array $contributions = [];

    /**
     * As chaves de primeiro nível que o aplicativo escreveu, por arquivo.
     *
     * @var array<string, list<array-key>>
     */
    private static array $applicationKeys = [];

    /**
     * Junta o padrão do pacote (`$path`) ao que o aplicativo tem em `$key`.
     */
    public static function merge(Application $app, string $path, string $key): void
    {
        self::$defaults[$key] = $path;

        if ($app instanceof CachesConfiguration && $app->configurationIsCached()) {
            return;
        }

        $config = $app->make('config');
        $package = require $path;
        $application = $config->get($key);
        $application = is_array($application) ? $application : [];

        self::$applicationKeys[$key] = array_keys($application);

        $config->set($key, array_merge(is_array($package) ? $package : [], $application));
    }

    /**
     * Acrescenta `$values` à configuração `$key` (notação de ponto, ao menos
     * `<arquivo>.<chave>`) de outro pacote, sem cópia no aplicativo. Lista
     * recebe os itens que faltam; mapa recebe as chaves que faltam.
     *
     * Quando o aplicativo escreveu a chave de primeiro nível, ela é dele e a
     * contribuição não entra — salvo com `$intoApplicationKeys`, para o
     * módulo cujos itens sempre entraram também nas cópias do aplicativo (a
     * demonstração: instalá-la é pedir as telas e as rotas dela).
     *
     * @param  array<array-key, mixed>  $values
     */
    public static function contribute(Application $app, string $key, array $values, bool $intoApplicationKeys = false): void
    {
        if (! in_array($values, self::$contributions[$key] ?? [], true)) {
            self::$contributions[$key][] = $values;
        }

        if ($app instanceof CachesConfiguration && $app->configurationIsCached()) {
            return;
        }

        $apply = static function () use ($app, $key, $values, $intoApplicationKeys): void {
            [$file, $first] = array_pad(explode('.', $key, 3), 2, null);

            // O aplicativo escreveu a chave de primeiro nível: ela é dele.
            if (! $intoApplicationKeys && $first !== null && in_array($first, self::$applicationKeys[$file] ?? [], true)) {
                return;
            }

            $config = $app->make('config');
            $config->set($key, self::addMissing($config->get($key), $values));
        };

        $app->isBooted() ? $apply() : $app->booting($apply);
    }

    /**
     * O padrão do pacote para `$key`, com as contribuições dos módulos — o que
     * valeria sem config/<arquivo>.php no aplicativo. Null quando o kit não
     * traz padrão para essa chave.
     *
     * @return array<array-key, mixed>|null
     */
    public static function defaultFor(string $key): ?array
    {
        if (! isset(self::$defaults[$key]) || ! is_file(self::$defaults[$key])) {
            return null;
        }

        $value = require self::$defaults[$key];
        $value = is_array($value) ? $value : [];

        foreach (self::$contributions as $contributionKey => $list) {
            if (! str_starts_with($contributionKey, $key.'.')) {
                continue;
            }

            $path = substr($contributionKey, strlen($key) + 1);

            foreach ($list as $values) {
                data_set($value, $path, self::addMissing(data_get($value, $path), $values));
            }
        }

        return $value;
    }

    /**
     * As chaves de configuração que têm padrão no kit e o arquivo de cada uma.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return self::$defaults;
    }

    /**
     * `$values` acrescentados a `$current`: na lista, os itens que faltam; no
     * mapa, as chaves que faltam (as que existem ficam como estão).
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function addMissing(mixed $current, array $values): array
    {
        if (! is_array($current)) {
            return $values;
        }

        if (array_is_list($values)) {
            foreach ($values as $value) {
                if (! in_array($value, $current, true)) {
                    $current[] = $value;
                }
            }

            return $current;
        }

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $current)) {
                $current[$key] = $value;
            }
        }

        return $current;
    }
}
