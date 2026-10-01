<?php

declare(strict_types=1);

// Mensajes del rastreo por correlation_id (es) — el comando
// `outbound-http:prune`.

return [

    'prune_disabled' => 'La depuración del registro de llamadas HTTP salientes está desactivada (TRACING_HTTP_RETENTION_DAYS=0).',
    'pruned' => ':count llamada(s) HTTP saliente(s) con más de :days días eliminada(s) del registro.',

];
