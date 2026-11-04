<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Twstec\Kit\Foundation\Customization\LivewireScreens;
use Twstec\Kit\Foundation\Customization\PackageConfig;
use Twstec\Kit\Foundation\Customization\PackageViews;
use Twstec\Kit\Foundation\Customization\ReactScreens;

// =============================================================================
// O padrão mora no pacote; o aplicativo sobrescreve só o que muda
// (docs/personalizacao.md).
//
//   - configuração: a chave de primeiro nível do aplicativo vence a do
//     pacote inteira (mapa restringido fica restringido);
//   - contribuição de módulo: entra sem cópia no aplicativo;
//   - views: o arquivo de mesmo nome no aplicativo vence;
//   - telas React: a página de mesmo caminho em resources/js vence;
//   - `kit:overrides`: diz quais arquivos do aplicativo são só cópias.
// =============================================================================

/**
 * Pasta temporária com os arquivos dados (caminho relativo => conteúdo).
 *
 * @param  array<string, string>  $files
 */
function customizationDir(array $files): string
{
    $dir = sys_get_temp_dir().'/kit-customization-'.uniqid();

    foreach ($files as $relative => $contents) {
        (new Filesystem)->ensureDirectoryExists(dirname("{$dir}/{$relative}"));
        file_put_contents("{$dir}/{$relative}", $contents);
    }

    return $dir;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/kit-customization-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('sem config/<arquivo>.php no aplicativo, vale o padrão inteiro do pacote', function (): void {
    $default = require PackageConfig::defaults()['security'];

    expect(config('security.headers.frame_options'))->toBe($default['headers']['frame_options'])
        ->and(config('security.validation.mode'))->toBe($default['validation']['mode']);
});

/**
 * Como o boot faria: o config/<arquivo>.php do aplicativo (`$application`)
 * já carregado, e o pacote juntando o padrão dele.
 *
 * @param  array<string, mixed>  $application
 */
function mergeAsBoot(string $key, array $application): void
{
    config()->set($key, $application);

    PackageConfig::merge(app(), PackageConfig::defaults()[$key], $key);
}

it('a chave de primeiro nível do aplicativo vence a do pacote inteira; as outras vêm do pacote', function (): void {
    $default = require PackageConfig::defaults()['security'];

    mergeAsBoot('security', ['headers' => ['frame_options' => 'SAMEORIGIN']]);

    expect(config('security.headers'))->toBe(['frame_options' => 'SAMEORIGIN'])
        ->and(config('security.validation'))->toBe($default['validation'])
        ->and(config('security.rate_limit'))->toBe($default['rate_limit']);
});

it('mapa que o aplicativo restringiu fica restrito: o pacote não devolve o que ele tirou', function (): void {
    // Ex.: só PDF nos uploads. Com junção profunda, o tipo que o aplicativo
    // tirou voltaria em silêncio.
    $default = require PackageConfig::defaults()['security'];
    $surfaces = ['admin' => ['painel*']];

    mergeAsBoot('security', ['headers' => [...$default['headers'], 'surfaces' => $surfaces]]);

    expect(config('security.headers.surfaces'))->toBe($surfaces)
        ->and(config('security.headers.surfaces'))->not->toHaveKey('horizon');
});

it('projeto antigo, com a cópia completa: a configuração fica exatamente a da cópia', function (): void {
    $copy = require PackageConfig::defaults()['audit'];
    $copy['retention_days'] = 30;

    mergeAsBoot('audit', $copy);

    expect(config('audit'))->toBe($copy);
});

/**
 * Um arquivo de configuração fictício do "pacote" (`kit_teste`), juntado como
 * o boot faria, com o config/kit_teste.php do aplicativo = `$application`.
 *
 * @param  array<string, mixed>  $application
 */
function fakePackageConfig(array $application): void
{
    $dir = customizationDir(['config/kit_teste.php' => '<?php return '.var_export([
        'lista' => ['App\\Models\\DoPacote'],
        'mapa' => ['do.pacote' => ['type' => 'int']],
        'valor' => 1,
    ], true).';']);

    config()->set('kit_teste', $application);

    PackageConfig::merge(app(), $dir.'/config/kit_teste.php', 'kit_teste');
}

it('contribuição de módulo: lista ganha os itens que faltam e mapa as chaves que faltam', function (): void {
    fakePackageConfig([]);

    PackageConfig::contribute(app(), 'kit_teste.lista', ['App\\Models\\DoPacote', 'App\\Models\\DoModulo']);
    PackageConfig::contribute(app(), 'kit_teste.mapa', ['do.pacote' => ['type' => 'bool'], 'do.modulo' => ['type' => 'bool']]);

    expect(config('kit_teste.lista'))->toBe(['App\\Models\\DoPacote', 'App\\Models\\DoModulo'])
        ->and(config('kit_teste.mapa'))->toBe(['do.pacote' => ['type' => 'int'], 'do.modulo' => ['type' => 'bool']])
        ->and(PackageConfig::defaultFor('kit_teste')['lista'])->toBe(['App\\Models\\DoPacote', 'App\\Models\\DoModulo']);
});

it('contribuição não entra na chave de primeiro nível que o aplicativo escreveu: a lista é dele', function (): void {
    fakePackageConfig(['lista' => ['App\\Models\\SoDoAplicativo']]);

    PackageConfig::contribute(app(), 'kit_teste.lista', ['App\\Models\\DoModuloOutro']);

    expect(config('kit_teste.lista'))->toBe(['App\\Models\\SoDoAplicativo'])
        ->and(config('kit_teste.valor'))->toBe(1);
});

it('contribuição com intoApplicationKeys entra também na chave que o aplicativo escreveu', function (): void {
    fakePackageConfig(['lista' => ['App\\Models\\SoDoAplicativo']]);

    PackageConfig::contribute(app(), 'kit_teste.lista', ['App\\Models\\DaDemo'], intoApplicationKeys: true);

    expect(config('kit_teste.lista'))->toBe(['App\\Models\\SoDoAplicativo', 'App\\Models\\DaDemo']);
});

it('contribuição registrada antes de o pacote dono juntar o padrão não esconde o resto do padrão', function (): void {
    // A ordem dos providers não importa: um módulo que acrescenta uma chave
    // aninhada (security.headers.surfaces.*) no register, antes do pacote
    // dono, não pode fazer `headers` parecer escrita pelo aplicativo.
    $fresh = new Application(dirname(__DIR__, 3));
    $fresh->instance('config', new Repository([]));
    $dir = customizationDir(['config/kit_teste_ordem.php' => '<?php return '.var_export([
        'headers' => ['csp' => "default-src 'self'", 'surfaces' => ['admin' => ['admin*']]],
    ], true).';']);

    PackageConfig::contribute($fresh, 'kit_teste_ordem.headers.surfaces.extra', ['v2'], intoApplicationKeys: true);
    PackageConfig::merge($fresh, $dir.'/config/kit_teste_ordem.php', 'kit_teste_ordem');
    $fresh->boot();

    expect($fresh['config']->get('kit_teste_ordem.headers'))->toBe([
        'csp' => "default-src 'self'",
        'surfaces' => ['admin' => ['admin*'], 'extra' => ['v2']],
    ]);
});

it('contribuição registrada no register entra antes de qualquer boot()', function (): void {
    $fresh = new Application(dirname(__DIR__, 3));
    $fresh->instance('config', new Repository(['kit_teste_boot' => ['lista' => []]]));

    PackageConfig::contribute($fresh, 'kit_teste_boot.lista', ['App\\Models\\Contribuido']);

    expect($fresh['config']->get('kit_teste_boot.lista'))->toBe([]);

    $fresh->boot();

    expect($fresh['config']->get('kit_teste_boot.lista'))->toBe(['App\\Models\\Contribuido']);
});

it('view do pacote: o arquivo de mesmo nome no aplicativo vence; sem ele, vale o do pacote', function (): void {
    $package = customizationDir(['mail/messages/kit-teste.blade.php' => 'corpo do pacote']);
    $application = customizationDir(['mail/messages/kit-teste.blade.php' => 'corpo do aplicativo']);

    PackageViews::register(app(), $package);

    expect(view('mail.messages.kit-teste')->render())->toBe('corpo do pacote');

    app('view')->getFinder()->prependLocation($application);
    app('view')->getFinder()->flush();

    expect(view('mail.messages.kit-teste')->render())->toBe('corpo do aplicativo');
});

it('kit:overrides: cópia igual ao padrão pode sair; personalizado e chave própria são apontados', function (): void {
    $default = require PackageConfig::defaults()['audit'];
    $customized = $default;
    $customized['retention_days'] = 30;

    $base = customizationDir([
        'config/audit.php' => '<?php return '.var_export($default, true).';',
        'config/security.php' => '<?php return '.var_export(['headers' => ['frame_options' => 'SAMEORIGIN'], 'minha_chave' => true], true).';',
        'lang/pt_BR/security.php' => '<?php return '.var_export(require dirname(__DIR__, 3).'/lang/pt_BR/security.php', true).';',
        'lang/pt_BR/audit.php' => '<?php return '.var_export(['minha_chave' => 'Minha'], true).';',
        'lang/pt_BR/tracing.php' => '<?php return '.var_export([...(require dirname(__DIR__, 3).'/lang/pt_BR/tracing.php'), 'minha_chave' => 'Minha'], true).';',
        'resources/views/mail/layouts/kit.blade.php' => 'meu layout',
    ]);

    app()->setBasePath($base);
    app()->useLangPath($base.'/lang');

    Artisan::call('kit:overrides');
    $output = Artisan::output();

    expect($output)->toMatch('#config/audit\.php\s*\.*\s*'.preg_quote(__('kit_overrides.copy'), '#').'#')
        ->toMatch('#config/security\.php\s*\.*\s*.*headers#')
        ->toContain('minha_chave')
        ->toMatch('#lang/pt_BR/security\.php\s*\.*\s*'.preg_quote(__('kit_overrides.copy'), '#').'#')
        // Arquivo só com chaves do aplicativo não sobrescreve nada: nem aparece.
        ->not->toContain('lang/pt_BR/audit.php')
        ->toMatch('#lang/pt_BR/tracing\.php\s*\.*\s*.*'.preg_quote(trans_choice('kit_overrides.same', 2, ['count' => count(Arr::dot(require dirname(__DIR__, 3).'/lang/pt_BR/tracing.php'))]), '#').'#')
        ->toMatch('#resources/views/mail/layouts/kit\.blade\.php\s*\.*\s*'.preg_quote(__('kit_overrides.customized_view'), '#').'#');

    file_put_contents($base.'/config/audit.php', '<?php return '.var_export($customized, true).';');

    Artisan::call('kit:overrides');

    expect(Artisan::output())->toMatch('#config/audit\.php\s*\.*\s*.*retention_days#');
});

it('kit:overrides sem nenhum arquivo do kit no aplicativo: nada a apontar', function (): void {
    $base = customizationDir(['config/.keep' => '', 'lang/.keep' => '', 'resources/views/.keep' => '']);

    app()->setBasePath($base);
    app()->useLangPath($base.'/lang');

    Artisan::call('kit:overrides');

    expect(Artisan::output())->toContain(__('kit_overrides.none'));
});

it('telas React: a página do aplicativo vence; sem ela, o documento raiz pede a do kit', function (): void {
    $base = customizationDir(['resources/js/pages/dashboard.tsx' => 'export default function Dashboard() {}']);

    app()->setBasePath($base);

    expect(ReactScreens::pageEntry('dashboard'))->toBe('resources/js/pages/dashboard.tsx')
        ->and(ReactScreens::pageEntry('auth/login'))->toBe(ReactScreens::PATH.'/pages/auth/login.tsx')
        ->and(ReactScreens::path().'/pages/auth/login.tsx')->toBeFile();
});

it('kit:overrides: tela React igual à do kit pode sair; a personalizada é apontada', function (): void {
    $kit = ReactScreens::path();

    $base = customizationDir([
        'resources/js/pages/auth/login.tsx' => (string) file_get_contents($kit.'/pages/auth/login.tsx'),
        'resources/js/components/ui/button.tsx' => '// meu botão',
        'resources/js/pages/dashboard.tsx' => '// tela do produto: o kit não tem',
    ]);

    app()->setBasePath($base);

    Artisan::call('kit:overrides');

    expect(Artisan::output())
        ->toMatch('#resources/js/pages/auth/login\.tsx\s*\.*\s*'.preg_quote(__('kit_overrides.copy'), '#').'#')
        ->toMatch('#resources/js/components/ui/button\.tsx\s*\.*\s*'.preg_quote(__('kit_overrides.customized_view'), '#').'#')
        ->not->toContain('dashboard.tsx');
});

it('telas Livewire: a pasta do kit entra por último nas views, no view.paths e no layouts::', function (): void {
    $application = customizationDir(['layouts/app.blade.php' => 'layout do aplicativo']);
    app('view')->addNamespace('layouts', $application.'/layouts');

    LivewireScreens::register(app());

    $paths = app('view')->getFinder()->getPaths();

    expect(end($paths))->toBe(LivewireScreens::path())
        ->and(config('view.paths'))->toContain(LivewireScreens::path())
        ->and(app('view')->getFinder()->getHints()['layouts'])->toBe([$application.'/layouts', LivewireScreens::path().'/layouts'])
        ->and(view('layouts::app')->render())->toBe('layout do aplicativo')
        ->and(view()->exists('auth.login'))->toBeTrue();
});
