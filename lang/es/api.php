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
        'idempotency_key_missing' => 'Esta operación requiere el encabezado Idempotency-Key.',
        'idempotency_key_invalid' => 'Idempotency-Key inválida: use de :min a :max caracteres entre letras, dígitos y - _ . : ~ + / =.',
        'idempotency_key_reused' => 'Esta Idempotency-Key ya se usó con una solicitud diferente. Use una clave nueva para una operación nueva.',
        'idempotency_request_in_progress' => 'Una solicitud con esta Idempotency-Key se está procesando o terminó sin un resultado registrado. Inténtelo de nuevo en unos instantes; si persiste, consulte el estado del recurso antes de repetir la operación con una clave nueva.',
        'server_error' => 'Error interno. Informe el correlation_id al soporte.',
    ],

    'idempotency' => [
        'withheld' => 'Esta solicitud ya fue procesada. El cuerpo de la respuesta original no se guarda en esta operación y no se muestra de nuevo.',
        'pruned' => ':count clave(s) de idempotencia vencida(s) eliminada(s).',
    ],

];
