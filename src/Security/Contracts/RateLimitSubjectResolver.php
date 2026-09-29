<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security\Contracts;

use Illuminate\Http\Request;

/**
 * Quem está sendo contado pelo limite da API quando a requisição foi
 * AUTENTICADA (ver Twstec\Kit\Foundation\Security\ApiRateLimit).
 *
 * Por que um contrato: a regra do limite é de Segurança, mas saber quem é o
 * cliente autenticado é de quem autentica (hoje, o módulo de Tenancy, pelo
 * par de chaves de API). Segurança não conhece esse módulo; ele é que se
 * apresenta, registrando uma implementação no container. Sem nenhuma
 * registrada, toda requisição conta pelo IP — o lado estreito.
 */
interface RateLimitSubjectResolver
{
    /**
     * Identificador do sujeito autenticado, já com o prefixo do espaço de
     * nomes (ex.: `key:<uuid>`), ou null quando a requisição não foi
     * autenticada — aí o limite conta pelo IP (`ip:…`).
     *
     * O prefixo não pode ser `ip:`: é ele que impede um IP de colidir com o
     * identificador de uma credencial.
     */
    public function resolve(Request $request): ?string;
}
