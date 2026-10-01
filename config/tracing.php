<?php

declare(strict_types=1);

// =============================================================================
// Rastreio de ponta a ponta pelo correlation_id: requisição → job da fila →
// chamada HTTP de saída. Ver Twstec\Kit\Foundation\Tracing e docs/logs-lgpd.md.
//
// Tudo LIGADO por padrão, pelo próprio pacote. Desligar é explícito e deixa
// aviso no log a cada boot em produção.
// =============================================================================

return [

    // --- Fila -----------------------------------------------------------------
    // Todo job despachado leva o correlation_id de quem o despachou (no
    // payload, chave `twsCorrelation`: só id e origem) e o restaura no worker
    // — contexto do log e trilhas. Job sem id recebe um novo (origem `queue`);
    // cada tarefa do agendador ganha o seu (origem `scheduler`).
    'queue' => [
        'enabled' => (bool) env('TRACING_QUEUE', true),
    ],

    // --- Chamadas HTTP de saída (cliente `Http` do Laravel) -------------------
    'http' => [

        // Cabeçalho com o correlation_id em toda chamada de saída.
        'header' => [
            'enabled' => (bool) env('TRACING_HTTP_HEADER', true),

            'name' => (string) env('TRACING_HTTP_HEADER_NAME', 'X-Correlation-Id'),

            // Destinos que NÃO recebem o cabeçalho (host exato ou curinga:
            // `api.exemplo.com`, `*.exemplo.com`). Por chamada:
            // `Http::withoutCorrelationHeader()`.
            'except_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRACING_HTTP_HEADER_EXCEPT_HOSTS', ''))))),
        ],

        // Trilha das chamadas de saída (`outbound_http_logs`, append-only):
        // uma linha por tentativa, com destino normalizado, método, status,
        // duração, tamanhos, correlation_id e conta — nunca cabeçalho, nunca
        // corpo (salvo `withBodyInTrail()`, e redigido). Falha ao gravar NÃO
        // derruba a chamada (fail-open só da trilha): vira linha `critical`
        // no canal `request_log`.
        'trail' => [
            'enabled' => (bool) env('TRACING_HTTP_TRAIL', true),

            // Destinos fora da trilha (mesma sintaxe de except_hosts).
            'except_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRACING_HTTP_TRAIL_EXCEPT_HOSTS', ''))))),

            // Conexão de banco da trilha. Vazio = a padrão. Uma conexão
            // própria (mesmo banco, outra conexão) faz a linha sobreviver ao
            // ROLLBACK da transação em que a chamada aconteceu.
            'connection' => env('TRACING_HTTP_TRAIL_CONNECTION'),

            // Retenção em DIAS (0 = nunca podar). A poda roda sozinha no
            // agendador (cron abaixo; vazio desliga, com aviso no log).
            'retention_days' => (int) env('TRACING_HTTP_RETENTION_DAYS', 90),

            'prune_schedule' => (string) env('TRACING_HTTP_PRUNE_SCHEDULE', '20 3 * * *'),
        ],
    ],

];
