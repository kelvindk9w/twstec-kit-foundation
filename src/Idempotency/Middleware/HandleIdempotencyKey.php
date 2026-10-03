<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twstec\Kit\Foundation\Idempotency\Acquisition;
use Twstec\Kit\Foundation\Idempotency\Exceptions\IdempotencyRefusedException;
use Twstec\Kit\Foundation\Idempotency\Exceptions\IdempotencyUnavailableException;
use Twstec\Kit\Foundation\Idempotency\Exceptions\MissingIdempotencyScopeException;
use Twstec\Kit\Foundation\Idempotency\Exceptions\ReleaseWithinTransaction;
use Twstec\Kit\Foundation\Idempotency\IdempotencyKey;
use Twstec\Kit\Foundation\Idempotency\IdempotencyOptions;
use Twstec\Kit\Foundation\Idempotency\IdempotencyRecord;
use Twstec\Kit\Foundation\Idempotency\IdempotencyScope;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;
use Twstec\Kit\Foundation\Idempotency\RequestFingerprint;
use Twstec\Kit\Foundation\Idempotency\StoredResponse;
use Twstec\Kit\Foundation\Logging\CorrelationId;
use Twstec\Kit\Foundation\Logging\EndpointSignature;

/**
 * `Idempotency-Key` nas escritas da API — alias `idempotent`, ligado POR ROTA
 * ou grupo:
 *
 *     Route::post('pedidos', …)->middleware('idempotent');            // chave opcional
 *     Route::post('pedidos', …)->middleware('idempotent:required');   // chave obrigatória
 *     Route::post('tokens', …)->middleware('idempotent:required,withhold,keep=data.uuid');
 *
 * (ver IdempotencyOptions; HandleIdempotencyKey::using() monta a string.)
 *
 * O QUE ACONTECE com a chave, em `POST`/`PATCH` (idempotency.methods):
 *
 * 1. a chave é validada (formato) e ganha um ESCOPO — conta + credencial, ou
 *    a pessoa (IdempotencyScope). Sem escopo, a requisição não executa (falha
 *    fechada);
 * 2. o banco decide quem executa: um INSERT "em processamento" sobre a
 *    unicidade (escopo, chave) — ver IdempotencyStore. Só UMA requisição
 *    ganha;
 * 3. quem perdeu encontra a linha da vencedora:
 *    - corpo diferente (hash da forma canônica — RequestFingerprint) → 422
 *      `idempotency_key_reused`, sem executar;
 *    - já concluída → REPLAY da resposta original (StoredResponse), sem
 *      executar;
 *    - ainda em processamento → 409 `idempotency_request_in_progress` com
 *      Retry-After (ou, com `idempotency.wait_ms`, espera curta pela
 *      vencedora e devolve o replay);
 * 4. a vencedora executa e o resultado decide o destino da chave:
 *    - 2xx/3xx: guardado (cifrado) — as repetições recebem o replay;
 *    - 5xx, exceção, ou 4xx que não congela: a linha é APAGADA e a chave fica
 *      livre para a próxima tentativa;
 *    - sem resultado registrado (processo morto, falha ao gravar a conclusão):
 *      a linha fica "em processamento" — 409 até vencer, salvo prazo de
 *      retomada configurado (IDEMPOTENCY_ABANDONED_TAKEOVER_SECONDS). No modo
 *      `transactional`, a rota e a conclusão são confirmadas juntas, e a
 *      retomada depois de `lock_seconds` é segura. Erro de servidor nunca congela a
 *      chave; erro de validação (422) só congela com
 *      `idempotency.freeze_validation_errors`; os demais 4xx, só os listados
 *      em `idempotency.freeze_client_errors` — e 401, 403, 408, 409, 425 e
 *      429 nunca.
 *
 * ORDEM: depois da autenticação e da autorização (lista de prioridade do
 * kernel — o FoundationServiceProvider põe este middleware depois do
 * `Authorize`, que já vem depois da autenticação e do limite). Um replay
 * nunca dispensa a autenticação nem a autorização da rota. Confirmação de uso
 * único (ex.: `sensitive.token`) vai DEPOIS deste middleware, para o replay
 * não precisar de uma confirmação nova (ver docs/api.md).
 *
 * TRILHA: recusas e replays vão ao canal `request_log` (`api.idempotency.*`)
 * com o correlation_id da requisição e a impressão curta da chave — nunca a
 * chave. O replay leva também o id da requisição original.
 */
