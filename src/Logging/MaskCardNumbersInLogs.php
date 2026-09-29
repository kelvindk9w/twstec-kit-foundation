<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;

/**
 * "Tap" dos canais de log (config/logging.php): envolve o formatter de cada
 * handler no CardNumberMaskingFormatter, para que nenhum número de cartão
 * saia em claro em storage/logs (e, daí, no backup).
 *
 * Idempotente: um handler já envolvido não é envolvido de novo (o canal
 * `stack` reaproveita os handlers dos canais que agrupa).
 */
final class MaskCardNumbersInLogs
{
    public function __construct(private readonly Redactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        /** @var \Monolog\Logger $monolog */
        $monolog = $logger->getLogger();

        foreach ($monolog->getHandlers() as $handler) {
            if (! $handler instanceof FormattableHandlerInterface) {
                continue;
            }

            $formatter = $handler->getFormatter();

            if ($formatter instanceof CardNumberMaskingFormatter) {
                continue;
            }

            $handler->setFormatter(new CardNumberMaskingFormatter($formatter, $this->redactor));
        }
    }
}
