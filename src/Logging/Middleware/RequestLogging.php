<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twstec\Kit\Foundation\Logging\CorrelationId;
use Twstec\Kit\Foundation\Logging\EndpointSignature;
use Twstec\Kit\Foundation\Logging\Enums\RequestLogStatus;
use Twstec\Kit\Foundation\Logging\Models\RequestLog;
use Twstec\Kit\Foundation\Logging\PersistFailure;
use Twstec\Kit\Foundation\Logging\Redactor;
use Twstec\Kit\Foundation\Logging\ScanTrafficSampler;
use Twstec\Kit\Foundation\Security\AttackEvidence;
use Twstec\Kit\Foundation\Security\Middleware\SecurityValidation;
use Twstec\Kit\Foundation\Security\RequestInputs;

/**
 * Pipeline de logs de requisição — segundo middleware da
 * cadeia global, logo após a validação de segurança. Cobre TODAS as rotas:
 * API, navegação web autenticada, super admin (/admin) e até rotas
 * inexistentes (sinal de varredura — middleware de grupo não executa em 404,
 * por isso este é global de propósito).
 *
 * - RECEBIMENTO: persiste o log com status INICIADA IMEDIATAMENTE, antes de
 *   qualquer processamento de negócio, com payload já redigido (LGPD).
 * - TERMINATE: transição controlada para CONCLUIDA (HTTP < 500) ou ERRO
 *   (HTTP >= 500), com duração, status HTTP e mensagem de erro redigida.
 * - Um log que permanece INICIADA = requisição que não chegou ao fim
 *   (bug, timeout, queda, ataque) → investigar.
 * - tenant_uuid nasce NULL (o log é gravado antes da identificação); o
 *   ResolveTenant o preenche via RequestLog::bindTenant() quando a chave de
 *   API é válida. Log que termina sem tenant = possível ataque.
 *
 * Exclusões e resumos (config security.request_logging):
 * - excluded_paths: health checks (/up, /api/health), assets estáticos
 *   (build/*, storage/*, favicon) e preflights OPTIONS não geram log pesado
 *   para não poluir a trilha — seguem no access log do nginx e passam
 *   normalmente pela validação de segurança.
 * - summarized_paths: updates genéricos do Livewire são registrados com o
 *   payload RESUMIDO (só os nomes dos componentes) — o snapshot serializado
 *   é enorme, repetitivo e sem valor de auditoria.
 *
 * Contenção do tráfego de varredura: requisição para rota
 * INEXISTENTE (404/405 — por construção anônima, porque sem rota não há
 * sessão nem chave de API) só vai ao banco na primeira ocorrência de cada
 * cliente por janela; as seguintes ficam no log de arquivo
 * (`request.unmatched.sampled_out`). Rotas casadas — que podem ser
 * autenticadas — seguem gravando INICIADA na chegada, sempre. Tentativas
 * bloqueadas pelo SecurityValidation são gravadas por ele e nunca chegam
 * aqui. Ver Twstec\Kit\Foundation\Logging\ScanTrafficSampler.
 *
 * Tentativa de ataque que SEGUIU (modo observe do filtro, ou rota delegada à
 * vitrine do /ui): a linha é gravada SEMPRE — sem exclusão de rota leve nem
 * amostragem de varredura —, com `attack_type`, a nota da tentativa em
 * `error_message` e a evidência neutralizada (Twstec\Kit\Foundation\Security\
 * AttackEvidence) no lugar do payload. O status segue o ciclo normal
 * (INICIADA → CONCLUIDA/ERRO): "observada" = `attack_type` preenchido fora
 * da BLOQUEADA.
 *
 * Resiliência: se o banco falhar, a requisição NÃO é derrubada — a trilha
 * de arquivo (canal request_log, JSON estruturado) registra a falha.
 */
final class RequestLogging
{
    public function __construct(
        private readonly Redactor $redactor,
        private readonly AttackEvidence $evidence,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExcluded($request)) {
            return $next($request);
        }

        // Id INTERNO, sempre gerado pelo servidor. O X-Correlation-Id que o
        // cliente mandou é só um rótulo dele, guardado à parte.
        $correlationId = CorrelationId::resolve($request);

        $request->attributes->set('request_log_started_at', microtime(true));

        // Padrão da rota, nunca o caminho real: o path é dado do usuário e
        // pode carregar segredo posicional (ver EndpointSignature).
        $matchedUri = EndpointSignature::matchedUri($request);
        $endpoint = $matchedUri ?? EndpointSignature::unmatchedMarker($request);

        // Rota inexistente (404/405 de varredura — por construção anônima):
        // só a primeira de cada cliente por janela vai ao banco; as demais
        // ficam no log de arquivo. Ver ScanTrafficSampler.
        if ($matchedUri === null && self::attackType($request) === null && ! ScanTrafficSampler::shouldPersist(ScanTrafficSampler::UNMATCHED, $request)) {
            Log::channel('request_log')->info('request.unmatched.sampled_out', [
                'correlation_id' => $correlationId,
                'ip' => $request->ip(),
                'method' => $request->method(),
                'endpoint' => $endpoint,
            ]);

            $response = $next($request);
            $response->headers->set(CorrelationId::HEADER, $correlationId);

            return $response;
        }

        $this->persistStarted($request, $correlationId, $endpoint);

        $response = $next($request);

        // O header de resposta devolve o id do SERVIDOR: é ele que identifica
        // a linha da trilha e é ele que o suporte pede ao cliente.
        $response->headers->set(CorrelationId::HEADER, $correlationId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->isExcluded($request)) {
            return;
        }

        $log = $request->attributes->get('request_log');

        if (! $log instanceof RequestLog) {
            return;
        }

