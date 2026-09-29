<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Twstec\Kit\Foundation\FoundationServiceProvider;

// =============================================================================
// O APLICATIVO VENCE O PACOTE NAS TRADUÇÕES.
//
// Quem usa o kit troca qualquer texto editando o lang/ do próprio aplicativo:
// numa mesma chave vale o texto do aplicativo; o pacote só preenche o que o
// aplicativo não definiu — em qualquer grupo, nos três idiomas e no idioma de
// reserva. As chaves são as de sempre, sem namespace.
// =============================================================================

/**
 * Troca a pasta lang/ do aplicativo por uma temporária com estes arquivos e
 * refaz o carregador de traduções (como num boot novo).
 *
 * @param  array<string, array<string, mixed>>  $files  'pt_BR/mail.php' => conteúdo
 */
function appLang(array $files): string
{
    $dir = sys_get_temp_dir().'/foundation-app-lang-'.uniqid();

    foreach ($files as $relative => $contents) {
        @mkdir(dirname($dir.'/'.$relative), 0755, true);
        file_put_contents($dir.'/'.$relative, '<?php return '.var_export($contents, true).';');
    }

    app()->useLangPath($dir);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    return $dir;
}

function packageLang(string $relative): array
{
    return require dirname(__DIR__, 2).'/lang/'.$relative;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/foundation-app-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) chave só no pacote: texto do pacote', function (string $locale): void {
    appLang(["{$locale}/outro.php" => ['x' => 'y']]);

    expect(__('security.blocked', [], $locale))->toBe(packageLang("{$locale}/security.php")['blocked'])
        ->and(__('mail.footer.rights', ['year' => 2026, 'company' => 'ACME'], $locale))
        ->toBe(str_replace([':year', ':company'], ['2026', 'ACME'], packageLang("{$locale}/mail.php")['footer']['rights']));
})->with(['pt_BR', 'en', 'es']);

it('(b) mesma chave no aplicativo: texto do aplicativo', function (string $locale): void {
    appLang([
        "{$locale}/mail.php" => ['footer' => ['transactional' => "Rodapé do app ({$locale})"]],
        "{$locale}/security.php" => ['blocked' => "Bloqueado pelo app ({$locale})"],
        "{$locale}/api.php" => ['errors' => ['server_error' => "Erro do app ({$locale})"]],
        "{$locale}/audit.php" => ['pruned' => "Podado pelo app ({$locale})"],
    ]);

    expect(__('mail.footer.transactional', [], $locale))->toBe("Rodapé do app ({$locale})")
        ->and(__('security.blocked', [], $locale))->toBe("Bloqueado pelo app ({$locale})")
        ->and(__('api.errors.server_error', [], $locale))->toBe("Erro do app ({$locale})")
        ->and(__('audit.pruned', [], $locale))->toBe("Podado pelo app ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) grupo repartido: cada chave resolve do lado certo', function (string $locale): void {
    appLang([
        "{$locale}/mail.php" => [
            'footer' => ['cnpj' => "CNPJ do app ({$locale})"],
            'meu_email' => ['subject' => "Assunto do app ({$locale})"],
        ],
    ]);

    $package = packageLang("{$locale}/mail.php")['footer'];

    expect(__('mail.footer.cnpj', [], $locale))->toBe("CNPJ do app ({$locale})")
        ->and(__('mail.footer.transactional', [], $locale))->toBe($package['transactional'])
        ->and(__('mail.meu_email.subject', [], $locale))->toBe("Assunto do app ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('no idioma de reserva o aplicativo também vence, e o pacote preenche o idioma pedido', function (): void {
    appLang(['pt_BR/security.php' => ['blocked' => 'Bloqueado pelo app (reserva)']]);

    app('translator')->setFallback('pt_BR');

    // Idioma sem arquivo em lugar nenhum: cai na reserva, onde vale o app.
    expect(__('security.blocked', [], 'fr'))->toBe('Bloqueado pelo app (reserva)')
        // Idioma que só o pacote tem: o pacote preenche antes da reserva.
        ->and(__('security.blocked', [], 'en'))->toBe(packageLang('en/security.php')['blocked']);
});

it('põe a pasta do pacote logo antes da do aplicativo no carregador', function (): void {
    $dir = appLang(['pt_BR/outro.php' => ['x' => 'y']]);

    $paths = app('translation.loader')->paths();
    $package = dirname(__DIR__, 2).'/lang';

    expect(array_search($package, $paths, true))->toBe(array_search($dir, $paths, true) - 1)
        ->and(FoundationServiceProvider::packageBeforeApplication(['/fw', '/app'], '/pkg', '/app'))->toBe(['/fw', '/pkg', '/app'])
        ->and(FoundationServiceProvider::packageBeforeApplication(['/fw', '/pkg', '/app'], '/pkg', '/app'))->toBe(['/fw', '/pkg', '/app'])
        ->and(FoundationServiceProvider::packageBeforeApplication(['/fw'], '/pkg', '/app'))->toBe(['/fw', '/pkg']);
});
