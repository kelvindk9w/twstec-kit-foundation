<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Routing\MiddlewareNameResolver;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Twstec\Kit\Foundation\Idempotency\Middleware\HandleIdempotencyKey;

/**
 * O modo de idempotência de cada rota, lido da declaração do middleware —
 * para as travas de arquitetura do projeto ("toda escrita sob tal prefixo
 * exige `idempotent:required,transactional`"):
 *
 *     expect(RouteIdempotency::missing('api/v1/pagamentos', transactional: true))->toBe([]);
 *
 * A leitura passa pelo roteador (`gatherRouteMiddleware`): vale o alias
 * (`idempotent:…`), a string de HandleIdempotencyKey::using() e o middleware
 * vindo de um grupo. Parâmetro inválido na rota é erro (o mesmo do
 * middleware), não "sem idempotência".
 */
final class RouteIdempotency
{
    /**
     * As opções do middleware na rota, ou null se ela não o declara.
     */
    public static function of(Route $route): ?IdempotencyOptions
    {
        foreach (self::middlewareOf($route) as $middleware) {
            [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

            if ($name !== HandleIdempotencyKey::class) {
                continue;
            }

            return IdempotencyOptions::parse($parameters === '' ? [] : explode(',', $parameters));
        }

        return null;
    }

    /**
     * As rotas de escrita sob o prefixo que não cumprem o modo pedido, como
     * "POST api/v1/pagamentos: <o que falta>". Lista vazia = todas cumprem.
     *
     * - prefixo: o caminho da rota é ele ou começa por ele seguido de "/"
     *   ("api/v1/pag" não pega "api/v1/pagamentos");
     * - escrita: os métodos em que o middleware lê a chave
     *   (`idempotency.methods`, padrão POST e PATCH) — PUT e DELETE não passam
     *   por ele;
     * - `$except`: rotas fora da trava, como "POST api/v1/webhooks/recebidos"
     *   (deixe o motivo ao lado, no teste).
     *
     * @param  list<string>  $except
     * @return list<string>
     */
    public static function missing(string $prefix, bool $required = true, bool $transactional = false, array $except = []): array
    {
        $prefix = trim($prefix, '/');
        $writeMethods = HandleIdempotencyKey::methods();
        $problems = [];

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $uri = trim($route->uri(), '/');

            if ($uri !== $prefix && ! str_starts_with($uri, $prefix.'/')) {
                continue;
            }

            $routeMethods = array_filter($route->methods(), is_string(...));
            $methods = array_values(array_intersect($routeMethods, $writeMethods));

            if ($methods === []) {
                continue;
            }

            $options = self::of($route);
            $absent = $options === null ? ['sem o middleware idempotent'] : array_keys(array_filter([
                'falta required' => $required && ! $options->required,
                'falta transactional' => $transactional && ! $options->transactional,
            ]));

            if ($absent === []) {
                continue;
            }

            $missing = implode(', ', $absent);

            foreach ($methods as $method) {
                if (! in_array($method.' '.$uri, $except, true)) {
                    $problems[] = $method.' '.$uri.': '.$missing;
                }
            }
        }

        sort($problems);

        return $problems;
    }

    /**
     * O middleware da rota resolvido em classes (com os parâmetros), como o
     * roteador faria ao atender: aliases e grupos do kernel HTTP e do
     * roteador — o kernel só os copia para o roteador ao atender a primeira
     * requisição — e sem o que a rota exclui (`withoutMiddleware`).
     *
     * @return list<string>
     */
    private static function middlewareOf(Route $route): array
    {
        $router = app(Router::class);
        /** @var HttpKernel $kernel */
        $kernel = app(HttpKernelContract::class);
        $aliases = [...$kernel->getMiddlewareAliases(), ...$router->getMiddleware()];
        $groups = [...$kernel->getMiddlewareGroups(), ...$router->getMiddlewareGroups()];

        /** @param array<mixed> $names */
        $resolve = static fn (array $names): array => array_values(array_filter(
            Arr::flatten(array_map(
                static fn (Closure|string $name): array => (array) MiddlewareNameResolver::resolve($name, $aliases, $groups),
                array_filter($names, static fn (mixed $name): bool => is_string($name) || $name instanceof Closure),
            )),
            is_string(...),
        ));

        $excluded = array_map(
            static fn (string $name): string => explode(':', $name, 2)[0],
            $resolve($route->excludedMiddleware()),
        );

        return array_values(array_filter(
            $resolve($route->gatherMiddleware()),
            static fn (string $name): bool => ! in_array(explode(':', $name, 2)[0], $excluded, true),
        ));
    }
}
