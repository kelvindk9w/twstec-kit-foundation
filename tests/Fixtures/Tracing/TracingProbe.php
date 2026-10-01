<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tests\Fixtures\Tracing;

use Twstec\Kit\Foundation\Logging\CorrelationId;

/**
 * O que os jobs de teste viram ao rodar: o correlation_id e a origem que
 * valiam dentro do handle().
 */
final class TracingProbe
{
    /**
     * @var list<array{label: string, id: ?string, origin: ?string}>
     */
    public static array $seen = [];

    public static function see(string $label): void
    {
        self::$seen[] = [
            'label' => $label,
            'id' => CorrelationId::current(),
            'origin' => CorrelationId::origin()?->value,
        ];
    }

    /**
     * @return array{label: string, id: ?string, origin: ?string}|null
     */
    public static function of(string $label): ?array
    {
        foreach (self::$seen as $entry) {
            if ($entry['label'] === $label) {
                return $entry;
            }
        }

        return null;
    }

    public static function reset(): void
    {
        self::$seen = [];
    }
}
