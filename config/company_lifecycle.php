<?php

/*
 * ADR-845 (Leezr 4.2 M8) — company end-of-relationship settings.
 *
 * `retention.markets` : data retention policy PER MARKET, as decided by the owner with legal counsel. EMPTY by
 * default : Leezr does not invent legal durations. While a market has no policy, the interface says so and NOTHING is
 * ever deleted automatically. Permanent deletion is not implemented (separate, documented and tested decision).
 * Example of the expected shape (illustrative, not a legal value) :
 *   'FR' => ['invoices' => ['years' => null, 'basis' => 'to be decided'], 'payslips' => [...], 'contracts' => [...]],
 */
return [
    'export' => [
        'ttl_days' => (int) env('COMPANY_EXPORT_TTL_DAYS', 7),          // archive kept, then the file is pruned
        'max_document_bytes' => (int) env('COMPANY_EXPORT_MAX_DOCUMENT_BYTES', 500 * 1024 * 1024), // documents beyond → listed as excluded
        'cooldown_minutes' => (int) env('COMPANY_EXPORT_COOLDOWN_MINUTES', 10), // one new request per company per window
    ],
    // ADR-846 (M8.1) — exceptional export started from Platform when the owner is unavailable. The request / double
    // approval / audit path exists, but EXECUTION stays OFF until the way a representative's authority is verified
    // (identity + mandate) is validated : approving then answers 409 and nothing is built.
    'exceptional_export' => [
        'enabled' => (bool) env('COMPANY_EXCEPTIONAL_EXPORT_ENABLED', false),
        'min_justification_chars' => 30,
    ],
    'retention' => [
        'markets' => [],
    ],
];
