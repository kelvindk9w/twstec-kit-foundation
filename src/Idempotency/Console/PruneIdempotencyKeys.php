<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Twstec\Kit\Foundation\Idempotency\IdempotencyStore;

/**
 * Remove as chaves de idempotência vencidas (`expires_at` no passado) — e,
 * com elas, as respostas cifradas guardadas para o replay. Agendado pelo
 * próprio pacote (`idempotency.prune_schedule`, padrão de hora em hora).
 *
 * A chave vencida já não vale nem antes da poda (a mesma chave executa de
 * novo); a poda só tira do banco o que não serve mais.
 */
final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Remove as chaves de idempotência vencidas e as respostas guardadas com elas';

    public function handle(IdempotencyStore $store): int
    {
        $deleted = $store->prune(CarbonImmutable::now());

        $this->info(__('api.idempotency.pruned', ['count' => $deleted]));

        return self::SUCCESS;
    }
}
