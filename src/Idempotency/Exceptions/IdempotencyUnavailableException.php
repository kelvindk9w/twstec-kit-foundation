<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency\Exceptions;

use LogicException;

/**
 * A requisição trouxe `Idempotency-Key`, mas a resposta não poderia ser
 * guardada: não há chave de cifra utilizável (APP_KEY ausente ou inválida).
 * FALHA FECHADA, ANTES de executar: a rota não roda, e a requisição sai como
 * erro de servidor (500 genérico; o detalhe vai para o log como
 * `api.idempotency.encryption_unavailable`, nível crítico). Executar e só
 * depois descobrir que o resultado não pode ser guardado deixaria a chave
 * presa com um efeito já gravado. Em produção o boot já recusa subir sem
 * APP_KEY utilizável (Support\ProductionHardening).
 */
final class IdempotencyUnavailableException extends LogicException
{
    public static function encryptionUnavailable(string $reason): self
    {
        return new self('Idempotency-Key recebida, mas não há chave de cifra utilizável para guardar a resposta (APP_KEY): '.$reason);
    }
}
