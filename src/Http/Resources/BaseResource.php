<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource base da API (padronização de respostas: nunca model cru).
 *
 * CONVENÇÃO OBRIGATÓRIA:
 * - NENHUM endpoint retorna modelo Eloquent cru. Toda entidade tem seu
 *   Resource (estendendo esta classe) definindo EXATAMENTE quais campos saem.
 * - Nunca expor `id` interno do banco — identificadores externos são `uuid`
 *   e `codigo_publico` (ver publicIdentifiers()).
 * - Valores monetários saem nos DOIS formatos via Money::toApiResponse()
 *   (inteiro canônico + formatado).
 */
abstract class BaseResource extends JsonResource
{
    /**
     * Identificadores públicos padrão de qualquer entidade:
     * uuid (externo seguro) + codigo_publico (legível). O `id` do banco
     * NUNCA é exposto.
     *
     * @return array{uuid: string|null, codigo_publico: string|null}
     */
    protected function publicIdentifiers(Model $model): array
    {
        return [
            'uuid' => $model->getAttribute('uuid'),
            'codigo_publico' => $model->getAttribute('codigo_publico'),
        ];
    }

    /**
     * Timestamp em ISO 8601 UTC (internamente tudo é UTC;
     * conversão para America/Sao_Paulo acontece apenas na borda de exibição).
     */
    protected function isoTimestamp(mixed $value): ?string
    {
        return $value?->toIso8601String();
    }

    /**
     * Envelope padrão de erro da API (usado pelos handlers de exceção).
     *
     * @param  array<string, mixed>  $errors
     * @return array{message: string, errors: array<string, mixed>}
     */
    public static function errorEnvelope(string $message, array $errors = []): array
    {
        return [
            'message' => $message,
            'errors' => $errors,
        ];
    }
}
