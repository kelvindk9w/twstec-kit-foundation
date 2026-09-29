<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Localization;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Throwable;
use Twstec\Kit\Foundation\Localization\Middleware\SetLocale;

/**
 * Idioma de uma resposta produzida ANTES do grupo `web` — hoje, a página 429
 * do limite da borda (EdgeRateLimit), que recusa a requisição antes de a
 * sessão, o EncryptCookies e o SetLocale rodarem.
 *
 * Ordem (a mesma intenção do SetLocale, com o que existe nesse ponto):
 * 1. cookie `locale` do visitante, decifrado aqui mesmo (o EncryptCookies
 *    ainda não rodou; valor adulterado ou de outra chave é descartado);
 * 2. `Accept-Language` do navegador, casado com os idiomas disponíveis;
 * 3. padrão da plataforma.
 *
 * A preferência salva na CONTA não entra: sem sessão, não há usuário. Só
 * valores da whitelist platform.available_locales são aceitos.
 */
final class EarlyLocale
{
    public static function resolve(Request $request): string
    {
        $available = platform()->availableLocales;

        $candidates = [
            self::fromCookie($request),
            $available === [] ? null : $request->getPreferredLanguage($available),
            platform()->locale,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return (string) config('app.fallback_locale', 'pt_BR');
    }

    private static function fromCookie(Request $request): ?string
    {
        $raw = $request->cookies->get(SetLocale::COOKIE);

        if (! is_string($raw) || $raw === '' || strlen($raw) > 1024) {
            return null;
        }

        try {
            $encrypter = app(Encrypter::class);

            $value = CookieValuePrefix::validate(
                SetLocale::COOKIE,
                (string) $encrypter->decrypt($raw, EncryptCookies::serialized(SetLocale::COOKIE)),
                $encrypter->getAllKeys(),
            );

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
