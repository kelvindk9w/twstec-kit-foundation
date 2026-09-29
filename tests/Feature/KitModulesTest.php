<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Twstec\Kit\Foundation\Kit;

// A detecção dos módulos do kit (Kit::has) — o ponto único que decide se uma
// tela, rota ou menu de módulo opcional existe. Aqui, a aplicação mínima do
// Testbench só tem o foundation: é o caso "nenhum módulo opcional".

afterEach(function (): void {
    Kit::flushFakes();
});

it('reconhece o próprio foundation e nenhum módulo que não foi instalado', function (): void {
    expect(Kit::has('foundation'))->toBeTrue()
        ->and(Kit::has('auth'))->toBeFalse()
        ->and(Kit::has('accounts'))->toBeFalse()
        ->and(Kit::has('uploads'))->toBeFalse()
        ->and(Kit::has('admin'))->toBeFalse()
        ->and(Kit::installed())->toBe(['foundation'])
        ->and(Kit::installedOptional())->toBe([]);
});

it('conhece os cinco módulos: dois obrigatórios e três opcionais, cada um com o pacote dele', function (): void {
    expect(array_keys(Kit::MODULES))->toBe(['foundation', 'auth', 'accounts', 'uploads', 'admin'])
        ->and(Kit::REQUIRED)->toBe(['foundation', 'auth'])
        ->and(Kit::OPTIONAL)->toBe(['accounts', 'uploads', 'admin'])
        ->and(Kit::package('uploads'))->toBe('twstec/kit-uploads');
});

it('recusa módulo desconhecido em vez de responder "não instalado"', function (): void {
    // Um erro de digitação ("upload") não pode esconder uma tela em silêncio.
    expect(fn () => Kit::has('upload'))->toThrow(InvalidArgumentException::class);
});

it('uploads exige accounts; accounts e admin não exigem outro opcional', function (): void {
    expect(Kit::missingDependencies(['uploads']))->toBe(['uploads' => ['accounts']])
        ->and(Kit::missingDependencies(['accounts', 'uploads']))->toBe([])
        ->and(Kit::missingDependencies(['admin']))->toBe([])
        ->and(Kit::missingDependencies([]))->toBe([]);
});

it('na suíte, finge um módulo ausente e desfaz — mas nunca um obrigatório', function (): void {
    Kit::pretendAbsent('accounts');

    expect(Kit::has('accounts'))->toBeFalse()
        ->and(Kit::has('foundation'))->toBeTrue()
        ->and(fn () => Kit::pretendAbsent('foundation'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Kit::pretendAbsent('auth'))->toThrow(InvalidArgumentException::class);

    Kit::flushFakes();

    expect(Kit::has('foundation'))->toBeTrue();
});

it('a diretiva @kit das views mostra o bloco só com o módulo instalado', function (): void {
    $view = "@kit('foundation') com-base @endkit | @kit('admin') com-admin @else sem-admin @endkit";

    expect(preg_split('/\s+/', trim(Blade::render($view))))->toBe(['com-base', '|', 'sem-admin']);
});
