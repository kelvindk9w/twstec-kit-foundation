<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Support\AuditEventTrigger;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;

/**
 * Evento da TRILHA DE AUDITORIA DE AÇÕES — quem fez o quê, em qual registro,
 * mudando o quê, de onde. Append-only.
 *
 * Complementa `request_logs` (a trilha de REQUISIÇÕES): a linha de lá prova
 * que houve a chamada; a daqui diz qual ação de negócio ela executou. As
 * duas se juntam pelo `correlation_id`.
 *
 * Quem grava: Twstec\Kit\Foundation\Audit\AuditTrail — nunca `AuditEvent::create()` solto
 * pelo código de tela (a redação dos dados sensíveis mora lá).
 *
 * Imutabilidade, em três camadas:
 * 1. instância: `updating`/`deleting` lançam AppendOnlyViolationException;
 * 2. query em massa pelo Eloquent: AuditEventBuilder recusa
 *    update/delete/upsert/increment/truncate;
 * 3. banco (PostgreSQL): gatilho AuditEventTrigger recusa UPDATE, TRUNCATE e
 *    DELETE fora da poda.
 * A única remoção é a poda por idade (pruneOlderThan, usada pelo comando
 * `audit:prune`, agendado diariamente).
 *
 * Sem `created_at`/`updated_at`: a data é `occurred_at` (UTC).
 *
 * @property string $uuid
 * @property CarbonInterface $occurred_at
 * @property AuditContext $context
 * @property string|null $actor_uuid
 * @property bool|null $actor_is_admin
 * @property string $action
 * @property AuditOutcome $outcome
 * @property string|null $subject_type
 * @property string|null $subject_uuid
 * @property string|null $tenant_uuid
 * @property array<string, array{before: mixed, after: mixed}>|null $changes
 * @property string|null $reason
 * @property string|null $correlation_id
 * @property string|null $ip
 * @property string|null $user_agent
 */
class AuditEvent extends Model
{
    use HasUuids, RoutesByUuid;

    public const CREATED_AT = 'occurred_at';

    public const UPDATED_AT = null;

    protected $table = 'audit_events';

    /**
     * Graváveis só na criação (INSERT) — depois disso, nada muda.
     *
     * @var list<string>
     */
    protected $fillable = [
        'context',
        'actor_uuid',
        'actor_is_admin',
        'action',
        'outcome',
        'subject_type',
        'subject_uuid',
        'tenant_uuid',
        'changes',
        'reason',
        'correlation_id',
        'ip',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'context' => AuditContext::class,
            'outcome' => AuditOutcome::class,
            'actor_is_admin' => 'boolean',
            'changes' => 'array',
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
     * @return AuditEventBuilder<static>
     */
    public function newEloquentBuilder($query): AuditEventBuilder
    {
        return new AuditEventBuilder($query);
    }

    /**
     * Quem agiu (pelo uuid — a conta pode já ter sido excluída; a linha fica).
     *
     * O model do usuário é o configurado na autenticação
     * (`auth.providers.users.model`): a trilha não importa o módulo de
     * autenticação.
     *
     * @return BelongsTo<Model, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo((string) config('auth.providers.users.model'), 'actor_uuid', 'uuid');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw AppendOnlyViolationException::auditUpdateAttempted();
        });

        static::deleting(function (): void {
            throw AppendOnlyViolationException::auditDeleteAttempted();
        });
    }

    /**
     * A PODA POR IDADE — a única remoção da trilha. Apaga em lotes (cada
     * lote na sua transação, com a flag do gatilho ligada só ali) tudo o que
     * aconteceu antes de `$cutoff`. Devolve quantas linhas saíram.
     */
    public static function pruneOlderThan(CarbonInterface $cutoff, int $chunk = 1000): int
    {
        $total = 0;

        do {
            $deleted = DB::transaction(function () use ($cutoff, $chunk): int {
                $ids = static::query()
                    ->where('occurred_at', '<', $cutoff)
                    ->orderBy('id')
                    ->limit($chunk)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    return 0;
                }

                AuditEventTrigger::allowPruneInCurrentTransaction();

                try {
                    return (int) AuditEventBuilder::whilePruning(
                        fn (): mixed => static::query()->whereKey($ids)->delete(),
                    );
                } finally {
                    // A flag é local à transação; desligar aqui mesmo evita
                    // que ela sobreviva numa transação externa (testes).
                    AuditEventTrigger::allowPruneInCurrentTransaction(false);
                }
            });

            $total += $deleted;
        } while ($deleted === $chunk);

        return $total;
    }
}
