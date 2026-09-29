<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Audit\Models;

use Illuminate\Database\Eloquent\Builder;
use Twstec\Kit\Foundation\Logging\Exceptions\AppendOnlyViolationException;

/**
 * Builder append-only de `audit_events`.
 *
 * Os eventos de model (`updating`/`deleting`) só enxergam a escrita feita
 * por uma INSTÂNCIA. `AuditEvent::query()->update([...])` e
 * `->where(...)->delete()` mandam uma sentença única e passam direto por eles
 * — é o mesmo buraco que a proteção das contas demo fecha com gatilho. Aqui
 * ele é fechado também na aplicação: toda escrita em massa pelo Eloquent
 * lança AppendOnlyViolationException.
 *
 * A única porta é a poda por idade (AuditEvent::pruneOlderThan()), que liga
 * a permissão só durante a própria sentença de DELETE.
 *
 * O que ISTO não cobre: `DB::table('audit_events')`, `toBase()` e SQL cru não
 * passam pelo Eloquent. No PostgreSQL o gatilho AuditEventTrigger recusa a
 * sentença no próprio banco (ver docs/logs-lgpd.md).
 *
 * @template TModel of AuditEvent
 *
 * @extends Builder<TModel>
 */
final class AuditEventBuilder extends Builder
{
    /**
     * Métodos encaminhados ao query builder por __call que também escrevem.
     */
    private const GUARDED_FORWARDS = ['truncate', 'updateorinsert', 'updatefrom'];

    /**
     * Ligada SÓ dentro de AuditEvent::pruneOlderThan().
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
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function touch($column = null)
    {
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw AppendOnlyViolationException::auditUpdateAttempted();
    }

    public function delete()
    {
        if (! self::$pruning) {
            throw AppendOnlyViolationException::auditDeleteAttempted();
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        throw AppendOnlyViolationException::auditDeleteAttempted();
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $parameters
     */
    public function __call($method, $parameters)
    {
        if (in_array(strtolower($method), self::GUARDED_FORWARDS, true)) {
            throw $method === 'truncate'
                ? AppendOnlyViolationException::auditDeleteAttempted()
                : AppendOnlyViolationException::auditUpdateAttempted();
        }

        return parent::__call($method, $parameters);
    }
}
