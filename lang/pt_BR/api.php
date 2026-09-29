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
        'server_error' => 'Erro interno. Informe o correlation_id ao suporte.',
    ],

];
