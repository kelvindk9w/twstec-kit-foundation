<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Http;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Twstec\Kit\Foundation\Logging\CorrelationContext;
use Twstec\Kit\Foundation\Logging\CorrelationOrigin;
use WeakMap;

/**
 * Middleware GLOBAL do cliente `Http` do Laravel: toda chamada de saída leva o
 * correlation_id num cabeçalho e deixa uma linha na trilha
 * (`outbound_http_logs`). Instalado pelo pacote (`Http::globalMiddleware`);
 * a aplicação não precisa lembrar de nada — `Http::post(...)` já sai
 * rastreado, inclusive com `Http::fake()`, `retry()`, `pool()` e `async()`.
 *
 * - Cabeçalho (`tracing.http.header`): o id corrente (o da requisição, o do
 *   job, o da tarefa do agendador — ou um novo, de origem `console`). Não
 *   sobrescreve o mesmo cabeçalho posto pela própria chamada. Fora: destinos
 *   em `except_hosts` e a chamada com `Http::withoutCorrelationHeader()`.
 * - Trilha (`tracing.http.trail`): uma linha por TENTATIVA, gravada quando a
 *   resposta ou a falha de conexão chega (OutboundHttpTrail). Fora: destinos
 *   em `except_hosts`. Corpo só com `Http::withBodyInTrail()`, redigido.
 *
 * A chamada nunca depende da trilha: o que der errado ao montar o cabeçalho
 * ou ao gravar a linha é engolido, e a resposta/exceção chega à aplicação
 * como chegaria sem o pacote.
 */
final class OutboundCorrelation
{
    /**
     * Opções do Guzzle usadas pelo pacote (o Guzzle ignora chaves que não
     * conhece e as repassa aos middlewares).
     */
    public const FLAGS_OPTION = 'tws_tracing';

    public const ATTEMPTS_OPTION = 'tws_tracing_attempts';

    /**
     * Fábricas que já receberam o middleware (registrar duas vezes na mesma
     * fábrica duplicaria a linha da trilha).
     *
     * @var WeakMap<Factory, true>|null
     */
    private static ?WeakMap $registered = null;

    public static function register(Factory $factory): void
    {
        self::$registered ??= new WeakMap;

        if (isset(self::$registered[$factory])) {
            return;
        }

        self::$registered[$factory] = true;

        $factory->globalMiddleware(app(self::class));

        // Um contador de tentativas por PendingRequest (ver OutboundAttempts),
        // somado às opções globais que já existirem.
        $existing = (fn () => $this->globalOptions)->call($factory);

        $factory->globalOptions(static fn (): array => [
            ...(array) value($existing),
            self::ATTEMPTS_OPTION => new OutboundAttempts,
        ]);

        PendingRequest::macro('withoutCorrelationHeader', function (): PendingRequest {
            /** @var PendingRequest $this */
            return $this->withOptions([OutboundCorrelation::FLAGS_OPTION => ['header' => false]]);
        });

        PendingRequest::macro('withBodyInTrail', function (): PendingRequest {
            /** @var PendingRequest $this */
            return $this->withOptions([OutboundCorrelation::FLAGS_OPTION => ['body' => true]]);
        });
    }

    public function __construct(private readonly OutboundHttpTrail $trail) {}

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $flags = is_array($options[self::FLAGS_OPTION] ?? null) ? $options[self::FLAGS_OPTION] : [];
            $host = strtolower($request->getUri()->getHost());

            [$correlationId, $origin] = $this->correlation();

            $request = $this->withHeader($request, $host, $flags, $correlationId);

            if (! $this->shouldTrail($host)) {
                return $handler($request, $options);
            }

            $attempts = $options[self::ATTEMPTS_OPTION] ?? null;
            $attempt = $attempts instanceof OutboundAttempts
                ? $attempts->next($request->getMethod().' '.$request->getUri())
                : null;

            $withBody = ($flags['body'] ?? false) === true;
            $requestBody = $withBody ? $this->trail->requestBody($request) : null;
            $started = hrtime(true);

            $finish = function (?ResponseInterface $response, mixed $failure) use ($request, $attempts, $attempt, $correlationId, $origin, $withBody, $requestBody, $started): void {
                if ($attempts instanceof OutboundAttempts) {
                    $attempts->finished($response === null || $response->getStatusCode() >= 400);
                }

                // record() já é fail-open; este try só garante que nada daqui
                // vire rejeição da promessa da chamada.
                try {
                    $this->trail->record(
                        $request,
                        $response,
                        $failure,
                        intdiv(hrtime(true) - $started, 1_000_000),
                        $attempt,
                        $correlationId,
                        $origin,
                        $withBody,
                        $requestBody,
                    );
                } catch (Throwable) {
                }
            };

            // O handler pode rejeitar a promessa (o caminho normal do Guzzle
            // numa falha de conexão) ou lançar na hora (o `Http::fake()` faz
            // isso com `failedConnection()`): os dois viram linha, e a
            // exceção segue para a aplicação como veio.
            try {
                $promise = $handler($request, $options);
            } catch (Throwable $exception) {
                $finish(self::responseOf($exception), $exception);

                throw $exception;
            }

            return $promise->then(
                function (mixed $response) use ($finish): mixed {
                    $finish($response instanceof ResponseInterface ? $response : null, null);

                    return $response;
                },
                function (mixed $reason) use ($finish): PromiseInterface {
                    $finish(self::responseOf($reason), $reason);

                    return Create::rejectionFor($reason);
                },
            );
        };
    }

    /**
     * @return array{0: ?string, 1: ?CorrelationOrigin}
     */
    private function correlation(): array
    {
        try {
            $context = app(CorrelationContext::class);

            return [$context->ensure(), $context->origin()];
        } catch (Throwable) {
            return [null, null];
        }
    }

    /**
     * @param  array<string, mixed>  $flags
     */
    private function withHeader(RequestInterface $request, string $host, array $flags, ?string $correlationId): RequestInterface
    {
        if ($correlationId === null || ($flags['header'] ?? true) === false || config('tracing.http.header.enabled', true) === false) {
            return $request;
        }

        $name = trim((string) config('tracing.http.header.name', 'X-Correlation-Id'));

        if ($name === '' || $request->hasHeader($name) || self::hostMatches($host, (array) config('tracing.http.header.except_hosts', []))) {
            return $request;
        }

        try {
            return $request->withHeader($name, $correlationId);
        } catch (Throwable) {
            return $request;
        }
    }

    private function shouldTrail(string $host): bool
    {
        return config('tracing.http.trail.enabled', true) !== false
            && ! self::hostMatches($host, (array) config('tracing.http.trail.except_hosts', []));
    }

    /**
     * @param  array<array-key, mixed>  $patterns
     */
    public static function hostMatches(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && $pattern !== '' && Str::is(strtolower(trim($pattern)), $host)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rejeição com resposta (exceção do Guzzle com `getResponse()`) também
     * tem status e tamanho para a trilha.
     */
    private static function responseOf(mixed $reason): ?ResponseInterface
    {
        if (is_object($reason) && method_exists($reason, 'getResponse')) {
            $response = $reason->getResponse();

            return $response instanceof ResponseInterface ? $response : null;
        }

        return null;
    }
}
