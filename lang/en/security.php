<?php

declare(strict_types=1);

return [

    // Client response when security validation blocks the request.
    // Deliberately generic: it does not reveal what was detected.
    'blocked' => 'Request rejected by the security policy.',

    // Internal message recorded in the request log (attempt metadata).
    'blocked_log' => 'Malicious payload detected (:type).',

    // Note for an attempt the filter let THROUGH (observe mode or delegated
    // route) — written on the audit trail row.
    'observed_log' => 'Attack pattern detected (:type) — recorded, request not refused (observe mode).',

    // Rejected by size: the submitted content exceeds what security validation
    // inspects (config security.validation.max_inspected_bytes).
    'payload_too_large' => 'The submitted content is too large to be accepted.',

    'payload_too_large_log' => 'Content above the security inspection limit (:bytes bytes) — rejected without inspection.',

    // 429 page/response — per-client request limit (edge and sensitive routes).
    'throttled' => [
        'title' => 'Too many requests',
        'heading' => 'Easy there, let\'s slow down',
        'body' => 'We received too many requests from your connection in a short time. Please wait a moment and try again.',
        'retry' => 'You can try again in about :seconds seconds.',
        'action' => 'Try again',
    ],

];
