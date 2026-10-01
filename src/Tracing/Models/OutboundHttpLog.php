<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Twstec\Kit\Foundation\Audit\Contracts\NotAudited;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;
use Twstec\Kit\Foundation\Tracing\Support\OutboundHttpLogTrigger;

/**
 * Uma CHAMADA HTTP DE SAÍDA (uma tentativa) — append-only.
 *
 * Complementa `request_logs` (o que ENTROU) com o que SAIU: a requisição, o
 * job e a chamada ao serviço externo se juntam pelo `correlation_id`.
 *
 * Quem grava: Tracing\Http\OutboundHttpTrail, a partir do middleware global
 * do cliente `Http` — nunca `create()` solto (a redação mora lá). A linha
 * guarda destino (host + rota normalizada + nomes dos parâmetros da query),
 * método, status, duração, tentativa, tamanhos, correlation_id e conta. Corpo
 * só quando a chamada pediu (`withBodyInTrail()`), e redigido. Cabeçalhos,
 * nunca.
 *
 * Imutabilidade, em três camadas (as mesmas de `audit_events`):
 * 1. instância: `updating`/`deleting` lançam AppendOnlyViolationException;
 * 2. query em massa pelo Eloquent: OutboundHttpLogBuilder recusa;
 * 3. banco (PostgreSQL): gatilho OutboundHttpLogTrigger.
 * A única remoção é a poda por idade (`outbound-http:prune`).
 *
 * Fora da trilha de auditoria (NotAudited): a chamada é efeito de uma ação,
 * não ação.
 *
 * Conexão: `tracing.http.trail.connection` (vazio = a padrão). Uma conexão
 * própria tira a gravação da transação da aplicação — ver docs/logs-lgpd.md.
 *
 * @property string $uuid
 * @property string|null $correlation_id
 * @property string|null $correlation_origin
 * @property string|null $tenant_uuid
 * @property string $method
 * @property string $host
 * @property string $path
 * @property list<string>|null $query_keys
 * @property int|null $attempt
 * @property int|null $http_status
 * @property int $duration_ms
 * @property int|null $request_bytes
 * @property int|null $response_bytes
 * @property string|null $error
 * @property array<array-key, mixed>|null $request_body
 * @property array<array-key, mixed>|null $response_body
 * @property CarbonInterface $created_at
 */
class OutboundHttpLog extends Model implements NotAudited
{
    use HasUuids, RoutesByUuid;

    public const UPDATED_AT = null;

    protected $table = OutboundHttpLogTrigger::TABLE;

    /**
     * Graváveis só na criação (INSERT) — depois disso, nada muda.
     *
     * @var list<string>
     */
    protected $fillable = [
        'correlation_id',
        'correlation_origin',
        'tenant_uuid',
        'method',
        'host',
        'path',
        'query_keys',
        'attempt',
        'http_status',
        'duration_ms',
        'request_bytes',
        'response_bytes',
        'error',
        'request_body',
        'response_body',
    ];

    public function getConnectionName(): ?string
    {
        $configured = config('tracing.http.trail.connection');

        return is_string($configured) && $configured !== '' ? $configured : parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'query_keys' => 'array',
            'request_body' => 'array',
            'response_body' => 'array',
            'attempt' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'request_bytes' => 'integer',
            'response_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @param  Builder  $query
     * @return OutboundHttpLogBuilder<static>
     */
    public function newEloquentBuilder($query): OutboundHttpLogBuilder
    {
        return new OutboundHttpLogBuilder($query);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw AppendOnlyViolationException::outboundUpdateAttempted();
        });

        static::deleting(function (): void {
            throw AppendOnlyViolationException::outboundDeleteAttempted();
        });
    }

    /**
     * A PODA POR IDADE — a única remoção da trilha. Apaga em lotes (cada lote
     * na sua transação, com a flag do gatilho ligada só ali) tudo o que foi
     * gravado antes de `$cutoff`. Devolve quantas linhas saíram.
     */
    public static function pruneOlderThan(CarbonInterface $cutoff, int $chunk = 1000): int
    {
        $connection = (new static)->getConnection();
        $total = 0;

        do {
            $deleted = $connection->transaction(function () use ($connection, $cutoff, $chunk): int {
                $ids = static::query()
                    ->where('created_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit($chunk)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return 0;
                }

                OutboundHttpLogTrigger::allowPruneInCurrentTransaction($connection);

                try {
                    return (int) OutboundHttpLogBuilder::whilePruning(
                        fn (): mixed => static::query()->whereKey($ids)->delete(),
                    );
                } finally {
                    OutboundHttpLogTrigger::allowPruneInCurrentTransaction($connection, false);
                }
            });

            $total += $deleted;
        } while ($deleted === $chunk);

        return $total;
    }
}
