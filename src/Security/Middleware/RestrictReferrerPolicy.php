<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security\Middleware;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Foundation\Security\ReferrerPolicy;

/**
 * `referrer.policy:<política>` — a rota pede uma `Referrer-Policy` MAIS
 * RESTRITA que a padrão, por exemplo:
 *
 *     Route::get('/pagar/{token}', ...)->middleware('referrer.policy:no-referrer');
 *
 * Só restringe: o SecurityHeaders confere a política pedida contra a padrão
 * (`security.headers.referrer_policy`) e, se ela for mais frouxa (ou não for
 * comparável), mantém a padrão. Valor que não é política é erro de
 * programação e para a requisição na hora.
 */
final class RestrictReferrerPolicy
{
    public const ALIAS = 'referrer.policy';

    public function handle(Request $request, Closure $next, string $policy = 'no-referrer'): Response
    {
        if (! ReferrerPolicy::isValid($policy)) {
            throw new InvalidArgumentException("Referrer-Policy desconhecida na rota: {$policy}.");
        }

        $response = $next($request);
        $response->headers->set(ReferrerPolicy::HEADER, (string) ReferrerPolicy::normalize($policy));

        return $response;
    }
}
