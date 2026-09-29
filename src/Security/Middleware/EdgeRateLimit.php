<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security\Middleware;

use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twstec\Kit\Foundation\Localization\EarlyLocale;
use Twstec\Kit\Foundation\Logging\CorrelationId;
use Twstec\Kit\Foundation\Logging\EndpointSignature;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Logging\PersistFailure;
use Twstec\Kit\Foundation\Logging\ScanTrafficSampler;
use Twstec\Kit\Foundation\Security\ClientBucket;

/**
 * Limite de requisições da BORDA — por cliente (IP, ou prefixo IPv6), para
 * TODA requisição que chega ao PHP: páginas, updates do Livewire, /admin,
 * /up, a API e as rotas que nem existem.
 *
 * POR QUE GLOBAL, E NÃO `throttle:` NO GRUPO `web`: middleware de grupo não
 * roda em rota inexistente — e o flood de 404 de varredura era justamente
 * metade do problema. Também não cobriria o /up, que é registrado fora dos
 * grupos.
 *
 * POR QUE ANTES DO SecurityValidation E DO RequestLogging: o que este limite
 * protege é exatamente o trabalho que eles fazem — regex sobre o corpo
 * inteiro (CPU) e INSERT + UPDATE em `request_logs` (escrita no banco). Um
 * cliente acima do limite recebe 429 SEM que o corpo seja lido, varrido ou
 * gravado: o custo da recusa é um incremento no cache. Dentro do limite,
 * nada muda — toda tentativa de ataque continua detectada e gravada como
 * BLOQUEADA pelo SecurityValidation, e toda requisição a rota existente
 * continua na trilha. Acima do limite o payload NÃO foi processado; não há
 * tentativa de ataque que tenha chegado à aplicação para registrar.
 *
 * O que fica registrado de uma recusa: a PRIMEIRA de cada cliente por janela
 * vira uma linha em `request_logs` (http 429, payload apenas com o resumo —
 * o corpo nunca é lido) e um evento `request.throttled` no log de arquivo.
 * As seguintes, na mesma janela, não escrevem nada: o volume exato do flood
 * está no contador do limiter e no access log do nginx. Escrever uma linha
 * de arquivo por recusa devolveria ao atacante a amplificação (agora em
 * disco) que este middleware existe para tirar. Ver ScanTrafficSampler.
 *
 * Relação com os limites que já existiam: `throttle:api` (por usuário/IP, no
 * grupo api) e `throttle:sensitive` (login, códigos, recuperação de senha)
 * continuam valendo e continuam sendo os mais estreitos nas rotas deles —
 * este é o TETO por cliente para o conjunto, com orçamento próprio (chaves
 * separadas no cache). Resposta: a API recebe o envelope de erro padrão
 * (ApiErrorRenderer, `too_many_requests`); a web recebe a página traduzida
 * `errors/429`. As duas com `Retry-After` e os headers de segurança (o
 * SecurityHeaders é externo a este middleware).
 *
 * Sem chave para desligar: quem precisa de mais folga (NAT corporativo,
 * escola, operadora com CGNAT) aumenta RATE_LIMIT_WEB. O valor padrão foi
 * medido — ver docs/seguranca.md, "Limite de requisições".
 */
final class EdgeRateLimit
{
    /**
     * Prefixo das chaves no cache — separado dos limiters nomeados
     * (`api`, `sensitive`), que têm orçamento próprio.
     */
    private const KEY_PREFIX = 'edge-rate-limit:';

    public function handle(Request $request, Closure $next): Response
    {
        $max = max(1, (int) config('security.rate_limit.web', 300));
        $decay = max(1, (int) config('security.rate_limit.web_decay_seconds', 60));

        $key = self::KEY_PREFIX.ClientBucket::for($request);

        $hits = RateLimiter::hit($key, $decay);

        if ($hits <= $max) {
            return $next($request);
        }

        $retryAfter = max(1, RateLimiter::availableIn($key));

        if (ScanTrafficSampler::shouldPersist(ScanTrafficSampler::THROTTLED, $request)) {
            $this->recordFirstRejection($request, $max, $decay);
        }

        // A página de erro da web sai no idioma do visitante. O SetLocale do
        // grupo `web` não chega a rodar numa requisição recusada aqui.
        app()->setLocale(EarlyLocale::resolve($request));

        throw new ThrottleRequestsException(
            __('security.throttled.title'),
            null,
            [
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ],
        );
    }

    /**
     * Primeira recusa do cliente na janela: uma linha na trilha em banco e um
     * evento no log de arquivo. Nunca lê o corpo da requisição — acima do
     * limite ele não é processado de jeito nenhum.
     */
    private function recordFirstRejection(Request $request, int $max, int $decay): void
    {
        $correlationId = CorrelationId::resolve($request);
        $endpoint = EndpointSignature::for($request);
        $clientCorrelationId = CorrelationId::fromClient($request);

        $context = [
            'correlation_id' => $correlationId,
            'client_correlation_id' => $clientCorrelationId,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'endpoint' => $endpoint,
            'limit' => $max,
            'decay_seconds' => $decay,
        ];

        try {
            RequestLog::query()->create([
                'correlation_id' => $correlationId,
                'client_correlation_id' => $clientCorrelationId,
                'ip' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'method' => $request->method(),
                'endpoint' => Str::limit($endpoint, 2000, ''),
                'payload' => [
                    '_resumo' => 'rate_limited',
                    'limite' => $max,
                    'janela_segundos' => $decay,
                ],
                'status' => RequestLogStatus::Concluida,
                'http_status_response' => 429,
                'duration_ms' => 0,
            ]);
        } catch (Throwable $exception) {
            Log::channel('request_log')->critical('request.throttled.persist_failed', [
                ...$context,
                ...PersistFailure::describe($exception),
            ]);
        }

        Log::channel('request_log')->warning('request.throttled', $context);
    }
}
