<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Security\AttackDetector;
use Twstec\Kit\Foundation\Security\Contracts\RateLimitSubjectResolver;
use Twstec\Kit\Foundation\Support\Platform;

// Nomes antigos (App\Core\<Módulo>\…, da 1.x) continuam resolvendo para as
// classes do pacote — é o que protege payload de fila serializado antes da
// atualização e config publicada que ainda usa o nome antigo.

it('resolve o nome antigo de classe, interface e enum para a classe nova', function (): void {
    expect(class_exists('App\\Core\\Security\\AttackDetector'))->toBeTrue()
        ->and((new ReflectionClass('App\\Core\\Security\\AttackDetector'))->getName())->toBe(AttackDetector::class)
        ->and(interface_exists('App\\Core\\Security\\Contracts\\RateLimitSubjectResolver'))->toBeTrue()
        ->and((new ReflectionClass('App\\Core\\Security\\Contracts\\RateLimitSubjectResolver'))->getName())->toBe(RateLimitSubjectResolver::class)
        ->and(enum_exists('App\\Core\\Logging\\Enums\\RequestLogStatus'))->toBeTrue()
        ->and(constant('App\\Core\\Logging\\Enums\\RequestLogStatus::Concluida'))->toBe(RequestLogStatus::Concluida);
});

it('o objeto serializado com o nome antigo volta como a classe nova', function (): void {
    $plataforma = app(Platform::class);
    $novo = serialize($plataforma);
    $antigo = str_replace(
        sprintf('O:%d:"%s"', strlen(Platform::class), Platform::class),
        sprintf('O:%d:"%s"', strlen('App\\Core\\Support\\Platform'), 'App\\Core\\Support\\Platform'),
        $novo,
    );

    expect($antigo)->not->toBe($novo);

    $objeto = unserialize($antigo);

    expect($objeto)->toBeInstanceOf(Platform::class)
        ->and($objeto->name)->toBe($plataforma->name);
});

it('não inventa apelido fora dos módulos da base', function (): void {
    expect(class_exists('App\\Core\\Auth\\Models\\User'))->toBeFalse()
        ->and(class_exists('App\\Core\\Security\\NaoExiste'))->toBeFalse()
        ->and(class_exists('App\\Core\\Compat\\Qualquer'))->toBeFalse();
});

it('cobre exatamente os módulos do pacote', function (): void {
    $modules = [];

    foreach ((new Finder)->directories()->in(dirname(__DIR__, 2).'/src')->depth(0) as $directory) {
        if ($directory->getFilename() !== 'Compat') {
            $modules[] = $directory->getFilename();
        }
    }

    preg_match('/\$modules = \[(.*?)\];/s', (string) file_get_contents(dirname(__DIR__, 2).'/src/Compat/legacy-aliases.php'), $match);
    preg_match_all("/'([A-Za-z]+)'/", $match[1] ?? '', $aliased);

    sort($modules);
    $aliasedModules = $aliased[1];
    sort($aliasedModules);

    expect($aliasedModules)->toBe($modules);
});
