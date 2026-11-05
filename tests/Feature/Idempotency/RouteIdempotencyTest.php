<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Twstec\Kit\Foundation\Idempotency\Middleware\HandleIdempotencyKey;
use Twstec\Kit\Foundation\Idempotency\RouteIdempotency;

// =============================================================================
// O MODO DE IDEMPOTÊNCIA DE CADA ROTA, para as travas de arquitetura do
// projeto (RouteIdempotency::of / ::missing).
// =============================================================================

beforeEach(function (): void {
    $ok = fn () => response()->noContent();

    Route::middlewareGroup('financeiro', ['idempotent:required,transactional']);

    Route::post('api/v1/pagamentos', $ok)->middleware('idempotent:required,transactional');
    Route::patch('api/v1/pagamentos/{id}', $ok)->middleware(HandleIdempotencyKey::using(required: true, transactional: true));
    Route::post('api/v1/pagamentos/{id}/estornos', $ok)->middleware('financeiro');
    Route::post('api/v1/pagamentos/lote', $ok)->middleware('idempotent:required');
    Route::post('api/v1/pagamentos/rascunho', $ok)->middleware('idempotent');
    Route::post('api/v1/pagamentos/sem-chave', $ok);
    Route::get('api/v1/pagamentos', $ok);
    Route::delete('api/v1/pagamentos/{id}', $ok);
    Route::post('api/v1/pagamentos-avulsos', $ok);
    Route::post('api/v1/outros', $ok);
});

function idemRoute(string $method, string $uri): Illuminate\Routing\Route
{
    $route = Route::getRoutes()->getRoutesByMethod()[$method][$uri] ?? null;

    expect($route)->not->toBeNull();

    return $route;
}

it('lê o modo declarado pelo alias, pela string de using() e pelo grupo de middleware', function (): void {
    $alias = RouteIdempotency::of(idemRoute('POST', 'api/v1/pagamentos'));
    $using = RouteIdempotency::of(idemRoute('PATCH', 'api/v1/pagamentos/{id}'));
    $group = RouteIdempotency::of(idemRoute('POST', 'api/v1/pagamentos/{id}/estornos'));
    $optional = RouteIdempotency::of(idemRoute('POST', 'api/v1/pagamentos/rascunho'));

    expect([$alias?->required, $alias?->transactional])->toBe([true, true])
        ->and([$using?->required, $using?->transactional])->toBe([true, true])
        ->and([$group?->required, $group?->transactional])->toBe([true, true])
        ->and([$optional?->required, $optional?->transactional])->toBe([false, false])
        ->and(RouteIdempotency::of(idemRoute('POST', 'api/v1/pagamentos/sem-chave')))->toBeNull();
});

it('aponta cada escrita sob o prefixo que não cumpre o modo pedido, com o que falta', function (): void {
    expect(RouteIdempotency::missing('api/v1/pagamentos', transactional: true))->toBe([
        'POST api/v1/pagamentos/lote: falta transactional',
        'POST api/v1/pagamentos/rascunho: falta required, falta transactional',
        'POST api/v1/pagamentos/sem-chave: sem o middleware idempotent',
    ]);
});

it('só exige o que foi pedido: sem transactional, a rota required basta', function (): void {
    expect(RouteIdempotency::missing('/api/v1/pagamentos/', except: ['POST api/v1/pagamentos/sem-chave']))->toBe([
        'POST api/v1/pagamentos/rascunho: falta required',
    ]);
});

it('o prefixo respeita a fronteira do caminho e as leituras ficam de fora', function (): void {
    $problems = RouteIdempotency::missing('api/v1/pagamentos', transactional: true);

    expect(implode("\n", $problems))
        ->not->toContain('pagamentos-avulsos')
        ->not->toContain('api/v1/outros')
        ->not->toContain('GET ')
        ->not->toContain('DELETE ');
});

it('segue os métodos configurados em idempotency.methods', function (): void {
    config()->set('idempotency.methods', ['POST', 'PATCH', 'DELETE']);

    expect(RouteIdempotency::missing('api/v1/pagamentos', except: ['POST api/v1/pagamentos/sem-chave', 'POST api/v1/pagamentos/rascunho']))
        ->toBe(['DELETE api/v1/pagamentos/{id}: sem o middleware idempotent']);
});

it('parâmetro inválido na rota é erro, não "sem idempotência"', function (): void {
    Route::post('api/v1/pagamentos/errado', fn () => response()->noContent())->middleware('idempotent:obrigatorio');

    RouteIdempotency::missing('api/v1/pagamentos');
})->throws(InvalidArgumentException::class, 'obrigatorio');

it('rota que tira o middleware do grupo (withoutMiddleware) conta como sem idempotência', function (): void {
    Route::post('api/v1/pagamentos/isenta', fn () => response()->noContent())
        ->middleware('financeiro')
        ->withoutMiddleware('idempotent');

    expect(RouteIdempotency::of(idemRoute('POST', 'api/v1/pagamentos/isenta')))->toBeNull()
        ->and(RouteIdempotency::missing('api/v1/pagamentos/isenta'))
        ->toBe(['POST api/v1/pagamentos/isenta: sem o middleware idempotent']);
});
