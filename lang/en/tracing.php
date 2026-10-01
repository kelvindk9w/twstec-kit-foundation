<?php

declare(strict_types=1);

// Correlation tracing messages (en) — the `outbound-http:prune` command.

return [

    'prune_disabled' => 'Outbound HTTP trail pruning is disabled (TRACING_HTTP_RETENTION_DAYS=0).',
    'pruned' => ':count outbound HTTP call(s) older than :days days removed from the trail.',

];
