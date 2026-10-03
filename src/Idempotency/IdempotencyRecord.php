<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Idempotency;

use Carbon\CarbonImmutable;

/**
 * Uma linha de `idempotency_keys`, como o middleware a lê.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $requestHash,
        public string $status,
        public string $owner,
        public ?CarbonImmutable $lockedUntil,
        public CarbonImmutable $expiresAt,
        public ?string $correlationId,
        public ?int $responseStatus,
        public ?string $response,
        public bool $withheld,
    ) {}

    public static function fromRow(object $row): self
    {
        $vars = get_object_vars($row);

        return new self(
            requestHash: (string) $vars['request_hash'],
            status: (string) $vars['status'],
            owner: (string) $vars['owner'],
            lockedUntil: $vars['locked_until'] !== null ? CarbonImmutable::parse((string) $vars['locked_until']) : null,
            expiresAt: CarbonImmutable::parse((string) $vars['expires_at']),
            correlationId: $vars['correlation_id'] !== null ? (string) $vars['correlation_id'] : null,
            responseStatus: $vars['response_status'] !== null ? (int) $vars['response_status'] : null,
            response: $vars['response'] !== null ? (string) $vars['response'] : null,
            withheld: (bool) $vars['response_withheld'],
        );
    }

    public function completed(): bool
    {
        return $this->status === IdempotencyStore::COMPLETED;
    }
}
