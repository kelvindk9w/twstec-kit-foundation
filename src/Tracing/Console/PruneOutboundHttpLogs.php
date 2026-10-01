<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Console;

use Illuminate\Console\Command;
use Twstec\Kit\Foundation\Audit\AuditScope;
use Twstec\Kit\Foundation\Audit\AuditTrail;
use Twstec\Kit\Foundation\Tracing\Models\OutboundHttpLog;

/**
 * Poda por idade da trilha das chamadas HTTP de saída — a ÚNICA remoção que
 * `outbound_http_logs` aceita (model, builder e gatilho do PostgreSQL recusam
 * qualquer outra).
 *
 * Janela: `tracing.http.trail.retention_days` (TRACING_HTTP_RETENTION_DAYS,
 * padrão 90). 0 = não poda. Agendada pelo próprio pacote
 * (`tracing.http.trail.prune_schedule`).
 *
 * A poda deixa rastro na trilha de auditoria (`outbound_http_log.pruned`,
 * contexto console), como a do `audit:prune`: um buraco sem explicação seria
 * indistinguível de adulteração.
 */
final class PruneOutboundHttpLogs extends Command
{
    protected $signature = 'outbound-http:prune {--days= : Janela de retenção em dias (padrão: tracing.http.trail.retention_days)}';

    protected $description = 'Remove da trilha de chamadas HTTP de saída as linhas mais antigas que a janela de retenção';

    public function handle(AuditTrail $trail): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('tracing.http.trail.retention_days', 90);

        if ($days <= 0) {
            $this->info(__('tracing.prune_disabled'));

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $deleted = OutboundHttpLog::pruneOlderThan($cutoff);

        if ($deleted > 0) {
            $trail->within(AuditScope::console('outbound-http:prune'), fn () => $trail->record(
                action: 'outbound_http_log.pruned',
                changes: [
                    'deleted' => ['before' => null, 'after' => $deleted],
                    'cutoff' => ['before' => null, 'after' => $cutoff->utc()->toIso8601String()],
                ],
                subjectType: AuditTrail::subjectType(OutboundHttpLog::class),
            ));
        }

        $this->info(__('tracing.pruned', ['count' => $deleted, 'days' => $days]));

        return self::SUCCESS;
    }
}
