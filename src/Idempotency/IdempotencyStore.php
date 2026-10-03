<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A tabela `idempotency_keys` — onde a CORRIDA é decidida.
 *
 * ADQUIRIR (acquire) é um único INSERT da linha "em processamento" com
 * `ON CONFLICT DO NOTHING` (PostgreSQL; `INSERT OR IGNORE` no SQLite) sobre
 * a unicidade (`scope_hash`, `key_hash`). Das requisições simultâneas com a
 * mesma chave, o banco deixa UMA inserir; as outras recebem 0 linhas e leem
 * a linha da vencedora. Nenhuma trava de cache, nenhum "SELECT e depois
 * INSERT" (que deixaria as duas passarem no meio). O INSERT roda fora de
 * transação do aplicativo — comprometido na hora, visível para os outros
 * processos.
 *
 * RETOMAR uma linha que venceu (`expires_at` passou) ou cuja execução foi
 * abandonada (em processamento além de `locked_until` — só quando a rota tem
 * prazo de retomada; sem prazo, `locked_until` é nulo e a linha espera
 * vencer) é um UPDATE condicionado ao DONO atual (`owner`): se duas tentativas
 * disputam a retomada, só a primeira muda a linha; a segunda vê 0 linhas.
 *
 * FINALIZAR e LIBERAR também são condicionados ao dono: uma execução antiga
 * que termina depois de ter sido retomada não sobrescreve a nova.
 */
final class IdempotencyStore
{
    public const TABLE = 'idempotency_keys';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    /**
     * Tentativas de adquirir quando a linha some ou muda no meio (liberada ou
     * retomada por outra requisição entre o INSERT e a leitura).
     */
    private const ACQUIRE_ATTEMPTS = 3;

