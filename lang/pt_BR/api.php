<?php

// =============================================================================
// Envelope de ERRO da API (`api/*`) — ver Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer
// e docs/api.md ("Contrato de resposta da API").
//
// A `message` é humana e traduzida; o `code` é estável e NÃO se traduz
// (é ele que o cliente da API programa).
// =============================================================================

return [

    'errors' => [
        'bad_request' => 'Requisição malformada.',
        'unauthorized' => 'Credenciais ausentes ou inválidas.',
        'forbidden' => 'Esta credencial não tem permissão para esta operação.',
        'not_found' => 'Recurso não encontrado.',
        'method_not_allowed' => 'Método HTTP não permitido para este endpoint.',
        'conflict' => 'A operação conflita com o estado atual do recurso.',
        'page_expired' => 'Sessão expirada. Refaça a requisição.',
        'validation_failed' => 'Os dados enviados são inválidos.',
        'too_many_requests' => 'Muitas requisições. Tente novamente em instantes.',
        'service_unavailable' => 'Serviço temporariamente indisponível.',
        'idempotency_key_missing' => 'Esta operação exige o cabeçalho Idempotency-Key.',
        'idempotency_key_invalid' => 'Idempotency-Key inválida: use de :min a :max caracteres entre letras, dígitos e - _ . : ~ + / =.',
        'idempotency_key_reused' => 'Esta Idempotency-Key já foi usada com uma requisição diferente. Use uma chave nova para uma operação nova.',
        'idempotency_request_in_progress' => 'Uma requisição com esta Idempotency-Key está em processamento ou terminou sem resultado registrado. Tente de novo em instantes; se persistir, consulte o estado do recurso antes de repetir a operação com uma chave nova.',
        'server_error' => 'Erro interno. Informe o correlation_id ao suporte.',
    ],

    'idempotency' => [
        'withheld' => 'Esta requisição já foi processada. O corpo da resposta original não é guardado nesta operação e não é exibido de novo.',
        'pruned' => ':count chave(s) de idempotência vencida(s) removida(s).',
    ],

];
