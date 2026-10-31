<?php

declare(strict_types=1);

use Twstec\Kit\Foundation\Support\Platform;

// =============================================================================
// Os PADRÕES de config/platform.php não carregam a identidade do kit: sem as
// variáveis PLATFORM_*, o nome cai no APP_NAME, a empresa no nome, a URL na
// APP_URL, a versão é a inicial do produto e os idiomas são só o padrão (sem
// seletor). O arquivo é avaliado de novo com o ambiente de cada caso — o
// mesmo arquivo que o pacote mescla e que os starters copiam.
// =============================================================================

/**
 * @param  array<string, string|null>  $environment  null = variável ausente
 * @return array<string, mixed>
 */
function platformConfigWith(array $environment): array
{
    // Todas as PLATFORM_* do processo (o container de dev injeta as do .env)
    // e as APP_* que a config lê.
    $keys = array_values(array_unique(array_merge(
        ['APP_NAME', 'APP_URL'],
        array_keys($environment),
        array_filter(
            array_merge(array_keys(getenv()), array_keys($_ENV), array_keys($_SERVER)),
            static fn (int|string $key): bool => str_starts_with((string) $key, 'PLATFORM_'),
        ),
    )));
    $previous = [];

    foreach ($keys as $key) {
        $previous[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        if (array_key_exists($key, $environment) && $environment[$key] !== null) {
            $_ENV[$key] = $_SERVER[$key] = $environment[$key];
            putenv("{$key}={$environment[$key]}");
        }
    }

    try {
        return require dirname(__DIR__, 2).'/config/platform.php';
    } finally {
        foreach ($previous as $key => [$env, $server, $get]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($get !== false) {
                putenv("{$key}={$get}");
            }
        }
    }
}

it('sem PLATFORM_*, nada do kit: nome do APP_NAME, empresa = nome, URL da APP_URL, versão do produto, um idioma', function (): void {
    $config = platformConfigWith(['APP_NAME' => 'acme', 'APP_URL' => 'http://acme.localhost:8081']);

    expect($config['name'])->toBe('acme')
        ->and($config['company_name'])->toBe('acme')
        ->and($config['official_url'])->toBe('http://acme.localhost:8081')
        ->and($config['version'])->toBe('0.1.0')
        ->and($config['repo_url'])->toBeNull()
        ->and($config['locale'])->toBe('pt_BR')
        ->and($config['available_locales'])->toBe(['pt_BR'])
        ->and(json_encode($config))->not->toContain('TWS')
        ->not->toContain('Starter')
        ->not->toContain('8180');
});

it('variável vazia vale como ausente (a linha "PLATFORM_COMPANY_NAME=" do .env)', function (): void {
    $config = platformConfigWith([
        'APP_NAME' => 'acme',
        'PLATFORM_NAME' => 'Acme Pagamentos',
        'PLATFORM_COMPANY_NAME' => '',
        'PLATFORM_VERSION' => '',
        'PLATFORM_LOCALE' => 'en',
        'PLATFORM_AVAILABLE_LOCALES' => '',
    ]);

    expect($config['name'])->toBe('Acme Pagamentos')
        ->and($config['company_name'])->toBe('Acme Pagamentos')
        ->and($config['version'])->toBe('0.1.0')
        ->and($config['available_locales'])->toBe(['en']);
});

it('com PLATFORM_* definidas, elas vencem', function (): void {
    $config = platformConfigWith([
        'APP_NAME' => 'acme',
        'PLATFORM_NAME' => 'Acme',
        'PLATFORM_COMPANY_NAME' => 'Acme Ltda',
        'PLATFORM_VERSION' => '1.2.3',
        'PLATFORM_OFFICIAL_URL' => 'https://acme.example',
        'PLATFORM_AVAILABLE_LOCALES' => 'pt_BR,en',
    ]);

    expect($config['name'])->toBe('Acme')
        ->and($config['company_name'])->toBe('Acme Ltda')
        ->and($config['version'])->toBe('1.2.3')
        ->and($config['official_url'])->toBe('https://acme.example')
        ->and($config['available_locales'])->toBe(['pt_BR', 'en']);
});

it('só há escolha de idioma com mais de um idioma disponível', function (): void {
    config(['platform.available_locales' => ['pt_BR']]);
    app()->forgetInstance(Platform::class);
    expect(platform()->offersLocaleChoice())->toBeFalse();

    config(['platform.available_locales' => ['pt_BR', 'en']]);
    app()->forgetInstance(Platform::class);
    expect(platform()->offersLocaleChoice())->toBeTrue();
});
