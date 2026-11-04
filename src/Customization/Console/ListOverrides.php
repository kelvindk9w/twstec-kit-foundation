<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Customization\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Attribute\AsCommand;
use Twstec\Kit\Foundation\Customization\PackageConfig;
use Twstec\Kit\Foundation\Customization\PackageViews;
use Twstec\Kit\Foundation\Customization\ReactScreens;
use Twstec\Kit\Foundation\Localization\PackageTranslations;

/**
 * `php artisan kit:overrides` — o que o aplicativo sobrescreve do kit.
 *
 * O padrão das traduções, dos e-mails, das configurações e das telas do kit
 * mora nos pacotes; o aplicativo só precisa do arquivo se mudar algo. Projetos criados
 * antes disso têm CÓPIAS completas, e cópia parada impede a atualização de
 * chegar (o arquivo do aplicativo vence). Este comando compara cada arquivo do
 * aplicativo que tem padrão no kit com esse padrão e diz:
 *
 *   - igual ao padrão           → pode apagar (nada muda);
 *   - personalizado             → as chaves que diferem, e quantas são só
 *                                 cópia do padrão (essas podem sair);
 *   - chaves que o kit não tem  → do próprio aplicativo (ficam). Arquivo só
 *                                 com chaves do aplicativo nem aparece.
 *
 * Views (e-mails e telas Blade) e telas React (resources/js) são comparadas
 * pelo conteúdo inteiro: iguais ao padrão ou personalizadas.
 *
 * Não altera nada.
 */
#[AsCommand(name: 'kit:overrides', description: 'Lista os arquivos do aplicativo que sobrescrevem textos, e-mails, configurações e telas do kit (e quais são só cópias)')]
final class ListOverrides extends Command
{
    protected $signature = 'kit:overrides';

    public function handle(): int
    {
        $rows = [...$this->translations(), ...$this->configs(), ...$this->views(), ...$this->reactScreens()];

        if ($rows === []) {
            $this->components->info(__('kit_overrides.none'));

            return self::SUCCESS;
        }

        foreach ($rows as [$file, $verdict]) {
            $this->components->twoColumnDetail($file, $verdict);
        }

        $this->newLine();
        $this->line(__('kit_overrides.hint'));

        return self::SUCCESS;
    }

    /**
     * @return list<array{string, string}>
     */
    private function translations(): array
    {
        $rows = [];

        foreach ($this->translationGroups() as $relative => $packageFiles) {
            $file = lang_path($relative);

            if (! is_file($file)) {
                continue;
            }

            $json = str_ends_with($relative, '.json');
            $default = [];

            foreach ($packageFiles as $packageFile) {
                $default = array_replace_recursive($default, $this->load($packageFile, $json));
            }

            $verdict = $this->verdict(Arr::dot($this->load($file, $json)), Arr::dot($default));

            if ($verdict !== null) {
                $rows[] = ['lang/'.$relative, $verdict];
            }
        }

        return $rows;
    }

    /**
     * Cada arquivo de tradução que algum pacote traz (pt_BR/auth.php,
     * es.json…), com os arquivos dos pacotes na ordem de registro.
     *
     * @return array<string, list<string>>
     */
    private function translationGroups(): array
    {
        $groups = [];

        foreach (PackageTranslations::paths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach ([...File::glob($path.'/*/*.php'), ...File::glob($path.'/*.json')] as $packageFile) {
                $groups[substr($packageFile, strlen($path) + 1)][] = $packageFile;
            }
        }

        ksort($groups);

        return $groups;
    }