final class HandleIdempotencyKey
{
    public const ALIAS = 'idempotent';

    /**
     * Intervalo de consulta da espera curta (milissegundos).
     */
    private const POLL_MS = 100;

    /**
     * Teto da espera curta (milissegundos), qualquer que seja a configuração.
     */
    private const MAX_WAIT_MS = 10000;

    /**
     * Status de erro de cliente que NUNCA congelam a chave: a requisição não
     * chegou a executar (autenticação, autorização, limite) ou o erro é
     * transitório.
     *
     * @var list<int>
     */
    private const NEVER_FROZEN = [401, 403, 408, 409, 425, 429];

    public function __construct(private readonly IdempotencyStore $store) {}

    /**
     * A declaração do middleware na rota, montada.
     *
     * @param  list<string>  $keep
     */
    public static function using(bool $required = false, bool $withhold = false, array $keep = [], bool $transactional = false): string
    {
        return self::ALIAS.':'.implode(',', (new IdempotencyOptions($required, $withhold, $keep, $transactional))->toParameters());
    }

    public function handle(Request $request, Closure $next, string ...$parameters): Response
    {
        if (! in_array(strtoupper($request->getMethod()), self::methods(), true)) {
            return $next($request);
        }

        $options = IdempotencyOptions::parse(array_values($parameters));

        try {
            $key = IdempotencyKey::fromRequest($request);
        } catch (IdempotencyRefusedException $refused) {
            $this->logRefusal($request, 'invalid_key', null);

            throw $refused;
        }

        if ($key === null) {
            if ($options->required) {
                $this->logRefusal($request, 'missing_key', null);

                throw IdempotencyRefusedException::missing();
            }

            return $next($request);
        }

        $route = EndpointSignature::for($request);
        $scope = IdempotencyScope::resolve($request);

        if ($scope === null) {
            Log::channel('request_log')->error('api.idempotency.scope_missing', [
                ...$this->context($request, $key),
            ]);

            throw MissingIdempotencyScopeException::forRoute($route);
        }

        // Sem cifra utilizável a resposta não poderia ser guardada: recusa
        // ANTES de executar (falha fechada), em vez de executar e deixar a
        // chave presa com o efeito já gravado.
        $encryptionProblem = StoredResponse::encryptionProblem();

        if ($encryptionProblem !== null) {
            Log::channel('request_log')->critical('api.idempotency.encryption_unavailable', [
                ...$this->context($request, $key),
                'exception' => $encryptionProblem,
            ]);

            throw IdempotencyUnavailableException::encryptionUnavailable($encryptionProblem);
        }

        $scopeHash = IdempotencyScope::hash($scope);
        $keyHash = $key->hash();
        $requestHash = RequestFingerprint::hash($request);

        if ($options->transactional) {
            $this->ensureSameConnection();
        }

        // Prazo da retomada de uma execução abandonada: no modo transacional,
        // "em processamento" quer dizer "nada confirmado" — a retomada é
        // segura; nas rotas comuns, só com prazo configurado (padrão: nunca).
        $takeoverAfter = $options->transactional ? IdempotencyStore::lockSeconds() : IdempotencyStore::abandonedTakeoverSeconds();

        $acquisition = $this->acquire($scopeHash, $keyHash, $requestHash, $request, $route, $takeoverAfter);

        if ($acquisition->existing !== null) {
            return $this->answerExisting($acquisition->existing, $requestHash, $request, $key);
        }

        /** @var string $owner */
        $owner = $acquisition->owner;

        if ($acquisition->takeover === Acquisition::ABANDONED) {
            Log::channel('request_log')->warning('api.idempotency.abandoned_taken_over', $this->context($request, $key));
        }

        if ($options->transactional) {
            return $this->runAtomically($request, $next, $options, $scopeHash, $keyHash, $owner, $key);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->store->release($scopeHash, $keyHash, $owner);

            throw $exception;
        }

        $this->finish($response, $options, $scopeHash, $keyHash, $owner, $request, $key);

        return $response;
    }

