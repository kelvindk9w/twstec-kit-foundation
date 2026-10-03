<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

/**
 * O resultado de IdempotencyStore::acquire(): ou ESTA requisição ficou com a
 * chave (`owner` preenchido — executa), ou a chave já é de outra execução
 * (`existing` preenchido — replay, recusa ou "em processamento").
 */
final readonly class Acquisition
{
    /**
     * A chave tinha vencido: vale como nova.
     */
    public const EXPIRED = 'expired';

    /**
     * A execução anterior passou do prazo sem terminar (processo morto).
     */
    public const ABANDONED = 'abandoned';

    private function __construct(
        public ?string $owner,
        public ?IdempotencyRecord $existing,
        public ?string $takeover,
    ) {}

    public static function owned(string $owner, ?string $takeover = null): self
    {
        return new self($owner, null, $takeover);
    }

    public static function existing(IdempotencyRecord $record): self
    {
        return new self(null, $record, null);
    }
}