    /**
     * @return list<array{string, string}>
     */
    private function configs(): array
    {
        $rows = [];
        $keys = array_keys(PackageConfig::defaults());
        sort($keys);

        foreach ($keys as $key) {
            $file = config_path($key.'.php');
            $default = PackageConfig::defaultFor($key);

            if (! is_file($file) || $default === null) {
                continue;
            }

            // Na configuração, a chave de PRIMEIRO NÍVEL do aplicativo vence a
            // do pacote inteira (Customization\PackageConfig): a comparação é
            // por ela, não por chave aninhada.
            $application = require $file;
            $verdict = $this->verdict(is_array($application) ? $application : [], $default);

            if ($verdict !== null) {
                $rows[] = ['config/'.$key.'.php', $verdict];
            }
        }

        return $rows;
    }

    /**
     * @return list<array{string, string}>
     */
    private function views(): array
    {
        $rows = [];

        foreach (PackageViews::paths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (File::allFiles($path) as $packageFile) {
                $relative = $packageFile->getRelativePathname();
                $file = resource_path('views/'.$relative);

                if (! is_file($file)) {
                    continue;
                }

                $rows[] = ['resources/views/'.$relative, $this->sameContents($file, $packageFile->getContents())
                    ? '<fg=green>'.__('kit_overrides.copy').'</>'
                    : '<fg=yellow>'.__('kit_overrides.customized_view').'</>'];
            }
        }

        return $rows;
    }

    /**
     * As telas React do kit (ReactScreens) que o aplicativo tem no mesmo
     * caminho em resources/js.
     *
     * @return list<array{string, string}>
     */
    private function reactScreens(): array
    {
        $rows = [];
        $path = ReactScreens::path();

        if (! is_dir($path) || ! is_dir(resource_path('js'))) {
            return [];
        }

        foreach (File::allFiles($path) as $packageFile) {
            $relative = $packageFile->getRelativePathname();
            $file = resource_path('js/'.$relative);

            if (! is_file($file)) {
                continue;
            }

            $rows[] = ['resources/js/'.$relative, $this->sameContents($file, $packageFile->getContents())
                ? '<fg=green>'.__('kit_overrides.copy').'</>'
                : '<fg=yellow>'.__('kit_overrides.customized_view').'</>'];
        }

        return $rows;
    }

    private function sameContents(string $file, string $packageContents): bool
    {
        return $this->normalize((string) file_get_contents($file)) === $this->normalize($packageContents);
    }

    /**
     * O veredito de um arquivo do aplicativo frente ao padrão do kit, ou null
     * quando o arquivo só tem chaves do próprio aplicativo (não sobrescreve
     * nada do kit).
     *
     * @param  array<array-key, mixed>  $application
     * @param  array<array-key, mixed>  $default
     */
    private function verdict(array $application, array $default): ?string
    {
        $changed = [];
        $same = [];
        $own = [];

        foreach ($application as $key => $value) {
            if (! array_key_exists($key, $default)) {
                $own[] = (string) $key;
            } elseif ($value !== $default[$key]) {
                $changed[] = (string) $key;
            } else {
                $same[] = (string) $key;
            }
        }

        if ($changed === [] && $same === []) {
            return null;
        }

        if ($changed === [] && $own === []) {
            return '<fg=green>'.__('kit_overrides.copy').'</>';
        }

        $parts = [];

        if ($changed !== []) {
            $parts[] = '<fg=yellow>'.trans_choice('kit_overrides.customized', count($changed), ['keys' => $this->list($changed)]).'</>';
        }

        if ($same !== []) {
            $parts[] = '<fg=green>'.trans_choice('kit_overrides.same', count($same)).'</>';
        }

        if ($own !== []) {
            $parts[] = trans_choice('kit_overrides.own', count($own), ['keys' => $this->list($own)]);
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  list<string>  $keys
     */
    private function list(array $keys): string
    {
        return implode(', ', array_slice($keys, 0, 5)).(count($keys) > 5 ? ' (+'.(count($keys) - 5).')' : '');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function load(string $file, bool $json): array
    {
        $lines = $json ? json_decode((string) file_get_contents($file), true) : require $file;

        return is_array($lines) ? $lines : [];
    }

    private function normalize(string $contents): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $contents));
    }
}
