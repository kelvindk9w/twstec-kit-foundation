<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Http;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Twstec\Kit\Foundation\Identifiers\UuidColumn;
use Twstec\Kit\Foundation\Logging\CorrelationOrigin;
use Twstec\Kit\Foundation\Logging\PersistFailure;
use Twstec\Kit\Foundation\Logging\Redactor;
use Twstec\Kit\Foundation\Logging\RequestLogChannel;
use Twstec\Kit\Foundation\Tracing\Models\OutboundHttpLog;

/**
 * Grava a linha de uma chamada HTTP de saída — no banco
 * (`outbound_http_logs`) e no canal `request_log` (`http.outbound`).
 *
 * O QUE ENTRA: método, host, rota normalizada e nomes dos parâmetros da query
 * (OutboundEndpoint), status, duração, tentativa, tamanhos do corpo enviado e
 * recebido, correlation_id e origem, conta (quando um pacote sabe dizer qual
 * — ver resolveTenantUsing) e, na falha de conexão, o tipo do erro com a
 * mensagem redigida e sem URL.
 *
 * O QUE NUNCA ENTRA: cabeçalho nenhum (Authorization, cookies, chaves de
 * API), valor de parâmetro da query, userinfo da URL. Corpo só quando a
 * chamada pediu (`Http::withBodyInTrail()`), e então redigido pelo Redactor
 * (segredos por nome de campo, CPF/CNPJ, e-mail e cartão em qualquer texto);
 * corpo que não é JSON nem formulário entra só como tipo e tamanho.
 *
 * FAIL-OPEN, SÓ DA TRILHA: qualquer erro ao montar ou gravar a linha é
 * engolido aqui e vira `http.outbound.persist_failed` (critical) no canal de
 * arquivo. A chamada em si nunca é afetada — nem a resposta, nem a exceção
 * de conexão, que seguem para a aplicação exatamente como viriam.
 */
final class OutboundHttpTrail
{
    /**
     * Corpo maior que isto não é lido para a trilha (só tipo e tamanho).
     */
    private const BODY_MAX_BYTES = 65536;

    /**
     * @var (Closure(): ?string)|null
     */
    private static ?Closure $tenantResolver = null;

    public function __construct(
        private readonly Redactor $redactor,
        private readonly OutboundEndpoint $endpoint,
    ) {}

    /**
     * Quem sabe qual é a CONTA atual (o twstec/kit-accounts registra a dele)
     * informa aqui; a trilha grava o uuid em `tenant_uuid`. Null desliga.
     *
     * @param  (Closure(): ?string)|null  $resolver
     */
    public static function resolveTenantUsing(?Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    /**
     * O corpo enviado, redigido — lido ANTES do envio, com o stream
     * devolvido ao início.
     *
     * @return array<array-key, mixed>|null
     */
    public function requestBody(RequestInterface $request): ?array
    {
        try {
            return $this->body($request);
        } catch (Throwable) {
            return ['_omitted' => 'unreadable'];
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $requestBody  corpo já redigido (quando pedido)
     */
    public function record(
        RequestInterface $request,
        ?ResponseInterface $response,
        mixed $failure,
        int $durationMs,
        ?int $attempt,
        ?string $correlationId,
        ?CorrelationOrigin $origin,
        bool $withBody,
        ?array $requestBody,
    ): void {
        $context = [];

        try {
            $uri = $request->getUri();

            $context = [
                'correlation_id' => UuidColumn::isValid($correlationId) ? $correlationId : null,
                'correlation_origin' => $origin?->value,
                'tenant_uuid' => $this->tenant(),
                'method' => strtoupper(Str::limit($request->getMethod(), 10, '')),
                'host' => Str::limit(OutboundEndpoint::host($uri), 255, ''),
                'path' => $this->endpoint->path($uri),
                'query_keys' => $this->endpoint->queryKeys($uri) ?: null,
                'attempt' => $attempt,
                'http_status' => $response?->getStatusCode(),
                'duration_ms' => max(0, $durationMs),
                'request_bytes' => $this->size($request),
                'response_bytes' => $response !== null ? $this->size($response) : null,
                'error' => $failure !== null ? $this->describeFailure($failure) : null,
            ];

            $bodies = $withBody ? [
                'request_body' => $requestBody,
                'response_body' => $response !== null ? $this->safeBody($response) : null,
            ] : [];

            OutboundHttpLog::query()->create([...$context, ...$bodies]);

            Log::channel(RequestLogChannel::NAME)->info('http.outbound', $context);
        } catch (Throwable $exception) {
            // A trilha falhou; a chamada não. Ver o cabeçalho da classe.
            try {
                Log::channel(RequestLogChannel::NAME)->critical('http.outbound.persist_failed', [
                    ...$context,
                    'error' => $context['error'] ?? null,
                    'failure_reason' => PersistFailure::describe($exception)['failure_reason'],
                    'persist_error' => Str::limit($this->redactor->redactString(self::withoutUrls($exception->getMessage())), 500, ''),
                ]);
            } catch (Throwable) {
                // Nem o arquivo: nada mais a fazer sem afetar a chamada.
            }
        }
    }

    private function tenant(): ?string
    {
        if (self::$tenantResolver === null) {
            return null;
        }

        try {
            $tenant = (self::$tenantResolver)();
        } catch (Throwable) {
            return null;
        }

        return UuidColumn::isValid($tenant) ? $tenant : null;
    }

    private function size(MessageInterface $message): ?int
    {
        $size = $message->getBody()->getSize();

        if ($size !== null) {
            return $size;
        }

        $length = $message->getHeaderLine('Content-Length');

        return ctype_digit($length) ? (int) $length : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function safeBody(MessageInterface $message): ?array
    {
        try {
            return $this->body($message);
        } catch (Throwable) {
            return ['_omitted' => 'unreadable'];
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function body(MessageInterface $message): ?array
    {
        $stream = $message->getBody();
        $size = $stream->getSize();
        $type = strtolower(trim(explode(';', $message->getHeaderLine('Content-Type'))[0]));

        if ($size === 0) {
            return null;
        }

        if (! $stream->isSeekable() || $size === null || $size > self::BODY_MAX_BYTES) {
            return ['_omitted' => $type !== '' ? $type : 'body', 'bytes' => $size];
        }

        $position = $stream->tell();
        $stream->rewind();
        $raw = $stream->getContents();
        $stream->seek($position);

        $decoded = match (true) {
            str_contains($type, 'json') => json_decode($raw, true),
            $type === 'application/x-www-form-urlencoded' => self::parseForm($raw),
            default => null,
        };

        if (! is_array($decoded)) {
            return ['_omitted' => $type !== '' ? $type : 'body', 'bytes' => $size];
        }

        return $this->redactor->redactArray($decoded);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function parseForm(string $raw): array
    {
        parse_str($raw, $parsed);

        return $parsed;
    }

    /**
     * Tipo do erro e mensagem redigida, sem URL (a mensagem do cURL repete a
     * URL inteira, com a query).
     */
    private function describeFailure(mixed $failure): string
    {
        if (! $failure instanceof Throwable) {
            return 'rejected';
        }

        return Str::limit(
            class_basename($failure).': '.$this->redactor->redactString(self::withoutUrls($failure->getMessage())),
            500,
            '',
        );
    }

    private static function withoutUrls(string $message): string
    {
        return (string) preg_replace('#\b[a-z][a-z0-9+.-]*://\S+#i', '[url]', $message);
    }
}
