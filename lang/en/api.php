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
        'idempotency_key_missing' => 'This operation requires the Idempotency-Key header.',
        'idempotency_key_invalid' => 'Invalid Idempotency-Key: use :min to :max characters among letters, digits and - _ . : ~ + / =.',
        'idempotency_key_reused' => 'This Idempotency-Key was already used with a different request. Use a new key for a new operation.',
        'idempotency_request_in_progress' => 'A request with this Idempotency-Key is being processed or ended without a recorded result. Try again shortly; if it persists, check the state of the resource before repeating the operation with a new key.',
        'api_quota_exceeded' => 'The account request quota for this window is used up. It starts over when the window ends (see Retry-After and RateLimit-Reset).',
        'api_burst_exceeded' => 'Too many requests from the account this minute. Try again shortly.',
        'api_concurrency_exceeded' => 'Too many requests from the account in progress at the same time. Wait for one to finish and try again.',
        'api_route_limit_exceeded' => 'Too many requests from the account to this endpoint. Try again shortly.',
        'api_limits_unavailable' => 'The API limits could not be checked right now. Try again shortly.',
        'server_error' => 'Internal error. Provide the correlation_id to support.',
    ],

    'idempotency' => [
        'withheld' => 'This request was already processed. The original response body is not stored for this operation and is not shown again.',
        'pruned' => ':count expired idempotency key(s) removed.',
    ],

];
