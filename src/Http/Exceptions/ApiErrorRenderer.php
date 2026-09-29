<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;
use Twstec\Kit\Foundation\Logging\CorrelationId;

/**
 * Envelope padronizado de ERRO da API (`api/*`) — contrapartida do envelope
 * de sucesso `{"data": …}` dos Resources.
 *
 * Formato (sempre):
 *
 *     {"error": {"code": "…", "message": "…", "correlation_id": "…"}}
 *
 * Em 422, o envelope ganha `errors` (mapa campo → lista de mensagens).
 *
 * Regras inegociáveis:
 * - NUNCA stack trace, classe interna, arquivo ou linha do servidor —
 *   nem com APP_DEBUG=true. O que o cliente da API recebe em produção é o
 *   que ele recebe em desenvolvimento (contrato estável e sem vazamento).
 * - O `correlation_id` é o MESMO do header X-Correlation-Id e da linha de
 *   `request_logs` — é assim que o suporte liga a queixa do cliente ao log.
 * - 5xx nunca ecoa a mensagem da exceção (pode conter SQL, caminho, segredo):
 *   sai sempre a mensagem genérica traduzida; o detalhe fica no log.
 */
final class ApiErrorRenderer
{
    /**
     * Códigos estáveis por status HTTP (contrato público — o cliente pode
     * programar em cima deles; a `message` é humana e traduzida).
     *
     * @var array<int, string>
     */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        419 => 'page_expired',
        422 => 'validation_failed',
        429 => 'too_many_requests',
        503 => 'service_unavailable',
    ];

    /**
     * Renderiza a exceção no envelope de erro. Retorna null quando a
     * requisição não é da API (o Laravel segue com o tratamento padrão).
     */
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $status = $this->status($e);
        $code = self::CODES[$status] ?? 'server_error';

        $payload = [
            'code' => $code,
            'message' => $this->message($e, $status, $code),
            'correlation_id' => CorrelationId::resolve($request),
        ];

        if ($e instanceof ValidationException) {
            $payload['errors'] = $e->errors();
        }

        $response = new JsonResponse(['error' => $payload], $status);

        // Rastreabilidade: o mesmo id do corpo também sai no header, como
        // nas respostas de sucesso.
        $response->headers->set(CorrelationId::HEADER, $payload['correlation_id']);

        // Retry-After do rate limit é informação útil e não sensível.
        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $header => $value) {
                $response->headers->set($header, is_array($value) ? $value : (string) $value);
            }
        }

        return $response;
    }

    /**
     * Status HTTP da exceção, com os mapeamentos que o Laravel também faz.
     */
    private function status(Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => $e->status,
            $e instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $e instanceof AuthorizationException => Response::HTTP_FORBIDDEN,
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => Response::HTTP_NOT_FOUND,
            $e instanceof TooManyRequestsHttpException => Response::HTTP_TOO_MANY_REQUESTS,
            $e instanceof BadRequestException => Response::HTTP_BAD_REQUEST,
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    /**
     * Mensagem humana. 4xx pode carregar a mensagem própria do abort()
     * (ex.: "chave de API inválida", já traduzida na origem); 5xx NUNCA —
     * a mensagem da exceção pode vazar infraestrutura.
     */
    private function message(Throwable $e, int $status, string $code): string
    {
        if ($status >= 500) {
            return __('api.errors.server_error');
        }

        $own = trim($e->getMessage());

        // O Symfony/Laravel preenche mensagens internas em inglês para
        // 404/405/419 ("No query results for model […]", "CSRF token
        // mismatch") — reveladoras e não traduzidas. Só aceitamos a
        // mensagem quando ela veio de um abort() explícito da aplicação.
        $isOwnMessage = $own !== ''
            && $e instanceof HttpExceptionInterface
            && ! $e instanceof NotFoundHttpException
            && ! str_contains($own, '\\');

        if ($e instanceof ValidationException) {
            return __('api.errors.validation_failed');
        }

        // 429 sai sempre com a mensagem traduzida da API: o ThrottleRequests
        // do framework lança "Too Many Attempts." em inglês, e a borda usa o
        // título da página de erro da web. O cliente da API programa em cima
        // do `code` e do Retry-After; a mensagem precisa ser a mesma venha o
        // limite de onde vier.
        if ($status === Response::HTTP_TOO_MANY_REQUESTS) {
            return __('api.errors.too_many_requests');
        }

        return $isOwnMessage ? $own : __("api.errors.{$code}");
    }
}
