<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Localization\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Http\SafeRedirect;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;

/**
 * Troca de idioma (seletor da landing/showcase/painel).
 *
 * Visitante: grava o cookie `locale` (1 ano). Usuário autenticado: também
 * persiste a preferência na conta (users.locale) — usada pela interface e
 * pelos e-mails transacionais. Locais fora da whitelist → 404.
 *
 * O retorno usa o `Referer` para devolver o usuário à MESMA página em que ele
 * estava — mas só depois de validar que aquele endereço é da própria
 * aplicação (Twstec\Kit\Foundation\Http\SafeRedirect). Sem essa validação a rota seria um
 * redirecionador aberto: `Referer: https://evil.example.com/phish` faria o
 * domínio do kit despachar a vítima para o site do atacante.
 */
final class LocaleController
{
    public function __invoke(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, platform()->availableLocales, true), 404);

        $user = $request->user();

        if ($user !== null && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return redirect()->to(SafeRedirect::back($request))
            ->withCookie(cookie()->make(SetLocale::COOKIE, $locale, 60 * 24 * 365));
    }
}
