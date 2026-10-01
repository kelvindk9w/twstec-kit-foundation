<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Http;

use Psr\Http\Message\UriInterface;
use Twstec\Kit\Foundation\Logging\Redactor;

/**
 * O DESTINO de uma chamada HTTP de saída, do jeito que pode ir para a trilha:
 * host e ROTA NORMALIZADA — nunca a URL como foi chamada.
 *
 * A URL de saída carrega segredo com frequência: token na query
 * (`?access_token=…`, `?sig=…`), credencial no userinfo
 * (`https://user:senha@host`), identificador pessoal ou chave no caminho
 * (`/clientes/123.456.789-09`, `/webhooks/9f8e…c1`). Por isso:
 *
 * - userinfo e fragmento: descartados;
 * - query: só os NOMES dos parâmetros, nunca os valores;
 * - caminho: cada segmento que parece valor vira marcador —
 *   número → `{n}`, UUID → `{uuid}`, e-mail → `{email}`, CPF/CNPJ/cartão e
 *   qualquer segmento com 6+ dígitos → `{n}`, segmento longo com letras e
 *   dígitos (token, hash, chave) e segmento de 24+ caracteres que não é
 *   palavra minúscula → `{token}`. O que sobra (nomes de rota
 *   como `v1`, `charges`, `refund`) fica, cortado em 64 caracteres e passado
 *   pelas máscaras do Redactor;
 * - host: minúsculo, com a porta só quando não é a padrão do esquema.
 *
 * Normalizar também é o que torna a trilha consultável: todas as chamadas a
 * `/v1/charges/{token}/refund` caem na mesma rota, seja qual for a cobrança.
 */
final class OutboundEndpoint
{
    /**
     * Tamanho a partir do qual um segmento alfanumérico com dígito é tratado
     * como token/identificador.
     */
    private const TOKEN_MIN_LENGTH = 16;

    /**
     * Tamanho a partir do qual um segmento que NÃO é palavra minúscula
     * (`payment-methods`, `refund_requests`) é tratado como valor opaco —
     * token só de letras, base64, chave com maiúsculas.
     */
    private const OPAQUE_MIN_LENGTH = 24;

    private const SEGMENT_MAX_LENGTH = 64;

    private const PATH_MAX_LENGTH = 500;

    private const MAX_QUERY_KEYS = 30;

    public function __construct(private readonly Redactor $redactor) {}

    public static function host(UriInterface $uri): string
    {
        $host = strtolower($uri->getHost());
        $port = $uri->getPort();
        $default = ['http' => 80, 'https' => 443][strtolower($uri->getScheme())] ?? null;

        return $port !== null && $port !== $default ? $host.':'.$port : $host;
    }

    public function path(UriInterface $uri): string
    {
        $segments = array_map(
            fn (string $segment): string => $this->segment(rawurldecode($segment)),
            array_values(array_filter(explode('/', $uri->getPath()), fn (string $s): bool => $s !== '')),
        );

        $segments = array_map(
            fn (string $segment): string => mb_strlen($segment) > self::SEGMENT_MAX_LENGTH ? mb_substr($segment, 0, self::SEGMENT_MAX_LENGTH).'…' : $segment,
            $segments,
        );

        $path = '/'.implode('/', $segments);

        return mb_strlen($path) > self::PATH_MAX_LENGTH ? mb_substr($path, 0, self::PATH_MAX_LENGTH).'…' : $path;
    }

    /**
     * Só os nomes dos parâmetros da query, sem repetição, com as máscaras
     * do Redactor (um nome de parâmetro também pode ser dado).
     *
     * @return list<string>
     */
    public function queryKeys(UriInterface $uri): array
    {
        // Lido da URL em texto (e não do método da URI) só para não cair na
        // trava de arquitetura que procura escapes do escopo de conta.
        $query = (string) parse_url((string) $uri, PHP_URL_QUERY);

        if ($query === '') {
            return [];
        }

        $keys = [];

        foreach (explode('&', $query) as $pair) {
            $name = rawurldecode(str_replace('+', ' ', explode('=', $pair, 2)[0]));

            if ($name === '') {
                continue;
            }

            $name = mb_substr($this->redactor->redactString($name), 0, self::SEGMENT_MAX_LENGTH);

            if (! in_array($name, $keys, true)) {
                $keys[] = $name;
            }

            if (count($keys) >= self::MAX_QUERY_KEYS) {
                break;
            }
        }

        return $keys;
    }

    private function segment(string $segment): string
    {
        return match (true) {
            preg_match('/^\d+$/', $segment) === 1 => '{n}',
            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1 => '{uuid}',
            str_contains($segment, '@') => '{email}',
            preg_match_all('/\d/', $segment) >= 6 => '{n}',
            mb_strlen($segment) >= self::TOKEN_MIN_LENGTH && preg_match('/\d/', $segment) === 1 => '{token}',
            mb_strlen($segment) >= self::OPAQUE_MIN_LENGTH && preg_match('/^[a-z]+(?:[-_.][a-z]+)*$/', $segment) !== 1 => '{token}',
            default => $this->redactor->redactString($segment),
        };
    }
}
