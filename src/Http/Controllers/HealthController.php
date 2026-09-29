<?php

declare(strict_types=1);

namespace Twstec\Kit\Foundation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Twstec\Kit\Foundation\Http\Resources\HealthCheckResource;
use Twstec\Kit\Foundation\Logging\CorrelationId;

/**
 * GET /api/health — verificação de saúde da aplicação.
 *
 * Decisão documentada (docs/logs-lgpd.md): a rota é EXCLUÍDA do request log em banco
 * (health checks são barulhentos) mas continua passando pela validação de
 * segurança, headers de segurança e rate limit global da API, além do
 * access log do nginx.
 */
final class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = CorrelationId::resolve($request);

        return (new HealthCheckResource([
            'status' => 'ok',
            'version' => platform()->version,
            'correlation_id' => $correlationId,
        ]))->response()->header(CorrelationId::HEADER, $correlationId);
    }
}
