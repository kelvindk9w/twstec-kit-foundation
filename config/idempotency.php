<?php

declare(strict_types=1);

// =============================================================================
// Idempotência HTTP: o cabeçalho `Idempotency-Key` nas escritas da API. Ver
// Twstec\Kit\Foundation\Idempotency e docs/api.md ("Idempotência").
//
// O middleware (`idempotent`) só vale nas rotas em que é declarado. Nada
// aqui liga a idempotência sozinho numa rota.
// =============================================================================

return [

    // Métodos em que o cabeçalho é lido. Nos demais, o middleware deixa a
    // requisição passar sem olhar a chave (GET, PUT e DELETE já são
    // idempotentes por definição).
    'methods' => array_values(array_filter(array_map(
        static fn (string $method): string => strtoupper(trim($method)),
        explode(',', (string) env('IDEMPOTENCY_METHODS', 'POST,PATCH')),
    ))),

    // Por quanto tempo a chave vale, em HORAS, contadas da primeira
    // execução. Depois disso a mesma chave executa de novo.
    'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),

    // Execução que ficou "em processamento" sem resultado registrado (o
    // processo morreu, ou o banco falhou ao gravar a conclusão): a rota PODE
    // ter gravado o efeito — não há como saber. Depois de quantos SEGUNDOS
    // uma nova tentativa com o mesmo corpo pode retomar a chave e executar de
    // novo? 0 (padrão) = NUNCA: a chave responde 409 até vencer, e o cliente
    // confere o estado do recurso antes de tentar com uma chave nova. Efeito
    // duplicado é pior que uma chave presa. Vale para as rotas comuns; as do
    // modo `transactional` usam `lock_seconds` (abaixo).
    'abandoned_takeover_seconds' => (int) env('IDEMPOTENCY_ABANDONED_TAKEOVER_SECONDS', 0),

    // Modo `transactional` (idempotent:transactional): a rota e a conclusão da
    // chave são gravadas na MESMA transação — "em processamento" quer dizer
    // "nada foi confirmado". Depois deste prazo, em SEGUNDOS, a execução
    // abandonada é retomada com segurança. Precisa ser MAIOR que a requisição
    // mais lenta da rota.
    'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 120),

    // Espera curta, em MILISSEGUNDOS, quando a mesma chave já está em
    // execução: a segunda requisição consulta a cada 100 ms e, se a primeira
    // terminar a tempo, recebe a resposta dela. 0 = responde 409 na hora.
    'wait_ms' => (int) env('IDEMPOTENCY_WAIT_MS', 0),

    // Formato da chave: caracteres `A-Z a-z 0-9 - _ . : ~ + / =` e tamanho
    // entre o mínimo e o máximo (teto absoluto: 255). Recomendado: UUID v4.
    'key_min_length' => (int) env('IDEMPOTENCY_KEY_MIN_LENGTH', 16),

    'key_max_length' => (int) env('IDEMPOTENCY_KEY_MAX_LENGTH', 255),

    // Erro de validação (422) "congela" a chave? Padrão: não — o cliente
    // corrige o corpo e tenta de novo com a mesma chave.
    'freeze_validation_errors' => (bool) env('IDEMPOTENCY_FREEZE_VALIDATION_ERRORS', false),

    // Outros erros de cliente (4xx) que congelam a chave, separados por
    // vírgula (ex.: 404,410). Padrão: nenhum. 401, 403, 408, 409, 425, 429 e
    // todo 5xx NUNCA congelam.
    'freeze_client_errors' => array_values(array_filter(array_map(
        static fn (string $status): int => (int) trim($status),
        explode(',', (string) env('IDEMPOTENCY_FREEZE_CLIENT_ERRORS', '')),
    ))),

    // Tamanho máximo, em BYTES, do corpo da resposta guardado para o replay.
    // Acima dele, a resposta é guardada sem corpo (o replay diz "já
    // processada" sem repetir o corpo).
    'max_response_bytes' => (int) env('IDEMPOTENCY_MAX_RESPONSE_BYTES', 262144),

    // Conexão de banco da tabela `idempotency_keys`. Vazio = a padrão.
    'connection' => env('IDEMPOTENCY_CONNECTION'),

    // Poda das chaves vencidas (`idempotency:prune`), agendada pelo pacote.
    // Vazio desliga, com aviso no log a cada boot.
    'prune_schedule' => (string) env('IDEMPOTENCY_PRUNE_SCHEDULE', '35 * * * *'),

];
