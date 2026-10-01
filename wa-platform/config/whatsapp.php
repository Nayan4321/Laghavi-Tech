<?php

return [
    // Meta Graph API version used for all Cloud API calls.
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),

    'graph_base' => env('WHATSAPP_GRAPH_BASE', 'https://graph.facebook.com'),

    // A global fallback verify token. Each number can also store its own
    // (whatsapp_numbers.webhook_verify_token); the webhook accepts either.
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'change-me-verify-token'),

    // App secret for validating the X-Hub-Signature-256 header on inbound
    // webhooks. Strongly recommended in production. Leave empty to skip
    // signature checking (e.g. during first local testing).
    'app_secret' => env('WHATSAPP_APP_SECRET', ''),

    // The 24h customer-service window length, in hours.
    'session_window_hours' => 24,
];
