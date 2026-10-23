<?php

return [

    /*
    | Which gateway talks to "the insurer".
    |  - local: in-process simulated insurer (App\Services\MockInsurer)
    |  - http:  HTTP calls to the mock insurer routes (or any compatible API)
    */
    'driver' => env('INSURER_DRIVER', 'local'),

    'http' => [
        'base_url' => rtrim((string) env('INSURER_HTTP_BASE_URL', 'http://127.0.0.1:8000/api/mock-insurer'), '/'),
        'timeout_seconds' => (int) env('INSURER_HTTP_TIMEOUT_SECONDS', 5),
    ],

    'issuance' => [
        'max_attempts' => (int) env('ISSUANCE_MAX_ATTEMPTS', 3),
        'submission_lease_seconds' => (int) env('ISSUANCE_SUBMISSION_LEASE_SECONDS', 120),
        'pending_stale_minutes' => (int) env('ISSUANCE_PENDING_STALE_MINUTES', 15),
    ],

    'mock' => [
        // Never expose the simulator in production, whatever the env flag says.
        'enabled' => env('APP_ENV') !== 'production' && (bool) env('MOCK_INSURER_ENABLED', true),
        'default_scenario' => strtoupper((string) env('MOCK_INSURER_DEFAULT_SCENARIO', 'SUCCESS')),
        'temporary_failures' => (int) env('MOCK_INSURER_TEMPORARY_FAILURES', 1),
        'duplicate_deliveries' => (int) env('MOCK_INSURER_DUPLICATE_DELIVERIES', 3),
    ],

    'webhook' => [
        'secret' => (string) env('MOCK_INSURER_WEBHOOK_SECRET', ''),
        'verify_signature' => (bool) env('MOCK_INSURER_VERIFY_SIGNATURE', true),
        'signature_header' => 'X-Insurer-Signature',
    ],

];
