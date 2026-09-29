<?php

// =============================================================================
// Envelope de ERROR de la API (`api/*`) — ver
// Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer y docs/api.md.
//
// El `message` es humano y traducido; el `code` es estable y NO se traduce
// (es el que programa el cliente de la API).
// =============================================================================

return [

    'errors' => [
        'bad_request' => 'Petición mal formada.',
        'unauthorized' => 'Credenciales ausentes o inválidas.',
        'forbidden' => 'Esta credencial no tiene permiso para esta operación.',
        'not_found' => 'Recurso no encontrado.',
        'method_not_allowed' => 'Método HTTP no permitido para este endpoint.',
        'conflict' => 'La operación entra en conflicto con el estado actual del recurso.',
        'page_expired' => 'Sesión expirada. Reintente la petición.',
        'validation_failed' => 'Los datos enviados son inválidos.',
        'too_many_requests' => 'Demasiadas peticiones. Inténtelo de nuevo en unos instantes.',
        'service_unavailable' => 'Servicio temporalmente no disponible.',
        'server_error' => 'Error interno. Informe el correlation_id al soporte.',
    ],

];
