<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Console;

use Illuminate\Console\Command;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

/**
 * Poda por idade da trilha de auditoria — a ÚNICA remoção que `audit_events`
 * aceita (model, builder e gatilho do PostgreSQL recusam qualquer outra).
 *
 * Janela: `audit.retention_days` (AUDIT_RETENTION_DAYS, padrão 365). 0 =
 * não poda. Agendado diariamente em routes/console.php, como a poda da
 * `failed_jobs`.
 *
 * A poda deixa rastro: quando apaga alguma coisa, grava a linha
 * `audit_event.pruned` (contexto console) com quantas linhas saíram e a data
 * de corte — um buraco na trilha sem explicação seria indistinguível de
 * adulteração.
 */
final class PruneAuditEvents extends Command
{
    protected $signature = 'audit:prune {--days= : Janela de retenção em dias (padrão: audit.retention_days)}';

    protected $description = 'Remove da trilha de auditoria os eventos mais antigos que a janela de retenção';

    public function handle(AuditTrail $trail): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('audit.retention_days', 365);

        if ($days <= 0) {
            $this->info(__('audit.prune_disabled'));

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $deleted = AuditEvent::pruneOlderThan($cutoff);

        if ($deleted > 0) {
            $trail->within(AuditScope::console('audit:prune'), fn () => $trail->record(
                action: 'audit_event.pruned',
                changes: [
                    'deleted' => ['before' => null, 'after' => $deleted],
                    'cutoff' => ['before' => null, 'after' => $cutoff->utc()->toIso8601String()],
                ],
                subjectType: AuditTrail::subjectType(AuditEvent::class),
            ));
        }

        $this->info(__('audit.pruned', ['count' => $deleted, 'days' => $days]));

        return self::SUCCESS;
    }
}
