<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Idempotency\Exceptions\IdempotencyRefusedException;

/**
 * A chave que o cliente mandou no cabeçalho `Idempotency-Key`, já validada.
 *
 * FORMATO: aceita o valor cru (`Idempotency-Key: 8e03978e-…`) ou entre aspas,
 * como o rascunho da IETF o descreve (Structured Field String —
 * `Idempotency-Key: "8e03978e-…"`); as aspas saem antes da validação. Só
 * passam os caracteres `A-Z a-z 0-9 - _ . : ~ + / =`, no tamanho configurado
 * (padrão 16 a 255). Mais de um cabeçalho na mesma requisição é recusado.
 *
 * A chave é DADO DO CLIENTE: nunca vira nome de arquivo, chave de cache nem
 * linha de log em claro. O banco guarda o SHA-256 dela (`hash()`); o log, só
 * uma impressão curta do hash (`fingerprint()`).
 */
final class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    /**
     * Teto absoluto do tamanho, independente da configuração.
     */
    public const MAX_LENGTH = 255;

    private const ALLOWED = '/\A[A-Za-z0-9\-_.:~+\/=]+\z/';

    private function __construct(private readonly string $value) {}

    /**
     * A chave da requisição; null quando o cabeçalho não veio.
     *
     * @throws IdempotencyRefusedException chave fora do formato (400)
     */
    public static function fromRequest(Request $request): ?self
    {
        $values = $request->headers->all(strtolower(self::HEADER));

        if ($values === []) {
            return null;
        }

        if (count($values) > 1 || ! is_string($values[0])) {
            throw IdempotencyRefusedException::invalid();
        }

        return self::parse($values[0]) ?? throw IdempotencyRefusedException::invalid();
    }

    /**
     * Valida o valor do cabeçalho; null quando fora do formato.
     */
    public static function parse(string $raw): ?self
    {
        $value = trim($raw);

        // Structured Field String: "valor" (sem escapes — os caracteres
        // aceitos não incluem aspa nem barra invertida).
        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        $length = strlen($value);

        if ($length < self::minLength() || $length > self::maxLength()) {
            return null;
        }

        return preg_match(self::ALLOWED, $value) === 1 ? new self($value) : null;
    }

    /**
     * SHA-256 da chave — o que o banco guarda.
     */
    public function hash(): string
    {
        return hash('sha256', $this->value);
    }

    /**
     * Impressão curta para o log (os 12 primeiros caracteres do hash): liga as
     * linhas da mesma chave sem expor o valor.
     */
    public function fingerprint(): string
    {
        return substr($this->hash(), 0, 12);
    }

    public static function minLength(): int
    {
        return max(1, min((int) config('idempotency.key_min_length', 16), self::maxLength()));
    }

    public static function maxLength(): int
    {
        return max(1, min((int) config('idempotency.key_max_length', self::MAX_LENGTH), self::MAX_LENGTH));
    }
}
