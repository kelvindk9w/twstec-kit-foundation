<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Security\Middleware;

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
use Twstec\Kit\Foundation\Security\AttackDetector;
use Twstec\Kit\Foundation\Security\AttackEvidence;
use Twstec\Kit\Foundation\Security\RequestInputs;
use Twstec\Kit\Foundation\Security\ValidationMode;

/**
 * Validação de segurança de entrada — PRIMEIRO middleware de payload da
 * cadeia global (web e api).
 *
 * Pipeline: receber → validação de segurança → sanitização/redaction → persistir.
 *
 * O que é inspecionado (Twstec\Kit\Foundation\Security\RequestInputs): query e corpo
 * SEPARADOS (formulário ou JSON, aninhado, chaves inclusive), metadados de
 * arquivos enviados (nome e MIME declarados — o conteúdo nunca é lido),
 * corpo não estruturado, os cabeçalhos de security.validation
 * .inspected_headers e o caminho decodificado (parâmetros de rota).
 *
 * O que acontece com uma tentativa detectada depende do MODO
 * (config security.validation.mode — ver Twstec\Kit\Foundation\Security\ValidationMode):
 * - `observe` (padrão): a requisição SEGUE. A tentativa fica marcada no
 *   atributo ATTACK_ATTRIBUTE da requisição; o RequestLogging grava a linha
 *   da trilha com `attack_type` e o payload NEUTRALIZADO (escapado +
 *   redigido), sem amostragem nem exclusão, e o evento `security.observed`
 *   vai para o log de arquivo.
 * - `block`: NUNCA bloqueia silenciosamente — grava request log com status
 *   BLOQUEADA, payload sanitizado + metadados da tentativa (IP, endpoint,
 *   tipo), registra `security.blocked` no log de arquivo e responde 422
 *   genérico (não revela o que foi detectado) com
 *   X-Correlation-Id.
 *
 * É também quem resolve o correlation_id da requisição (primeira peça da
 * cadeia de payload), propagando para os demais middlewares e logs.
 *
 * Teto de inspeção (config security.validation.max_inspected_bytes): a
 * detecção roda antes da autenticação, então o volume que ela varre é
 * limitado. Acima do teto a requisição é RECUSADA com 413, EM QUALQUER MODO,
 * e gravada como BLOQUEADA (`payload_too_large`) com o tamanho, nunca o
 * conteúdo — aceitar o excedente sem inspeção abriria o atalho de esconder o
 * ataque no fim de um corpo grande. Vale também para os caminhos delegados.
 * Antes deste middleware roda o EdgeRateLimit: acima do teto de requisições
 * por cliente o corpo nem chega a ser lido.
 *
 * Delegação (config security.validation): os formulários demo do /ui são
 * uma vitrine de defesa EM CAMADAS — para eles, a detecção é feita pela
 * própria aplicação (o MESMO AttackDetector), que registra a tentativa em
 * form_submissions com payload inerte. Isso vale para o path do POST
 * clássico (delegated_paths) e para updates Livewire em que TODOS os
 * componentes envolvidos são autodefendidos (delegated_components). A
 * requisição delegada segue em qualquer modo, mas a linha da trilha também
 * sai marcada e neutralizada, como no modo observe.
 */
final class SecurityValidation
{
    /**
     * `attack_type` da requisição recusada por passar do teto de inspeção.
     */
    public const OVERSIZED = 'payload_too_large';

    /**
     * Atributo da requisição com o tipo da tentativa que SEGUIU (modo observe
     * ou rota delegada). O RequestLogging lê daqui para gravar a linha da
     * trilha marcada e neutralizada.
     */
    public const ATTACK_ATTRIBUTE = 'security_attack_type';

    /**
     * Atributo com a origem da detecção fora do corpo (`headers`/`path`), para
     * a evidência dizer onde a tentativa estava sem copiar o caminho.
     */
    public const ATTACK_SOURCE_ATTRIBUTE = 'security_attack_source';

