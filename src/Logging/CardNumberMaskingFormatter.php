<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formatter decorador: mascara número de cartão (PAN) na linha JÁ formatada
 * de qualquer canal de log em arquivo/stream (PCI DSS — req. 3).
 *
 * Por que na saída, e não num processor do registro: o PAN chega ao log de
 * arquivo principalmente por caminhos que ninguém controla campo a campo —
 * a mensagem de uma exceção (uma QueryException carrega os valores do
 * INSERT), o `error` do PersistFailure, um `Log::info()` com contexto. A
 * exceção é um objeto; o formatter é quem a transforma em texto. Mascarar o
 * texto final cobre mensagem, contexto, extra e exceção de uma vez, qualquer
 * que seja o formatter do canal (linha ou JSON).
 *
 * Na linha final rodam o cartão (Redactor::maskCardNumbers) e os segredos
 * de provedor em texto livre (Redactor::maskSecrets, #114). Antes de
 * formatar, o contexto e o `extra` passam pela regra por NOME de campo
 * (Redactor::redactSensitiveKeys, #122): `password`, `pix_key` ou um nome
 * que o aplicativo acrescentou em `security.redaction.keys` não saem em
 * claro. CPF/CNPJ/e-mail em texto continuam sob responsabilidade de quem
 * monta o contexto, como sempre foram.
 */
final class CardNumberMaskingFormatter implements FormatterInterface
{
    public function __construct(
        private readonly FormatterInterface $inner,
        private readonly Redactor $redactor,
    ) {}

    public function format(LogRecord $record): mixed
    {
        return $this->mask($this->inner->format($this->redactRecord($record)));
    }

    /**
     * @param  array<LogRecord>  $records
     */
    public function formatBatch(array $records): mixed
    {
        return $this->mask($this->inner->formatBatch(array_map($this->redactRecord(...), $records)));
    }

    /**
     * O formatter original, para quem precisar inspecionar a configuração.
     */
    public function inner(): FormatterInterface
    {
        return $this->inner;
    }

    private function redactRecord(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redactor->redactSensitiveKeys($record->context),
            extra: $this->redactor->redactSensitiveKeys($record->extra),
        );
    }

    private function mask(mixed $formatted): mixed
    {
        return is_string($formatted)
            ? $this->redactor->maskCardNumbers($this->redactor->maskSecrets($formatted))
            : $formatted;
    }
}
