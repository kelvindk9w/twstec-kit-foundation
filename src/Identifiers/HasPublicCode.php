<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Identifiers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Gera o `codigo_publico` legível da entidade (ex.: `CLI-9F4K2Q`) — sequencial nunca, para não permitir enumeração.
 *
 * Regras:
 * - Formato: PREFIXO-XXXXXX (prefixo definido por entidade + sufixo aleatório
 *   criptograficamente seguro em alfabeto SEM ambiguidade: sem 0/O, 1/I/L).
 * - Sequencial puro é PROIBIDO (enumeração).
 * - A unicidade é garantida pelo BANCO (constraint UNIQUE na coluna
 *   `codigo_publico`), nunca pela probabilidade: em colisão, tenta de novo
 *   (ver self::createWithPublicCodeRetry()). O retry funciona também dentro
 *   de uma transação já aberta (savepoint por tentativa — ver o método).
 *
 * Uso no model:
 *   class Cliente extends Model {
 *       use HasUuids, HasPublicCode;
 *       protected const PUBLIC_CODE_PREFIX = 'CLI';
 *   }
 *
 *   // Criação com retry de colisão (recomendado):
 *   Cliente::createWithPublicCodeRetry([...]);
 */
trait HasPublicCode
{
    /**
     * Tamanho do sufixo aleatório (alfabeto de 32 → 32^6 ≈ 1 bilhão de códigos).
     */
    protected const PUBLIC_CODE_SUFFIX_LENGTH = 6;

    /**
     * Alfabeto sem caracteres ambíguos (sem 0, O, 1, I, L).
     */
    protected const PUBLIC_CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /**
     * Máximo de tentativas em caso de colisão na constraint UNIQUE.
     */
    protected const PUBLIC_CODE_MAX_ATTEMPTS = 5;

    public static function bootHasPublicCode(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->codigo_publico)) {
                $model->codigo_publico = static::generatePublicCode();
            }
        });
    }

    /**
     * Cria o registro com retry em colisão de código público.
     * A constraint UNIQUE do banco é o mecanismo anti-race — nunca
     * "checa-then-insert" sem constraint.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createWithPublicCodeRetry(array $attributes): static
    {
        $attempts = 0;

        while (true) {
            try {
                // Cada tentativa num SAVEPOINT quando já há transação aberta.
                // No PostgreSQL, a violação de UNIQUE aborta a transação
                // inteira: sem o savepoint, a tentativa seguinte falharia com
                // "current transaction is aborted" em vez de gravar. O
                // savepoint desfaz só o insert que colidiu. Fora de transação
                // nada muda (o insert falho não contamina nada).
                $query = static::query();

                /** @var static */
                return $query->withSavepointIfNeeded(fn () => $query->create($attributes));
            } catch (UniqueConstraintViolationException $exception) {
                $attempts++;

                unset($attributes['codigo_publico']);

                if ($attempts >= static::PUBLIC_CODE_MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Gera um código público novo: PREFIXO-XXXXXX.
     */
    public static function generatePublicCode(): string
    {
        $suffix = '';

        for ($i = 0; $i < static::PUBLIC_CODE_SUFFIX_LENGTH; $i++) {
            $suffix .= static::PUBLIC_CODE_ALPHABET[random_int(0, strlen(static::PUBLIC_CODE_ALPHABET) - 1)];
        }

        return static::publicCodePrefix().'-'.$suffix;
    }

    /**
     * Prefixo legível da entidade (ex.: 'CLI', 'COB'). Cada model define o seu
     * via constante PUBLIC_CODE_PREFIX.
     */
    public static function publicCodePrefix(): string
    {
        if (defined('static::PUBLIC_CODE_PREFIX')) {
            /** @var string */
            return constant('static::PUBLIC_CODE_PREFIX');
        }

        // Fallback: 3 primeiras letras do nome da classe, em maiúsculas.
        return Str::upper(Str::substr(class_basename(static::class), 0, 3));
    }
}