        $startedAt = $request->attributes->get('request_log_started_at');
        $durationMs = is_float($startedAt) ? max(0, (int) round((microtime(true) - $startedAt) * 1000)) : null;

        $httpStatus = $response->getStatusCode();
        $status = $httpStatus >= 500 ? RequestLogStatus::Erro : RequestLogStatus::Concluida;

        // A nota da tentativa observada (gravada na chegada) sobrevive à
        // conclusão; o erro de servidor, quando houver, tem precedência.
        $errorMessage = $log->error_message;

        if ($status === RequestLogStatus::Erro) {
            $captured = $request->attributes->get('request_log_error');
            $errorMessage = Str::limit(
                $this->redactor->redactString(is_string($captured) && $captured !== '' ? $captured : 'Erro interno sem mensagem capturada.'),
                1000,
                '',
            );
        }

        try {
            $log->markFinished($status, $httpStatus, $errorMessage, $durationMs);
        } catch (Throwable $exception) {
            Log::channel('request_log')->critical('request.finished.persist_failed', [
                'correlation_id' => $log->correlation_id,
                ...PersistFailure::describe($exception),
            ]);
        }

        Log::channel('request_log')->info('request.finished', [
            'correlation_id' => $log->correlation_id,
            'status' => $status->value,
            'http_status_response' => $httpStatus,
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Grava o log INICIADA imediatamente, com payload redigido (ou resumido
     * nas rotas configuradas — ex.: updates genéricos do Livewire).
     */
    private function persistStarted(Request $request, string $correlationId, string $endpoint): void
    {
        $attackType = self::attackType($request);

        // Tentativa de ataque que SEGUIU (modo observe ou rota delegada): a
        // linha guarda a evidência neutralizada — escapada e redigida, como a
        // BLOQUEADA — em vez do payload comum ou do resumo do Livewire.
        $payload = match (true) {
            $attackType !== null => $this->evidence->payload($request),
            $this->shouldSummarize($request) => $this->summarizePayload($request),
            default => $this->redactor->redactArray(RequestInputs::extract($request)),
        };

        // Correlação do cliente: já saneada (lista branca + limite), sem
        // unicidade, apenas informativa.
        $clientCorrelationId = CorrelationId::fromClient($request);

        $context = [
            'correlation_id' => $correlationId,
            'client_correlation_id' => $clientCorrelationId,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'endpoint' => $endpoint,
        ];

        try {
            $log = RequestLog::query()->create([
                'correlation_id' => $correlationId,
                'client_correlation_id' => $clientCorrelationId,
                'ip' => $request->ip(),
                'user_agent' => $attackType !== null
                    ? $this->evidence->userAgent($request)
                    : Str::limit((string) $request->userAgent(), 500, ''),
                'method' => $request->method(),
                'endpoint' => Str::limit($endpoint, 2000, ''),
                'payload' => $payload,
                'status' => RequestLogStatus::Iniciada,
                'attack_type' => $attackType,
                'error_message' => $attackType !== null
                    ? __('security.observed_log', ['type' => $attackType])
                    : null,
            ]);

            $request->attributes->set('request_log', $log);

            Log::channel('request_log')->info('request.started', $context);
        } catch (Throwable $exception) {
            // A causa é classificada: "banco fora do ar" e "colisão de chave
            // única" pedem reações diferentes (ver PersistFailure).
            Log::channel('request_log')->critical('request.started.persist_failed', [
                ...$context,
                ...PersistFailure::describe($exception),
            ]);
        }
    }

    /**
     * O que fica fora do log pesado:
     * - preflights CORS (OPTIONS);
     * - rotas leves listadas em config/security.php: health checks
     *   (/up, /api/health) e assets estáticos (build/*, storage/*, favicon).
     *
     * Todo o resto é auditado: API, navegação web autenticada, super admin
     * e rotas inexistentes (varredura/ataque).
     */
    private function isExcluded(Request $request): bool
    {
        // Tentativa de ataque nunca fica fora da trilha, nem em rota leve.
        if (self::attackType($request) !== null) {
            return false;
        }

        if ($request->isMethod('OPTIONS')) {
            return true;
        }

        /** @var list<string> $excluded */
        $excluded = config('security.request_logging.excluded_paths', []);

        return $excluded !== [] && $request->is(...$excluded);
    }

    /**
     * Tipo da tentativa de ataque que o SecurityValidation deixou seguir
     * (modo observe ou rota delegada), ou null.
     */
    private static function attackType(Request $request): ?string
    {
        $type = $request->attributes->get(SecurityValidation::ATTACK_ATTRIBUTE);

        return is_string($type) && $type !== '' ? $type : null;
    }

    /**
     * Rotas com payload resumido (config security.request_logging
     * .summarized_paths) — os updates genéricos do Livewire carregam
     * snapshots enormes e repetitivos sem valor de auditoria.
     */
    private function shouldSummarize(Request $request): bool
    {
        /** @var list<string> $summarized */
        $summarized = config('security.request_logging.summarized_paths', []);

        return $summarized !== [] && $request->is(...$summarized);
    }

    /**
     * Resumo do payload de updates Livewire: extrai apenas os nomes dos
     * componentes envolvidos (do snapshot serializado) — o restante é
     * ruído de transporte. Nunca passa pelo corpo bruto aqui.
     *
     * @return array<string, mixed>
     */
    private function summarizePayload(Request $request): array
    {
        $components = [];

        /** @var mixed $updates */
        $updates = $request->input('components', []);

        if (is_array($updates)) {
            foreach ($updates as $component) {
                if (! is_array($component)) {
                    continue;
                }

                $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
                $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

                $components[] = is_string($name) ? $name : '[desconhecido]';
            }
        }

        return [
            '_resumo' => 'livewire.update',
            'componentes' => $components,
        ];
    }
}
