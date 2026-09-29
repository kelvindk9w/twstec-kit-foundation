<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http;

use Illuminate\Http\Request;

/**
 * Destino seguro para redirecionar "de volta" (proteção contra open redirect).
 *
 * PROBLEMA: qualquer rota que devolva o usuário para um endereço vindo do
 * CLIENTE — o header `Referer` (é ele que o helper `back()` do Laravel usa),
 * um parâmetro `?redirect=`, `?next=`, `?return_to=` — vira um redirecionador
 * aberto hospedado no domínio da aplicação. É munição clássica de phishing: o
 * link começa no domínio legítimo (passa na inspeção visual do usuário e nos
 * filtros que confiam no domínio) e termina no domínio do atacante.
 *
 * SOLUÇÃO: só redirecionar para destino de MESMA ORIGEM; qualquer outra coisa
 * cai no fallback configurado. A origem permitida é a da aplicação
 * (`config('app.url')`) mais a lista opcional de
 * `security.redirects.allowed_origins` — NUNCA o host da requisição atual.
 *
 * Por que não usar o host da requisição: `Host`/`X-Forwarded-Host` são dados
 * do cliente. Enquanto o TrustProxies não estiver configurado (e mesmo depois
 * dele, se a lista de proxies for ampla demais), aceitar a origem "da
 * requisição" significaria aceitar a origem que o ATACANTE declarou — bastaria
 * mandar `Host: evil.com` junto com `Referer: https://evil.com/...` para os
 * dois casarem. Ancorar em configuração torna a validação independente da
 * borda: o TrustProxies pode entrar depois sem afrouxar nada aqui.
 *
 * Comparação ESTRUTURADA, nunca prefixo de string: esquema, host e porta são
 * extraídos e comparados um a um. `str_starts_with` cairia em todos os truques
 * clássicos — `https://app.legit.evil.com` (sufixo), `https://evil.com/https://app.legit`
 * (o alvo no caminho), `//evil.com` (URL relativa ao protocolo),
 * `https://user@evil.com` (credenciais embutidas, host real depois do `@`),
 * `https:/\evil.com` (barra invertida, que vários parsers e navegadores tratam
 * como `//`).
 */
final class SafeRedirect
{
    /**
     * Teto de tamanho do candidato: acima disso não é destino de navegação
     * legítima, e parsers divergem em entradas gigantes.
     */
    private const MAX_LENGTH = 2048;

    /**
     * Portas implícitas por esquema (normalização antes de comparar).
     */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * Destino para onde a requisição pode voltar com segurança.
     * Devolve o próprio candidato quando ele é de mesma origem; caso
     * contrário, o fallback configurado.
     */
    public static function url(?string $candidate, ?string $fallback = null): string
    {
        if (self::isSafe($candidate)) {
            /** @var string $candidate */
            return $candidate;
        }

        return $fallback ?? self::fallback();
    }

    /**
     * Destino de volta a partir do `Referer` da requisição (o mesmo header
     * que o `back()` do Laravel usa, aqui validado antes de ser obedecido).
     */
    public static function back(Request $request, ?string $fallback = null): string
    {
        return self::url($request->headers->get('referer'), $fallback);
    }

    /**
     * O candidato aponta para dentro da própria aplicação?
     */
    public static function isSafe(?string $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        $candidate = trim($candidate);

        if ($candidate === '' || strlen($candidate) > self::MAX_LENGTH) {
            return false;
        }

        // Caracteres que parsers, navegadores e proxies interpretam de formas
        // diferentes: controle, espaço em branco e BARRA INVERTIDA. Recusar
        // antes de analisar elimina `https:/\evil.com` e companhia sem
        // depender de qual normalização o parser da vez aplica.
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $candidate) === 1) {
            return false;
        }

        // Caminho relativo à raiz é sempre da própria aplicação — desde que
        // NÃO seja `//host` (URL relativa ao protocolo, que sai do domínio).
        if (str_starts_with($candidate, '/')) {
            return ! str_starts_with($candidate, '//');
        }

        $origin = self::originOf($candidate);

        if ($origin === null) {
            return false;
        }

        return in_array($origin, self::allowedOrigins(), true);
    }

    /**
     * Fallback usado quando o destino pedido não é confiável.
     */
    public static function fallback(): string
    {
        $fallback = (string) config('security.redirects.fallback', '/');

        return $fallback === '' ? '/' : $fallback;
    }

    /**
     * Origens aceitas: a da aplicação mais as declaradas em configuração.
     *
     * @return list<string>
     */
    private static function allowedOrigins(): array
    {
        /** @var list<string> $extra */
        $extra = (array) config('security.redirects.allowed_origins', []);

        $origins = [];

        foreach (array_merge([(string) config('app.url')], $extra) as $url) {
            $origin = self::originOf($url);

            if ($origin !== null) {
                $origins[] = $origin;
            }
        }

        return array_values(array_unique($origins));
    }

    /**
     * Origem canônica (`esquema://host:porta`) de uma URL absoluta, ou nulo
     * quando a URL não é uma URL http(s) absoluta e sem credenciais.
     */
    private static function originOf(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return null;
        }

        // Credenciais embutidas: o host "visível" antes do `@` é decorativo e
        // serve justamente para enganar quem lê a URL. Nunca são aceitas.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        // Sem esquema não há URL absoluta: `//evil.com` e `evil.com` param aqui.
        if (! array_key_exists($scheme, self::DEFAULT_PORTS)) {
            return null;
        }

        // Host sempre em minúsculas e sem o ponto final da forma absoluta do
        // DNS (`app.legit.`), que apontaria para o mesmo lugar.
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');

        if ($host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? self::DEFAULT_PORTS[$scheme]);

        return $scheme.'://'.$host.':'.$port;
    }
}
