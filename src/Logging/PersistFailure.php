<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Classificação da falha de gravação da trilha de auditoria.
 *
 * A gravação do request log é deliberadamente resiliente: se o banco falhar,
 * a requisição NÃO é derrubada — a exceção vira uma linha `critical` no canal
 * de arquivo. O problema de só isso: "banco fora do ar" e "alguém conseguiu
 * fazer duas linhas colidirem numa constraint" saíam com a mesma cara, e a
 * segunda é sinal de tentativa de apagar rastro, não de indisponibilidade.
 *
 * Aqui a causa é classificada para que o alerta distinga os casos. Nada é
 * reprojetado: o catch continua engolindo a exceção; só passa a dizer o que
 * aconteceu, com um motivo pesquisável no log estruturado.
 */
final class PersistFailure
{
    /**
     * SQLSTATE de violação de unicidade (PostgreSQL 23505 e equivalentes).
     */
    private const UNIQUE_VIOLATION = '23505';

    /**
     * Contexto adicional da falha, para juntar ao log estruturado.
     *
     * @return array{error: string, failure_reason: string}
     */
    public static function describe(Throwable $exception): array
    {
        return [
            'error' => $exception->getMessage(),
            'failure_reason' => self::isUniqueViolation($exception) ? 'unique_violation' : 'database_error',
        ];
    }

    /**
     * A falha foi colisão de chave única? Depois da separação entre o id
     * interno (gerado pelo servidor) e a correlação do cliente, isto não
     * deveria mais acontecer por dado de entrada — se acontecer, é incidente
     * de verdade e merece ser reconhecível no log.
     */
    public static function isUniqueViolation(Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        return ($exception->errorInfo[0] ?? null) === self::UNIQUE_VIOLATION;
    }
}
