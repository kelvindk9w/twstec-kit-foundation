<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Contracts;

use Illuminate\Http\Request;

/**
 * DE QUEM é uma chave de idempotência: a mesma `Idempotency-Key` vinda de
 * dois escopos diferentes são duas chaves diferentes, e nenhum escopo enxerga
 * a resposta guardada do outro.
 *
 * Por que um contrato: a regra da idempotência é da base, mas saber quem é o
 * cliente (a conta, a chave de API, a pessoa logada) é de quem autentica. O
 * módulo de contas (twstec/kit-accounts) se apresenta registrando uma
 * implementação no container — conta + chave de API, ou conta + pessoa na
 * sessão. Sem nenhuma registrada, vale a pessoa autenticada
 * (`$request->user()`). Ver Idempotency\IdempotencyScope.
 *
 * FALHA FECHADA: devolver null significa "não sei quem é" — e a requisição com
 * chave é RECUSADA, sem executar. Nunca devolva um escopo largo (o IP, uma
 * constante) para "fazer funcionar": dois clientes no mesmo escopo receberiam
 * a resposta um do outro.
 */
interface IdempotencyScopeResolver
{
    /**
     * Identificador estável do escopo (ex.: `account:<uuid>|key:<uuid>`), ou
     * null quando a requisição não tem quem a identifique.
     */
    public function resolve(Request $request): ?string;
}
