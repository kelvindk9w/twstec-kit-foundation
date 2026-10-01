<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Tests\Fixtures\Tracing;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Job de teste: anota o correlation_id que vale dentro dele, escreve uma
 * linha de log e, se pedido, despacha outro job ou chama um serviço externo.
 */
final class ProbeJob implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(
        public string $label,
        public ?string $dispatchChild = null,
        public ?string $callUrl = null,
    ) {}

    public function handle(): void
    {
        TracingProbe::see($this->label);

        Log::info('probe.job', ['label' => $this->label]);

        if ($this->dispatchChild !== null) {
            dispatch(new self($this->dispatchChild));
        }

        if ($this->callUrl !== null) {
            Http::post($this->callUrl, ['from' => $this->label]);
        }
    }
}