    /**
     * Modo `transactional`: a rota e a conclusão da chave na MESMA transação.
     *
     * - status que congela: a resposta é guardada (cifrada) e a chave marcada
     *   concluída DENTRO da transação; o commit confirma os dois juntos;
     * - status que não congela (5xx, validação…): a transação é desfeita — o
     *   que a rota gravou não fica — e a chave é liberada;
     * - falha ao guardar a conclusão (ou exceção da rota): a transação é
     *   desfeita, a chave é liberada e a falha sobe (500). O cliente não
     *   recebe um "201" de algo que não foi confirmado, e a nova tentativa
     *   executa uma vez só.
     *
     * Um processo que morre no meio deixa a linha "em processamento" sem nada
     * confirmado: depois de `idempotency.lock_seconds`, a retomada é segura.
     */
    private function runAtomically(Request $request, Closure $next, IdempotencyOptions $options, string $scopeHash, string $keyHash, string $owner, IdempotencyKey $key): Response
    {
        try {
            /** @var Response */
            return $this->store->connection()->transaction(function () use ($request, $next, $options, $scopeHash, $keyHash, $owner): Response {
                /** @var Response $response */
                $response = $next($request);
                $status = $response->getStatusCode();

                if (! self::freezes($status)) {
                    throw new ReleaseWithinTransaction($response);
                }

                $captured = StoredResponse::capture($response, $options);

                if (! $this->store->complete($scopeHash, $keyHash, $owner, $status, $captured['encrypted'], $captured['withheld'])) {
                    throw new LogicException('Idempotência: a chave não pertence mais a esta execução; a transação foi desfeita.');
                }

                return $response;
            });
        } catch (ReleaseWithinTransaction $release) {
            $this->releaseQuietly($scopeHash, $keyHash, $owner, $request, $key);

            Log::channel('request_log')->info('api.idempotency.released', [
                ...$this->context($request, $key),
                'response_status' => $release->response->getStatusCode(),
                'rolled_back' => true,
            ]);

            return $release->response;
        } catch (Throwable $exception) {
            Log::channel('request_log')->critical('api.idempotency.persist_failed', [
                ...$this->context($request, $key),
                'exception' => $exception::class,
                'rolled_back' => true,
            ]);

            $this->releaseQuietly($scopeHash, $keyHash, $owner, $request, $key);

            throw $exception;
        }
    }

