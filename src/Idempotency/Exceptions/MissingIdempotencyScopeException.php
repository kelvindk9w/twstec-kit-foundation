<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Exceptions;

use LogicException;

/**
 * A requisição trouxe `Idempotency-Key` (ou a rota a exige), mas ninguém
 * soube dizer DE QUEM ela é — rota sem autenticação, ou um resolvedor que
 * devolveu null. FALHA FECHADA: a requisição não executa e sai como erro de
 * servidor (500 genérico, detalhe só no log). É erro de montagem da rota, não
 * do cliente: ponha a autenticação antes do `idempotent`, ou registre um
 * IdempotencyScopeResolver que identifique o cliente.
 */
final class MissingIdempotencyScopeException extends LogicException
{
    public static function forRoute(string $route): self
    {
        return new self(sprintf(
            'Idempotency-Key recebida em "%s", mas a requisição não tem escopo (nenhuma conta, credencial ou pessoa identificada). A rota precisa de autenticação antes do middleware `idempotent` — ver docs/api.md, "Idempotência".',
            $route,
        ));
    }
}
