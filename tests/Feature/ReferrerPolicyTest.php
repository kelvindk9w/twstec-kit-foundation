<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Security\Middleware\RestrictReferrerPolicy;
use Twstec\Kit\Foundation\Security\ReferrerPolicy;

// =============================================================================
// REFERRER-POLICY POR ROTA: a rota pode pedir uma política MAIS RESTRITA que a
// padrão (`referrer.policy:no-referrer`, ou o cabeçalho da própria resposta);
// nunca uma mais frouxa — o SecurityHeaders a troca pela padrão.
// =============================================================================

uses(RefreshDatabase::class);

it('a rota pede no-referrer e recebe; as outras seguem com a padrão', function (): void {
    Route::get('/_pagar', fn () => 'ok')->middleware('referrer.policy:no-referrer');
    Route::get('/_comum', fn () => 'ok');

    $this->get('http://localhost/_pagar')->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
    $this->get('http://localhost/_comum')->assertOk()->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect(app(Kernel::class)->getMiddlewareAliases()[RestrictReferrerPolicy::ALIAS] ?? null)->toBe(RestrictReferrerPolicy::class);
});

it('política mais frouxa, não comparável ou desconhecida posta pela resposta: vale a padrão', function (string $pedida): void {
    Route::get('/_frouxa', fn () => response('ok')->header('Referrer-Policy', $pedida));

    $this->get('http://localhost/_frouxa')->assertOk()->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
})->with(['unsafe-url', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin', 'qualquer-coisa', 'no-referrer, unsafe-url']);

it('com a padrão mais restrita, a rota não volta a uma mais frouxa', function (): void {
    config(['security.headers.referrer_policy' => 'same-origin']);
    Route::get('/_restrita', fn () => 'ok')->middleware('referrer.policy:strict-origin');
    Route::get('/_nenhuma', fn () => 'ok')->middleware('referrer.policy:no-referrer');

    // strict-origin manda a origem para outra origem: same-origin não manda nada.
    $this->get('http://localhost/_restrita')->assertHeader('Referrer-Policy', 'same-origin');
    $this->get('http://localhost/_nenhuma')->assertHeader('Referrer-Policy', 'no-referrer');
});

it('a comparação: cada política só substitui outra se não mandar mais em nenhum caso', function (string $candidata, string $base, bool $esperado): void {
    expect(ReferrerPolicy::isAtLeastAsStrictAs($candidata, $base))->toBe($esperado);
})->with([
    ['no-referrer', 'unsafe-url', true],
    ['no-referrer', 'strict-origin-when-cross-origin', true],
    ['same-origin', 'strict-origin-when-cross-origin', true],
    ['strict-origin', 'strict-origin-when-cross-origin', true],
    ['STRICT-ORIGIN', 'strict-origin-when-cross-origin', true],
    ['strict-origin-when-cross-origin', 'strict-origin-when-cross-origin', true],
    ['origin', 'strict-origin-when-cross-origin', false],
    ['unsafe-url', 'no-referrer', false],
    ['same-origin', 'strict-origin', false],
    ['strict-origin', 'same-origin', false],
    ['inventada', 'unsafe-url', false],
]);

it('política desconhecida no middleware da rota é erro de programação', function (): void {
    $this->withoutExceptionHandling();
    Route::get('/_errada', fn () => 'ok')->middleware('referrer.policy:sem-referer');

    $this->get('http://localhost/_errada');
})->throws(InvalidArgumentException::class, 'sem-referer');
