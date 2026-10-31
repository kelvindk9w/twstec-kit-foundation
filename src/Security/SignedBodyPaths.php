<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security;

use Illuminate\Http\Request;

/**
 * Caminhos com CORPO AUTENTICADO POR ASSINATURA na aplicação (config
 * security.validation.signed_body_paths) — tipicamente webhooks RECEBIDOS de
 * um provedor (WhatsApp, gateway de pagamento), em que o corpo é conferido
 * por HMAC e traz texto escrito por terceiros (a mensagem de um cliente
 * final, dado pessoal).
 *
 * Nesses caminhos:
 * - o filtro de ataques NÃO inspeciona o conteúdo do corpo (texto que parece
 *   código numa mensagem legítima não pode ser recusado — o provedor
 *   reenviaria e acabaria descartando a mensagem);
 * - o corpo NUNCA vai para a trilha (request_logs), nem na linha comum nem na
 *   evidência de tentativa: só o tamanho e o formato (ver RequestInputs);
 * - query, cabeçalhos inspecionados e caminho continuam inspecionados, e o
 *   teto security.validation.max_inspected_bytes continua valendo para o
 *   corpo (o 413 vem antes de qualquer coisa).
 *
 * A lista NÃO pode virar desvio genérico do filtro. Só vale um padrão que
 * aponte para um endereço específico: caminho literal (`webhooks/whatsapp`)
 * ou prefixo literal terminado em `/*` (`webhooks/meta/*`). Curinga no meio
 * ou no começo, curinga parcial (`webhooks*`), o curinga sozinho e padrão
 * sem segmento literal são RECUSADOS — o corpo nesses caminhos continua
 * inspecionado (lado estreito), e em produção a recusa é avisada a cada boot
 * (ProductionHardening), para a decisão não sumir em silêncio.
 */
final class SignedBodyPaths
{
    /**
     * Padrões válidos da configuração, normalizados (sem barra nas pontas).
     *
     * @return list<string>
     */
    public static function patterns(): array
    {
        return array_values(array_filter(
            array_map(self::normalize(...), self::configured()),
            self::isAcceptable(...),
        ));
    }

    /**
     * Entradas da configuração recusadas por serem largas demais (ou vazias
     * de conteúdo literal), na forma em que foram escritas.
     *
     * @return list<string>
     */
    public static function rejected(): array
    {
        return array_values(array_filter(
            self::configured(),
            fn (string $pattern): bool => ! self::isAcceptable(self::normalize($pattern)),
        ));
    }

    /**
     * A requisição é para um caminho de corpo assinado?
     */
    public static function matches(Request $request): bool
    {
        $patterns = self::patterns();

        return $patterns !== [] && $request->is(...$patterns);
    }

    /**
     * Um padrão aceitável aponta para um endereço específico: segmentos
     * literais (sem `*`), opcionalmente seguidos de um único `/*` final.
     */
    public static function isAcceptable(string $pattern): bool
    {
        $pattern = self::normalize($pattern);

        if ($pattern === '') {
            return false;
        }

        $segments = explode('/', $pattern);

        if (end($segments) === '*') {
            array_pop($segments);
        }

        if ($segments === []) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || str_contains($segment, '*')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function configured(): array
    {
        /** @var mixed $configured */
        $configured = config('security.validation.signed_body_paths', []);

        if (! is_array($configured)) {
            return [];
        }

        $patterns = [];

        foreach ($configured as $pattern) {
            if (is_string($pattern) && trim($pattern) !== '') {
                $patterns[] = trim($pattern);
            }
        }

        return $patterns;
    }

    private static function normalize(string $pattern): string
    {
        return trim(trim($pattern), '/');
    }
}
