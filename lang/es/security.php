<?php

declare(strict_types=1);

return [

    // Respuesta al cliente cuando la validación de seguridad bloquea la
    // petición. Mensaje deliberadamente genérico: no revela lo detectado.
    'blocked' => 'Solicitud rechazada por la política de seguridad.',

    // Mensaje interno grabado en el request log (metadato del intento).
    'blocked_log' => 'Payload malicioso detectado (:type).',

    // Nota del intento que el filtro dejó PASAR (modo observar o ruta
    // delegada) — registrada en la fila de la auditoría.
    'observed_log' => 'Patrón de ataque detectado (:type) — registrado, solicitud no rechazada (modo observar).',

    // Rechazo por tamaño: el contenido enviado supera lo que la validación de
    // seguridad inspecciona (config security.validation.max_inspected_bytes).
    'payload_too_large' => 'El contenido enviado es demasiado grande para ser aceptado.',

    'payload_too_large_log' => 'Contenido por encima del límite de inspección de seguridad (:bytes bytes) — rechazado sin inspección.',

    // Página/respuesta 429 — límite de peticiones por cliente (borde y rutas
    // sensibles).
    'throttled' => [
        'title' => 'Demasiadas solicitudes',
        'heading' => 'Tranquilo, vamos más despacio',
        'body' => 'Recibimos demasiadas solicitudes desde tu conexión en poco tiempo. Espera un momento e inténtalo de nuevo.',
        'retry' => 'Puedes intentarlo de nuevo en unos :seconds segundos.',
        'action' => 'Intentar de nuevo',
    ],

];
