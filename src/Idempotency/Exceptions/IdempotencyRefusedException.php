<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesApiErrorCode;
use Twstec\Kit\Foundation\Idempotency\IdempotencyKey;

/**
 * Recusa da idempotência, SEM executar a requisição. Sai no envelope de erro
 * da API com um código estável (o cliente programa em cima dele):
 *
 * | status | code                              | quando                                       |
 * |--------|-----------------------------------|----------------------------------------------|
 * | 400    | `idempotency_key_missing`         | rota exige a chave e ela não veio            |
 * | 400    | `idempotency_key_invalid`         | chave fora do formato (tamanho, caracteres)   |
 * | 422    | `idempotency_key_reused`          | mesma chave, requisição diferente             |
 * | 409    | `idempotency_request_in_progress` | mesma chave ainda em execução                 |
 *
 * Os status seguem o rascunho da IETF "The Idempotency-Key HTTP Header
 * Field" (draft-ietf-httpapi-idempotency-key-header).
 */
final class IdempotencyRefusedException extends HttpException implements ProvidesApiErrorCode
{
    public const MISSING = 'idempotency_key_missing';

    public const INVALID = 'idempotency_key_invalid';

    public const REUSED = 'idempotency_key_reused';

    public const IN_PROGRESS = 'idempotency_request_in_progress';

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, int|string>  $replace
     */
    private function __construct(int $status, private readonly string $errorCode, array $headers = [], array $replace = [])
    {
        parent::__construct($status, (string) __("api.errors.{$errorCode}", $replace), null, $headers);
    }

    public static function missing(): self
    {
        return new self(400, self::MISSING);
    }

    public static function invalid(): self
    {
        return new self(400, self::INVALID, [], [
            'min' => IdempotencyKey::minLength(),
            'max' => IdempotencyKey::maxLength(),
        ]);
    }

    public static function reused(): self
    {
        return new self(422, self::REUSED);
    }

    public static function inProgress(): self
    {
        return new self(409, self::IN_PROGRESS, ['Retry-After' => '1']);
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }
}