    public function acquire(
        string $scopeHash,
        string $keyHash,
        string $requestHash,
        string $method,
        string $route,
        ?string $correlationId,
        ?int $takeoverAfterSeconds = null,
    ): Acquisition {
        $last = null;

        for ($attempt = 0; $attempt < self::ACQUIRE_ATTEMPTS; $attempt++) {
            $now = CarbonImmutable::now();
            $owner = (string) Str::uuid();
            $fresh = $this->freshRow($requestHash, $method, $route, $owner, $correlationId, $now, $takeoverAfterSeconds ?? self::abandonedTakeoverSeconds());

            $inserted = $this->table()->insertOrIgnore([
                'scope_hash' => $scopeHash,
                'key_hash' => $keyHash,
                ...$fresh,
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return Acquisition::owned($owner);
            }

            $record = $this->find($scopeHash, $keyHash);

            if ($record === null) {
                // Liberada entre o INSERT e a leitura: tenta de novo.
                continue;
            }

            $last = $record;
            $takeover = $this->takeoverReason($record, $requestHash, $now);

            if ($takeover === null) {
                return Acquisition::existing($record);
            }

            $taken = $this->table()
                ->where('scope_hash', $scopeHash)
                ->where('key_hash', $keyHash)
                ->where('owner', $record->owner)
                ->update([...$fresh, 'created_at' => $now]);

            if ($taken === 1) {
                return Acquisition::owned($owner, $takeover);
            }
        }

        return $last !== null
            ? Acquisition::existing($last)
            : throw new RuntimeException('Não foi possível adquirir a chave de idempotência (a linha mudou a cada tentativa).');
    }

    /**
     * Guarda o resultado e encerra o "em processamento". Falso quando a linha
     * já não é deste dono (foi retomada por outra execução).
     */
    public function complete(string $scopeHash, string $keyHash, string $owner, int $status, string $encryptedResponse, bool $withheld): bool
    {
        return $this->table()
            ->where('scope_hash', $scopeHash)
            ->where('key_hash', $keyHash)
            ->where('owner', $owner)
            ->where('status', self::PROCESSING)
            ->update([
                'status' => self::COMPLETED,
                'locked_until' => null,
                'response_status' => $status,
                'response' => $encryptedResponse,
                'response_withheld' => $withheld,
                'updated_at' => CarbonImmutable::now(),
            ]) === 1;
    }

    /**
     * Apaga a linha deste dono: a chave fica livre para uma nova execução
     * (erro de servidor, erro de cliente que não congela, exceção).
     */
    public function release(string $scopeHash, string $keyHash, string $owner): void
    {
        $this->table()
            ->where('scope_hash', $scopeHash)
            ->where('key_hash', $keyHash)
            ->where('owner', $owner)
            ->where('status', self::PROCESSING)
            ->delete();
    }

    public function find(string $scopeHash, string $keyHash): ?IdempotencyRecord
    {
        $row = $this->table()
            ->where('scope_hash', $scopeHash)
            ->where('key_hash', $keyHash)
            ->first();

        return $row === null ? null : IdempotencyRecord::fromRow($row);
    }

    /**
     * Remove as chaves vencidas até `$now`, em lotes. Devolve quantas saíram.
     */
    public function prune(CarbonImmutable $now, int $batch = 1000): int
    {
        $deleted = 0;

        do {
            $ids = $this->table()
                ->where('expires_at', '<=', $now)
                ->orderBy('id')
                ->limit($batch)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += $this->table()->whereIn('id', $ids)->delete();
        } while (count($ids) === $batch);

        return $deleted;
    }

    /**
     * Pode retomar? `expired` (a chave venceu: vale como nova, qualquer que
     * seja o corpo) ou `abandoned` (em processamento além do prazo, com o
     * MESMO corpo — corpo diferente continua sendo recusa). Null = não.
     */
    private function takeoverReason(IdempotencyRecord $record, string $requestHash, CarbonImmutable $now): ?string
    {
        if ($record->expiresAt->lessThanOrEqualTo($now)) {
            return Acquisition::EXPIRED;
        }

        if ($record->status === self::PROCESSING
            && $record->lockedUntil !== null
            && $record->lockedUntil->lessThanOrEqualTo($now)
            && hash_equals($record->requestHash, $requestHash)) {
            return Acquisition::ABANDONED;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function freshRow(string $requestHash, string $method, string $route, string $owner, ?string $correlationId, CarbonImmutable $now, int $takeoverAfterSeconds): array
    {
        return [
            'request_hash' => $requestHash,
            'method' => Str::limit($method, 10, ''),
            'route' => Str::limit($route, 255, ''),
            'status' => self::PROCESSING,
            'owner' => $owner,
            // Sem prazo (null) = a execução abandonada NUNCA é retomada; a
            // linha só sai quando vence (expires_at).
            'locked_until' => $takeoverAfterSeconds > 0 ? $now->addSeconds($takeoverAfterSeconds) : null,
            'correlation_id' => $correlationId,
            'response_status' => null,
            'response' => null,
            'response_withheld' => false,
            'expires_at' => $now->addHours(self::ttlHours()),
            'updated_at' => $now,
        ];
    }

    private function table(): Builder
    {
        return $this->connection()->table(self::TABLE);
    }

    public static function ttlHours(): int
    {
        return max(1, (int) config('idempotency.ttl_hours', 24));
    }

    /**
     * Prazo do modo `transactional` (a retomada é segura: nada foi confirmado).
     */
    public static function lockSeconds(): int
    {
        return max(1, (int) config('idempotency.lock_seconds', 120));
    }

    /**
     * Prazo da retomada nas rotas comuns. 0 = nunca (falha fechada).
     */
    public static function abandonedTakeoverSeconds(): int
    {
        return max(0, (int) config('idempotency.abandoned_takeover_seconds', 0));
    }

    /**
     * A conexão da tabela — a do modo `transactional` abre a transação nela.
     */
    public function connection(): ConnectionInterface
    {
        $configured = config('idempotency.connection');

        return DB::connection(is_string($configured) && $configured !== '' ? $configured : null);
    }
}
