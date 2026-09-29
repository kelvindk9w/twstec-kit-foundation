<?php

// =============================================================================
// API error envelope (`api/*`) — see Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer
// and docs/api.md ("Contrato de resposta da API").
//
// `message` is human and translated; `code` is stable and NOT translated
// (that is what API clients branch on).
// =============================================================================

return [

    'errors' => [
        'bad_request' => 'Malformed request.',
        'unauthorized' => 'Missing or invalid credentials.',
        'forbidden' => 'This credential is not allowed to perform this operation.',
        'not_found' => 'Resource not found.',
        'method_not_allowed' => 'HTTP method not allowed for this endpoint.',
        'conflict' => 'The operation conflicts with the current state of the resource.',
        'page_expired' => 'Session expired. Please retry the request.',
        'validation_failed' => 'The submitted data is invalid.',
        'too_many_requests' => 'Too many requests. Try again shortly.',
        'service_unavailable' => 'Service temporarily unavailable.',
        'server_error' => 'Internal error. Provide the correlation_id to support.',
    ],

];
