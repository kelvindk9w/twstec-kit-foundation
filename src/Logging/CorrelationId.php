<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Identificadores de correlação da requisição.
 *
 * São DOIS, com donos diferentes — e essa separação é de segurança:
 *
 * 1. correlation_id (INTERNO): SEMPRE gerado pelo servidor (UUID v7
 *    ordenado). É a chave única da linha em `request_logs`, o valor
 *    propagado no contexto do Monolog, o que sai no header de resposta
 *    X-Correlation-Id e o que o envelope de erro da API entrega ao cliente
 *    para acionar o suporte. NUNCA vem do cliente.
 *
 * 2. client_correlation_id (DO CLIENTE): o X-Correlation-Id que veio na
 *    requisição, saneado. É só um rótulo de rastreabilidade do chamador,
 *    guardado em coluna própria, SEM restrição de unicidade.
 *
 * POR QUE SEPARADO — a falha que isso corrige: enquanto o header de entrada
 * alimentava a coluna única `request_logs.correlation_id`, qualquer anônimo
 * podia "queimar" um UUID com uma requisição qualquer e, dali em diante,
 * repetir o mesmo header para que todo INSERT seguinte violasse a constraint.
 * Como a gravação da trilha roda dentro de try/catch (por desenho: o log não
 * pode derrubar a requisição), a exceção era engolida e a requisição
 * simplesmente NÃO era auditada — inclusive as linhas BLOQUEADA do
 * SecurityValidation, que são o registro das tentativas de ataque. O
 * atacante apagava o próprio rastro. Com o id interno gerado pelo servidor,
 * o valor enviado pelo cliente não pode mais colidir com nada.
 *
 * SANEAMENTO do valor do cliente: é dado hostil e entra em tela de admin, em
 * arquivo de log e em coluna de banco. Não se exige UUID — clientes de API
 * legítimos correlacionam com traceparent do W3C, ULID, ou ids próprios do
 * vendor —, mas só sobrevivem caracteres de uma lista branca conservadora
 * (letras, dígitos, `-`, `_`, `.`, `:`) e um limite de tamanho configurável.
 * Fora da lista branca não há aspa, sinal de menor, barra invertida, byte
 * nulo nem quebra de linha: nada que sirva de vetor de injeção ou de
 * envenenamento de log, e nada que estoure a coluna.
 */
final class CorrelationId
{
    /**
     * Nome do atributo na requisição e da chave no contexto de log do id
     * INTERNO (o gerado pelo servidor).
     */
    public const ATTRIBUTE = 'correlation_id';

    /**
     * Atributo/chave de contexto da correlação informada pelo CLIENTE.
     */
    public const CLIENT_ATTRIBUTE = 'client_correlation_id';

    /**
     * Header HTTP de propagação (entrada e saída).
     */
    public const HEADER = 'X-Correlation-Id';

    /**
     * Teto absoluto do valor do cliente, independente da configuração —
     * é o tamanho da coluna em `request_logs`.
     */
    public const CLIENT_MAX_LENGTH = 255;

    /**
     * Caracteres aceitos no valor do cliente (lista branca).
     */
    private const CLIENT_ALLOWED = '/[^A-Za-z0-9._:-]/';

    /**
     * Resolve (ou gera) o correlation_id INTERNO da requisição e o propaga
     * para o contexto de log. Idempotente: chamadas seguintes na mesma
     * requisição retornam o mesmo valor. O header de entrada é ignorado
     * aqui de propósito — ver o cabeçalho desta classe.
     */
    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid7();

        $request->attributes->set(self::ATTRIBUTE, $id);

        $context = [self::ATTRIBUTE => $id];

        $client = self::fromClient($request);

        if ($client !== null) {
            $context[self::CLIENT_ATTRIBUTE] = $client;
        }

        Log::shareContext($context);

        return $id;
    }

    /**
     * Correlação informada pelo cliente no X-Correlation-Id de entrada, já
     * saneada — ou null quando ausente ou sem nenhum caractere aproveitável.
     * Idempotente na mesma requisição.
     */
    public static function fromClient(Request $request): ?string
    {
        if ($request->attributes->has(self::CLIENT_ATTRIBUTE)) {
            $cached = $request->attributes->get(self::CLIENT_ATTRIBUTE);

            return is_string($cached) && $cached !== '' ? $cached : null;
        }

        $incoming = $request->header(self::HEADER);

        $sanitized = is_string($incoming) ? self::sanitizeClientValue($incoming) : null;

        $request->attributes->set(self::CLIENT_ATTRIBUTE, $sanitized);

        return $sanitized;
    }

    /**
     * Lista branca de caracteres + limite de tamanho. Devolve null quando
     * não sobra nada de útil (header vazio ou só com lixo descartado).
     */
    public static function sanitizeClientValue(string $value): ?string
    {
        $clean = (string) preg_replace(self::CLIENT_ALLOWED, '', $value);

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, self::clientMaxLength());
    }

    /**
     * Tamanho máximo configurado (config/security.php), limitado ao teto da
     * coluna para que a configuração nunca provoque erro de gravação.
     */
    private static function clientMaxLength(): int
    {
        /** @var int $configured */
        $configured = config('security.request_logging.client_correlation_max_length', 128);

        return max(1, min($configured, self::CLIENT_MAX_LENGTH));
    }
}
