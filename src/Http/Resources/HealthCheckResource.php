<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Resources;

use Illuminate\Http\Request;

/**
 * Resource do endpoint de saúde (GET /api/health).
 *
 * Segue a convenção da API: resposta via Resource, expondo apenas o
 * necessário — status, versão da plataforma (config centralizada) e o
 * correlation_id da requisição para rastreabilidade.
 */
final class HealthCheckResource extends BaseResource
{
    /**
     * @return array{status: string, version: string, correlation_id: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'version' => $this->resource['version'],
            'correlation_id' => $this->resource['correlation_id'],
        ];
    }
}
