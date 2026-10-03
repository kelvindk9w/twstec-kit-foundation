<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Exceptions\Contracts;

/**
 * Exceção que traz o próprio `code` estável do envelope de erro da API, mais
 * específico que o código por status (ex.: `idempotency_key_reused` em vez
 * de `validation_failed` num 422). O ApiErrorRenderer usa este código, e a
 * mensagem sai de `api.errors.<code>`.
 */
interface ProvidesApiErrorCode
{
    public function apiErrorCode(): string;
}
