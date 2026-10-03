<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Idempotency\Contracts\IdempotencyScopeResolver;

/**
 * DE QUEM é a chave desta requisição (ver Contracts\IdempotencyScopeResolver).
 *
 * 1. Há um resolvedor registrado (o do módulo de contas, ou o do aplicativo):
 *    vale a resposta dele. Null = sem escopo = recusa.
 * 2. Não há: vale a pessoa autenticada — `user:<classe>:<id>` (a classe
 *    separa dois provedores de usuário com ids iguais). Sem ninguém
 *    autenticado = sem escopo = recusa.
 *
 * O escopo nunca é o IP nem uma constante: dois clientes no mesmo escopo
 * receberiam a resposta guardada um do outro.
 */
final class IdempotencyScope
{
    public static function resolve(Request $request): ?string
    {
        if (app()->bound(IdempotencyScopeResolver::class)) {
            $scope = app(IdempotencyScopeResolver::class)->resolve($request);

            return is_string($scope) && $scope !== '' ? $scope : null;
        }

        $user = $request->user();

        if (! $user instanceof Authenticatable) {
            return null;
        }

        $id = $user->getAuthIdentifier();

        if (! is_scalar($id) || (string) $id === '') {
            return null;
        }

        return 'user:'.$user::class.':'.$id;
    }

    /**
     * SHA-256 do escopo — o que o banco guarda.
     */
    public static function hash(string $scope): string
    {
        return hash('sha256', $scope);
    }
}
