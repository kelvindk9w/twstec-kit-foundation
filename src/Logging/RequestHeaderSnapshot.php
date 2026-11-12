<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Os cabeçalhos que a trilha guarda (`request_logs.headers`) e a cadeia de
 * proxies (`ip_chain`, `forwarded_trusted`).
 *
 * Lista FECHADA (security.request_logging.headers): só entra o que está
 * listado. Credencial nunca entra, mesmo listada — Authorization, Cookie,
 * X-Api-Key e qualquer nome que o Redactor trate como segredo. Os valores
 * passam pelo Redactor; Origin e Referer perdem a query e o fragmento (onde
 * mora o token de link mágico, de redefinição de senha...).
 */
final class RequestHeaderSnapshot
{
    /**
     * Nunca gravados, mesmo que a configuração os liste.
     *
     * @var list<string>
     */
    public const NEVER = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie',
        'x-api-key', 'x-api-secret', 'x-auth-token', 'x-csrf-token', 'x-xsrf-token',
    ];

    private const MAX_VALUE_LENGTH = 500;

    private const MAX_CHAIN_LENGTH = 1000;

    public function __construct(private readonly Redactor $redactor) {}

    /**
     * @return array<string, string>|null null quando nenhum cabeçalho listado veio
     */
    public function headers(Request $request): ?array
    {
        $captured = [];

        foreach ($this->allowed() as $name) {
            $value = $request->headers->get($name);

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (in_array($name, ['origin', 'referer'], true)) {
                $value = (string) preg_replace('/[?#].*$/s', '', $value);
            }

            $captured[$name] = Str::limit($this->redactor->redactString($value), self::MAX_VALUE_LENGTH, '…');
        }

        return $captured === [] ? null : $captured;
    }

    /**
     * O X-Forwarded-For como chegou, item a item: endereço IP válido fica,
     * qualquer outra coisa vira `[invalid]` (a evidência de que a cadeia foi
     * forjada, sem gravar o texto do atacante). Com limite. Null quando não
     * veio.
     */
    public function ipChain(Request $request): ?string
    {
        $raw = $request->headers->get('X-Forwarded-For');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $items = array_map(
            fn (string $item): string => filter_var(trim($item), FILTER_VALIDATE_IP) !== false ? trim($item) : '[invalid]',
            explode(',', $raw),
        );

        return Str::limit(implode(', ', $items), self::MAX_CHAIN_LENGTH, '');
    }

    /**
     * O REMOTE_ADDR era um proxy confiável? Sem isso, a cadeia é só o que o
     * cliente afirmou — e o `ip` gravado é o do próprio REMOTE_ADDR.
     */
    public function forwardedTrusted(Request $request): bool
    {
        return $request->isFromTrustedProxy();
    }

    /**
     * Os nomes listados, em minúsculas, menos os proibidos.
     *
     * @return list<string>
     */
    private function allowed(): array
    {
        $configured = config('security.request_logging.headers', []);
        $configured = is_string($configured) ? explode(',', $configured) : (is_array($configured) ? $configured : []);

        $names = [];

        foreach ($configured as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = strtolower(trim($name));

            if ($name === '' || in_array($name, self::NEVER, true) || $this->redactor->isSensitiveKey(str_replace('-', '_', $name))) {
                continue;
            }

            $names[] = $name;
        }

        return array_values(array_unique($names));
    }
}