    public function __construct(
        private readonly AttackDetector $detector,
        private readonly AttackEvidence $evidence,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Primeira peça da cadeia: gera o id INTERNO da requisição (nunca
        // aceito do cliente — ver CorrelationId) e o propaga adiante.
        $correlationId = CorrelationId::resolve($request);

        $inputs = RequestInputs::forInspection($request);

        // Teto do que se inspeciona ANTES da autenticação: sem ele, qualquer
        // anônimo compra regex sobre o corpo inteiro a cada requisição. O
        // excedente é RECUSADO, não aceito sem inspeção — ver
        // config security.validation.max_inspected_bytes.
        $maxBytes = max(1, (int) config('security.validation.max_inspected_bytes', 1048576));

        if ($this->detector->exceedsInspectionBudget($inputs, $maxBytes)) {
            $this->registerBlockedAttempt($request, $correlationId, self::OVERSIZED, 413, [
                '_resumo' => 'payload_acima_do_limite_de_inspecao',
                'limite_bytes' => $maxBytes,
                'content_length' => (int) $request->header('Content-Length', '0'),
            ]);

            return response()
                ->json([
                    'message' => __('security.payload_too_large'),
                    'correlation_id' => $correlationId,
                ], 413)
                ->header(CorrelationId::HEADER, $correlationId);
        }

        [$attackType, $source] = $this->inspect($request, $inputs);

        if ($attackType === null) {
            return $next($request);
        }

        $request->attributes->set(self::ATTACK_ATTRIBUTE, $attackType);

        if ($source !== null) {
            $request->attributes->set(self::ATTACK_SOURCE_ATTRIBUTE, $source);
        }

        // Rotas/componentes delegados: a camada da aplicação roda o MESMO
        // detector e registra a tentativa (vitrine de segurança do /ui).
        if ($this->isDelegated($request)) {
            return $next($request);
        }

        if (ValidationMode::current() === ValidationMode::Observe) {
            Log::channel('request_log')->warning('security.observed', [
                'correlation_id' => $correlationId,
                'attack_type' => $attackType,
                'ip' => $request->ip(),
                'method' => $request->method(),
                'endpoint' => EndpointSignature::for($request),
            ]);

            return $next($request);
        }

        $this->registerBlockedAttempt($request, $correlationId, $attackType, 422);

        return response()
            ->json([
                'message' => __('security.blocked'),
                'correlation_id' => $correlationId,
            ], 422)
            ->header(CorrelationId::HEADER, $correlationId);
    }

    /**
     * Roda a detecção sobre as fontes do corpo e, em seguida, sobre os
     * cabeçalhos configurados e o caminho. Devolve o tipo e, quando a
     * tentativa estava fora do corpo, de onde ela veio.
     *
     * @param  array<string, mixed>  $inputs
     * @return array{0: ?string, 1: ?string}
     */
    private function inspect(Request $request, array $inputs): array
    {
        $type = $this->detector->detect($inputs);

        if ($type !== null) {
            return [$type, null];
        }

        $envelope = RequestInputs::envelope($request);

        if (($type = $this->detector->detect($envelope['headers'])) !== null) {
            return [$type, 'headers'];
        }

        if (($type = $this->detector->detectInString($envelope['path'])) !== null) {
            return [$type, 'path'];
        }

        return [null, null];
    }

    /**
     * A detecção é delegada à camada da aplicação? Vale quando o path está
     * em delegated_paths OU quando é um update Livewire em que TODOS os
     * componentes envolvidos estão em delegated_components (autodefendidos:
     * rodam o mesmo AttackDetector e registram a tentativa).
     */
    private function isDelegated(Request $request): bool
    {
        /** @var list<string> $paths */
        $paths = config('security.validation.delegated_paths', []);

        if ($paths !== [] && $request->is(...$paths)) {
            return true;
        }

        // Endpoint de atualização de componentes (config: Livewire 4 ofusca o
        // path do update — livewire-<hash>/update — e o /admin tem o seu).
        /** @var list<string> $componentPaths */
        $componentPaths = (array) config('security.validation.livewire_paths', []);

        if ($componentPaths === [] || ! $request->is(...$componentPaths)) {
            return false;
        }

        /** @var list<string> $allowed */
        $allowed = config('security.validation.delegated_components', []);

        if ($allowed === []) {
            return false;
        }

        /** @var mixed $components */
        $components = $request->input('components', []);

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            if (! is_array($component)) {
                return false;
            }

            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;

            if (! is_string($name) || ! in_array($name, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Persiste a tentativa de ataque: payload sanitizado/escapado + redigido
     * (dupla camada), com os metadados da tentativa.
     *
     * @param  array<string, mixed>|null  $summary  payload já resumido (usado
     *                                              quando o corpo NÃO deve ser
     *                                              copiado — ex.: acima do teto)
     */
    private function registerBlockedAttempt(Request $request, string $correlationId, string $attackType, int $httpStatus, ?array $summary = null): void
    {
        $payload = $summary ?? $this->evidence->payload($request);

        // Padrão da rota, nunca o caminho real (ver EndpointSignature): a
        // tentativa de ataque também pode chegar por uma URL que carrega
        // segredo no path.
        $endpoint = EndpointSignature::for($request);

        $clientCorrelationId = CorrelationId::fromClient($request);

        $context = [
            'correlation_id' => $correlationId,
            'client_correlation_id' => $clientCorrelationId,
            'attack_type' => $attackType,
            'ip' => $request->ip(),
            'method' => $request->method(),
            'endpoint' => $endpoint,
        ];

        try {
            RequestLog::query()->create([
                'correlation_id' => $correlationId,
                'client_correlation_id' => $clientCorrelationId,
                'ip' => $request->ip(),
                'user_agent' => $this->evidence->userAgent($request),
                'method' => $request->method(),
                'endpoint' => Str::limit($endpoint, 2000, ''),
                'payload' => $payload,
                'status' => RequestLogStatus::Bloqueada,
                'attack_type' => $attackType,
                'http_status_response' => $httpStatus,
                'error_message' => $attackType === self::OVERSIZED
                    ? __('security.payload_too_large_log', ['bytes' => (int) config('security.validation.max_inspected_bytes')])
                    : __('security.blocked_log', ['type' => $attackType]),
            ]);
        } catch (Throwable $exception) {
            // Falha de banco NÃO pode impedir o bloqueio nem apagar a evidência:
            // a trilha de arquivo sobrevive à falha do banco.
            Log::channel('request_log')->critical('security.blocked.persist_failed', [
                ...$context,
                ...PersistFailure::describe($exception),
            ]);
        }

        Log::channel('request_log')->warning('security.blocked', $context);
    }
}
