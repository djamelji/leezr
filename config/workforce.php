<?php

return [

    'dsn' => [
        // Gateway driver: 'null', 'file' (future: 'net-entreprises')
        'gateway' => env('DSN_GATEWAY_DRIVER', 'null'),

        // Global kill-switch: when false, forces NullDsnGateway regardless of driver.
        // Set to true ONLY when ready for real transmission.
        'submit_enabled' => env('DSN_SUBMIT_ENABLED', false),

        // Retry policy for gateway submissions
        'max_attempts' => (int) env('DSN_MAX_ATTEMPTS', 3),
        'backoff_base_seconds' => (int) env('DSN_BACKOFF_BASE_SECONDS', 1),

        // FileDsnGateway settings
        'file_disk' => env('DSN_FILE_DISK', 'local'),
        'file_target_dir' => env('DSN_FILE_TARGET_DIR', 'workforce/dsn/transmitted'),

        // Net-Entreprises settings (Sprint 7.1 — ADR-529, Sprint 8.1 — ADR-534)
        'ne_environment' => env('DSN_NE_ENVIRONMENT', 'sandbox'),
        'ne_service_code' => env('DSN_NE_SERVICE_CODE', '97'), // 97=test, 25=production
        'ne_auth_url' => env('DSN_NE_AUTH_URL', 'https://test-services.net-entreprises.fr/authentifier/1.0/'),
        'ne_deposit_url' => env('DSN_NE_DEPOSIT_URL', 'https://test-dsnrg.net-entreprises.fr/deposer-dsn/1.0/'),
        'ne_status_url' => env('DSN_NE_STATUS_URL', 'https://test-dsnrg.net-entreprises.fr/consulter-retour/1.0/'),
        'ne_download_url' => env('DSN_NE_DOWNLOAD_URL', 'https://test-dsnrg.net-entreprises.fr/telecharger/1.0/'),
        'ne_token_ttl_minutes' => (int) env('DSN_NE_TOKEN_TTL', 30),
        'ne_timeout_seconds' => (int) env('DSN_NE_TIMEOUT', 30),
        'ne_retry_attempts' => (int) env('DSN_NE_RETRY_ATTEMPTS', 2),

        // Polling settings
        'poll_initial_delay_seconds' => (int) env('DSN_POLL_INITIAL_DELAY', 15),
        'poll_interval_seconds' => (int) env('DSN_POLL_INTERVAL', 300),
        'poll_max_duration_hours' => (int) env('DSN_POLL_MAX_HOURS', 72),
    ],

];
