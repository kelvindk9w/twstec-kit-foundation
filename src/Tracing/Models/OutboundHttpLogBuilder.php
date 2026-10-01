<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tracing\Models;

use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;

/**
 * Builder append-only de `outbound_http_logs` — a mesma regra do builder de
 * `audit_events` (Audit\Models\AuditEventBuilder): toda escrita em massa pelo
 * Eloquent (`update`, `delete`, `upsert`, `increment`, `touch`, `truncate`,
 * `updateOrInsert`) lança AppendOnlyViolationException, porque passaria
 * direto pelos eventos de model.
 *
 * A única porta é a poda por idade (OutboundHttpLog::pruneOlderThan()), que
 * liga a permissão só durante a própria sentença de DELETE. `DB::table()` e
 * SQL cru não passam por aqui: no PostgreSQL o gatilho OutboundHttpLogTrigger
 * os recusa no próprio banco.
 *
 * @template TModel of OutboundHttpLog
 *
 * @extends Builder<TModel>
 */
final class OutboundHttpLogBuilder extends Builder
{
    /**
     * Métodos encaminhados ao query builder por __call que também escrevem.
     */
    private const GUARDED_FORWARDS = ['truncate', 'updateorinsert', 'updatefrom'];

    /**
     * Ligada SÓ dentro de OutboundHttpLog::pruneOlderThan().
     */
    private static bool $pruning = false;

    /**
     * Executa `$callback` com a remoção liberada — uso exclusivo da poda.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function whilePruning(callable $callback): mixed
    {
        self::$pruning = true;

        try {
            return $callback();
        } finally {
            self::$pruning = false;
        }
    }

    public function update(array $values)
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function touch($column = null)
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw AppendOnlyViolationException::outboundUpdateAttempted();
    }

    public function delete()
    {
        if (! self::$pruning) {
            throw AppendOnlyViolationException::outboundDeleteAttempted();
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        throw AppendOnlyViolationException::outboundDeleteAttempted();
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        if (in_array(strtolower($method), self::GUARDED_FORWARDS, true)) {
            throw $method === 'truncate'
                ? AppendOnlyViolationException::outboundDeleteAttempted()
                : AppendOnlyViolationException::outboundUpdateAttempted();
        }

        return parent::__call($method, $parameters);
    }
}
