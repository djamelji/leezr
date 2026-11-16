<?php

/*
 * ADR-796.1 — documents configuration. `generated_disk` keeps the historical default ('local').
 * `scanner.driver` : none (default, fail-closed : no quarantined file is ever usable) | clamav |
 * simulated (DEV/tests only, refused in production — it is NOT an analysis).
 */
return [
    'generated_disk' => env('DOCUMENTS_GENERATED_DISK', 'local'),

    'scanner' => [
        'driver' => env('DOCUMENT_SCANNER', 'none'),
        'clamav_socket' => env('CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl'),
        'timeout' => (int) env('DOCUMENT_SCANNER_TIMEOUT', 30),
        'max_attempts' => (int) env('DOCUMENT_SCANNER_MAX_ATTEMPTS', 5),
    ],
];
