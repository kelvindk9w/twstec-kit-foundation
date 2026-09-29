<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers HTTP de segurança (OWASP Secure Headers).
 *
 * Aplicados na camada da aplicação (defesa em profundidade: o nginx de
 * produção também envia os principais). Valores via config/security.php,
 * ajustáveis por .env.
 *
 * HSTS só é enviado sob HTTPS e quando habilitado (padrão: produção) —
 * enviar HSTS em HTTP é inócuo, mas habilitar em dev atrapalha.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('security.headers.enabled', true)) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', (string) config('security.headers.frame_options', 'DENY'));
        $response->headers->set('Referrer-Policy', (string) config('security.headers.referrer_policy', 'strict-origin-when-cross-origin'));
        $response->headers->set('Permissions-Policy', (string) config('security.headers.permissions_policy', 'camera=(), microphone=(), geolocation=()'));

        $csp = config('security.headers.content_security_policy');

        // Super admin (/admin — Filament): 'unsafe-eval' é exigido pelo
        // Alpine do Filament 5 (ver config/security.php →
        // content_security_policy_admin, decisão documentada). Somente nas
        // rotas da superfície `admin` (security.headers.surfaces, padrão
        // `admin*`); o resto da aplicação segue com a CSP estrita.
        if (self::onSurface($request, 'admin')) {
            $adminCsp = config('security.headers.content_security_policy_admin');

            if (is_string($adminCsp) && $adminCsp !== '') {
                $csp = $adminCsp;
            } elseif (is_string($csp) && ! str_contains($csp, 'unsafe-eval')) {
                $csp = (string) preg_replace('/script-src /', "script-src 'unsafe-eval' ", (string) $csp, 1);
            }
        }

        // Horizon (/horizon): o dashboard é uma SPA Vue com template
        // in-DOM (precisa de 'unsafe-eval' no script-src) e carrega fontes do
        // fonts.bunny.net (style-src/font-src). Somente nas rotas do Horizon
        // (restritas a is_admin + IP allowlist); o resto segue estrito.
        // CSP própria configurável por SECURITY_CSP_HORIZON (vazio = deriva
        // da CSP base, como acima). Caminhos: superfície `horizon`.
        if (self::onSurface($request, 'horizon')) {
            $horizonCsp = config('security.headers.content_security_policy_horizon');

            if (is_string($horizonCsp) && $horizonCsp !== '') {
                $csp = $horizonCsp;
            } elseif (is_string($csp) && $csp !== '') {
                if (! str_contains($csp, 'unsafe-eval')) {
                    $csp = (string) preg_replace('/script-src /', "script-src 'unsafe-eval' ", $csp, 1);
                }

                $csp = (string) preg_replace('/style-src /', 'style-src https://fonts.bunny.net ', $csp, 1);
                $csp = (string) preg_replace('/font-src /', 'font-src https://fonts.bunny.net ', $csp, 1);
            }
        }

        // Landing alternativa (/v2 "O Rastro"): página pública de vitrine
        // que lê a contagem de estrelas do repositório na API pública do
        // GitHub. A exceção é MÍNIMA — apenas o host no connect-src, apenas
        // nessa rota. Tudo o mais (script-src, style-src, font-src) segue a
        // CSP base: nenhum script de CDN entra na página. A landing oficial
        // (/) não faz requisição externa nenhuma e fica na CSP base.
        // Caminhos: superfície `landing_alt`.
        if (self::onSurface($request, 'landing_alt')) {
            $landingCsp = config('security.headers.content_security_policy_landing_alt');

            if (is_string($landingCsp) && $landingCsp !== '') {
                $csp = $landingCsp;
            } elseif (is_string($csp) && $csp !== '') {
                $hosts = implode(' ', (array) config('security.headers.landing_alt_connect_src', []));

                if ($hosts !== '') {
                    $csp = (string) preg_replace('/connect-src /', 'connect-src '.$hosts.' ', $csp, 1);
                }
            }
        }

        if (is_string($csp) && $csp !== '') {
            $response->headers->set('Content-Security-Policy', $csp);
        }

        if (config('security.headers.hsts_enabled', false) && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * A requisição está numa superfície com CSP própria? Os caminhos de cada
     * uma vêm de security.headers.surfaces; superfície sem caminho = nenhuma
     * rota.
     */
    private static function onSurface(Request $request, string $surface): bool
    {
        /** @var list<string> $patterns */
        $patterns = array_values(array_filter(
            (array) config("security.headers.surfaces.{$surface}", []),
            static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));

        return $patterns !== [] && $request->is(...$patterns);
    }
}
