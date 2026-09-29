<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Localization\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve o locale da requisição, nesta ordem:
 *
 * 1. Usuário autenticado → preferência salva na conta (users.locale);
 * 2. Visitante → cookie `locale` (gravado pela rota locale.switch);
 * 3. Fallback → padrão da plataforma (platform()->locale, default pt-BR).
 *
 * Apenas valores da whitelist platform.available_locales são aceitos —
 * qualquer outro cai no fallback (o cookie é de terceiros: nunca confiar).
 */
final class SetLocale
{
    public const COOKIE = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $available = platform()->availableLocales;

        $candidates = [
            $request->user()?->locale,
            $request->cookie(self::COOKIE),
            platform()->locale,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return (string) config('app.fallback_locale', 'pt_BR');
    }
}
