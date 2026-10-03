<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sinal INTERNO do modo `transactional`: a rota respondeu com um status que
 * não congela a chave (erro de servidor, validação…). Lançado dentro da
 * transação para desfazê-la — o que a rota gravou não fica — e apanhado logo
 * fora dela pelo middleware, que libera a chave e devolve a resposta. Nunca
 * sai do middleware.
 */
final class ReleaseWithinTransaction extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('Idempotência: status que não congela a chave; transação desfeita.');
    }
}