    /**
     * Libera a chave sem mascarar a falha original (o banco pode estar fora).
     * Se nem isso der, a linha espera o prazo do modo transacional — e, como
     * nada foi confirmado, a retomada é segura.
     */
    private function releaseQuietly(string $scopeHash, string $keyHash, string $owner, Request $request, IdempotencyKey $key): void
    {
        try {
            $this->store->release($scopeHash, $keyHash, $owner);
        } catch (Throwable $exception) {
            Log::channel('request_log')->error('api.idempotency.release_failed', [
                ...$this->context($request, $key),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * O modo transacional só é atômico se a rota grava na MESMA conexão da
     * tabela de chaves (a padrão, salvo IDEMPOTENCY_CONNECTION).
     */
    private function ensureSameConnection(): void
    {
        if ($this->store->connection()->getName() !== DB::connection()->getName()) {
            throw new LogicException('idempotent:transactional exige que a tabela idempotency_keys use a conexão padrão (IDEMPOTENCY_CONNECTION vazio): a rota e a conclusão da chave precisam estar na mesma transação.');
        }
    }

    /**
     * Adquire a chave — com a espera curta, se configurada, enquanto outra
     * execução a segura.
     */
    private function acquire(string $scopeHash, string $keyHash, string $requestHash, Request $request, string $route, int $takeoverAfter): Acquisition
    {
        $polls = (int) ceil(min(max(0, (int) config('idempotency.wait_ms', 0)), self::MAX_WAIT_MS) / self::POLL_MS);
        $correlationId = CorrelationId::resolve($request);

        while (true) {
            $acquisition = $this->store->acquire($scopeHash, $keyHash, $requestHash, strtoupper($request->getMethod()), $route, $correlationId, $takeoverAfter);
            $existing = $acquisition->existing;

            if ($existing === null || $existing->completed() || ! hash_equals($existing->requestHash, $requestHash) || $polls-- <= 0) {
                return $acquisition;
            }

            Sleep::for(self::POLL_MS)->milliseconds();
        }
    }

    private function answerExisting(IdempotencyRecord $record, string $requestHash, Request $request, IdempotencyKey $key): Response
    {
        if (! hash_equals($record->requestHash, $requestHash)) {
            $this->logRefusal($request, 'key_reused', $key);

            throw IdempotencyRefusedException::reused();
        }

        if (! $record->completed()) {
            $this->logRefusal($request, 'in_progress', $key);

            throw IdempotencyRefusedException::inProgress();
        }

        $readable = $record->response !== null && StoredResponse::readable($record);

        Log::channel('request_log')->info('api.idempotency.replayed', [
            ...$this->context($request, $key),
            'original_correlation_id' => $record->correlationId,
            'response_status' => $record->responseStatus,
            'body_withheld' => $record->withheld || ! $readable,
        ]);

        if (! $readable) {
            Log::channel('request_log')->error('api.idempotency.replay_unreadable', [
                ...$this->context($request, $key),
                'original_correlation_id' => $record->correlationId,
            ]);
        }

        return StoredResponse::replay($record);
    }

    /**
     * Guarda o resultado (cifrado) ou libera a chave, conforme o status.
     */
    private function finish(Response $response, IdempotencyOptions $options, string $scopeHash, string $keyHash, string $owner, Request $request, IdempotencyKey $key): void
    {
        $status = $response->getStatusCode();

        try {
            if (! self::freezes($status)) {
                $this->store->release($scopeHash, $keyHash, $owner);

                Log::channel('request_log')->info('api.idempotency.released', [
                    ...$this->context($request, $key),
                    'response_status' => $status,
                ]);

                return;
            }

            $captured = StoredResponse::capture($response, $options);

            if (! $this->store->complete($scopeHash, $keyHash, $owner, $status, $captured['encrypted'], $captured['withheld'])) {
                Log::channel('request_log')->warning('api.idempotency.lost_ownership', $this->context($request, $key));
            }
        } catch (Throwable $exception) {
            // A rota JÁ executou e pode ter confirmado o efeito: a resposta
            // segue para o cliente, e a linha fica "em processamento". As
            // repetições recebem 409 — por padrão até a chave vencer
            // (IDEMPOTENCY_ABANDONED_TAKEOVER_SECONDS=0), nunca uma segunda
            // execução. Para fechar essa janela, use o modo `transactional`.
            Log::channel('request_log')->critical('api.idempotency.persist_failed', [
                ...$this->context($request, $key),
                'exception' => $exception::class,
                'rolled_back' => false,
            ]);
        }
    }

    /**
     * Este status congela a chave (guarda a resposta para o replay)?
     */
    public static function freezes(int $status): bool
    {
        if ($status < 400) {
            return true;
        }

        if ($status >= 500 || in_array($status, self::NEVER_FROZEN, true)) {
            return false;
        }

        if ($status === 422) {
            return (bool) config('idempotency.freeze_validation_errors', false);
        }

        /** @var list<int> $frozen */
        $frozen = array_map('intval', (array) config('idempotency.freeze_client_errors', []));

        return in_array($status, $frozen, true);
    }

    /**
     * @return list<string>
     */
    private static function methods(): array
    {
        return array_values(array_map(
            static fn (mixed $method): string => strtoupper((string) $method),
            (array) config('idempotency.methods', ['POST', 'PATCH']),
        ));
    }

    private function logRefusal(Request $request, string $reason, ?IdempotencyKey $key): void
    {
        Log::channel('request_log')->warning('api.idempotency.refused', [
            ...$this->context($request, $key),
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request, ?IdempotencyKey $key): array
    {
        return [
            'correlation_id' => CorrelationId::resolve($request),
            'method' => $request->getMethod(),
            'endpoint' => EndpointSignature::for($request),
            'key_fingerprint' => $key?->fingerprint(),
        ];
    }
}
