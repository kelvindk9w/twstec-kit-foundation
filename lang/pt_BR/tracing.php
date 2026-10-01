<?php

declare(strict_types=1);

// Mensagens do rastreio pelo correlation_id (pt-BR) — o comando
// `outbound-http:prune`.

return [

    'prune_disabled' => 'Poda da trilha de chamadas HTTP de saída desligada (TRACING_HTTP_RETENTION_DAYS=0).',
    'pruned' => ':count chamada(s) HTTP de saída com mais de :days dias removida(s) da trilha.',

];
