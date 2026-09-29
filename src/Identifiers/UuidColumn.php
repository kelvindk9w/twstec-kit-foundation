<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Identifiers;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * Consulta segura em coluna `uuid` com valor que veio de fora (URL, ação do
 * Livewire, filtro digitado no /admin, estado de formulário).
 *
 * No PostgreSQL — o banco de produção — a coluna é do tipo `uuid` NATIVO:
 * comparar com um texto que não é uuid não "não encontra", ele DERRUBA a
 * consulta com erro de sintaxe (SQLSTATE 22P02), e a requisição vira 500. O
 * SQLite aceita qualquer texto calado, e por isso a suíte em SQLite nunca viu
 * o problema.
 *
 * A regra daqui: valor que não é uuid não chega ao banco. Ele simplesmente não
 * corresponde a registro nenhum — o mesmo resultado que um uuid inexistente,
 * o que mantém o 404 uniforme das buscas por dono (anti-IDOR).
 */
final class UuidColumn
{
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    /**
     * Só os valores que são uuid, na ordem em que vieram.
     *
     * @param  iterable<mixed>  $values
     * @return list<string>
     */
    public static function onlyValid(iterable $values): array
    {
        $valid = [];

        foreach ($values as $value) {
            if (self::isValid($value)) {
                $valid[] = $value;
            }
        }

        return $valid;
    }

    /**
     * `where coluna = valor` quando o valor é uuid; caso contrário, uma
     * condição sempre falsa (a consulta roda e devolve vazio).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function where(Builder $query, string $column, mixed $value): Builder
    {
        return self::isValid($value)
            ? $query->where($column, $value)
            : $query->whereRaw('1 = 0');
    }
}
