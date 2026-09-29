<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Http\Middleware\TrustHosts;
use Twstec\Kit\Foundation\Http\Middleware\TrustProxies;
use Twstec\Kit\Foundation\Logging\Middleware\RequestLogging;
use Twstec\Kit\Foundation\Security\Middleware\EdgeRateLimit;
use Twstec\Kit\Foundation\Security\Middleware\SecurityHeaders;
use Twstec\Kit\Foundation\Security\Middleware\SecurityValidation;

// A pilha global de segurança que o pacote instala. A ordem é a regra (ver o
// docblock de FoundationServiceProvider::GLOBAL_MIDDLEWARE); a lista aqui é
// escrita À MÃO, e não lida da constante, de propósito: tirar ou trocar de
// lugar um middleware no provider tem de reprovar este teste.

// A requisição de prova passa pela trilha (request_logs), que é uma tabela
// das migrations do pacote.
uses(RefreshDatabase::class);

it('põe a pilha de segurança na frente de toda a pilha global, nesta ordem', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect(array_slice($global, 0, 6))->toBe([
        TrustProxies::class,
        SecurityHeaders::class,
        EdgeRateLimit::class,
        TrustHosts::class,
        SecurityValidation::class,
        RequestLogging::class,
    ]);
});

it('tira o TrustProxies do framework e não repete nenhum middleware', function (): void {
    $global = app(Kernel::class)->getGlobalMiddleware();

    expect($global)->not->toContain(FrameworkTrustProxies::class)
        ->and($global)->toBe(array_values(array_unique($global)));
});

it('registra os aliases de uso explícito em rotas', function (): void {
    $aliases = app(Kernel::class)->getMiddlewareAliases();

    expect($aliases['security.validation'] ?? null)->toBe(SecurityValidation::class)
        ->and($aliases['security.headers'] ?? null)->toBe(SecurityHeaders::class)
        ->and($aliases['request.logging'] ?? null)->toBe(RequestLogging::class);
});

it('responde com os cabeçalhos de segurança e recusa host forjado', function (): void {
    Route::get('/_foundation-probe', fn () => 'ok');

    $this->get('http://localhost/_foundation-probe')
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');

    $this->get('http://evil.example/_foundation-probe')->assertStatus(400);
});
