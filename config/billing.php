<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "null", "stripe"
    | The "null" driver writes plan_key directly to DB (no external service).
    | The "stripe" driver is a stub until real Stripe integration (ADR-011).
    |
    */

    'driver' => env('BILLING_DRIVER', 'null'),

    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'EUR'),

    /*
    |--------------------------------------------------------------------------
    | Reporting currency (audit F2/F10)
    |--------------------------------------------------------------------------
    |
    | Plan prices (PlanRegistry) are a SINGLE nominal figure applied as-is in each
    | market's own currency — NO FX conversion (a company on the GB market pays the
    | same NUMBER in GBP that an FR company pays in EUR). This is a deliberate
    | commercial simplification, not a per-currency price list; see ADR-570.
    |
    | `reporting_currency` is the label used for the scalar nominal MRR/ARR aggregate.
    | Because those scalars sum nominal amounts across currencies WITHOUT FX, they are
    | only exact in a mono-currency portfolio; `mrr_by_currency` gives the exact
    | per-currency breakdown. Do NOT read the scalar as a converted total.
    |
    */
    'reporting_currency' => env('BILLING_REPORTING_CURRENCY', 'EUR'),

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform identity (ADR-310)
    |--------------------------------------------------------------------------
    |
    | Market key and VAT number of the platform operator (seller).
    | Used by TaxContextResolver for intra-EU reverse charge determination.
    |
    */

    'platform' => [
        'market_key'  => env('PLATFORM_MARKET', 'FR'),
        'vat_number'  => env('PLATFORM_VAT_NUMBER'),
        'legal_name'  => env('PLATFORM_LEGAL_NAME', 'Leezr SAS'),
        'siret'       => env('PLATFORM_SIRET'),
        'rcs'         => env('PLATFORM_RCS'),
        'capital'     => env('PLATFORM_CAPITAL'),
        'address'     => env('PLATFORM_ADDRESS'),
        'email'       => env('PLATFORM_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerting (ADR-140 D3d)
    |--------------------------------------------------------------------------
    |
    | When enabled, critical audit events (drift detection, payment failures)
    | trigger email notifications. Optional webhook for external integrations.
    |
    */

    'alerting' => [
        'enabled'           => env('BILLING_ALERT_ENABLED', false),
        'email'             => env('BILLING_ALERT_EMAIL'),
        'webhook_url'       => env('BILLING_ALERT_WEBHOOK'),
        'slack_webhook_url' => env('BILLING_SLACK_WEBHOOK'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Repair (ADR-141 D3e)
    |--------------------------------------------------------------------------
    |
    | Controlled auto-repair of safe drift types detected by reconciliation.
    | Opt-in only, dry-run by default. Snapshot taken before every mutation.
    |
    */

    'auto_repair' => [
        'enabled'          => env('BILLING_AUTO_REPAIR_ENABLED', false),
        'dry_run_default'  => env('BILLING_AUTO_REPAIR_DRY_RUN', true),
        'safe_types'       => [
            'missing_local_payment',
            'status_mismatch',
            'invoice_not_paid',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Controls (ADR-143 D3g)
    |--------------------------------------------------------------------------
    |
    | Writeoff threshold: maximum single write-off amount in cents.
    | Set to 0 to disable the guard (allow unlimited write-offs).
    |
    */

    'writeoff_threshold' => (int) env('BILLING_WRITEOFF_THRESHOLD', 0),

    /*
    |--------------------------------------------------------------------------
    | Metrics Export (ADR-311)
    |--------------------------------------------------------------------------
    |
    | Bearer token for the Prometheus-format metrics endpoint.
    | Leave empty to disable the endpoint.
    |
    */

    'metrics' => [
        'token' => env('BILLING_METRICS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Consolidated billing (ADR-632 — V2)
    |--------------------------------------------------------------------------
    |
    | `route_activations` : OPERATIONAL gate (OFF by default). Even a company
    | contractually eligible to V2 (subscriptions.v2_effective_at) only routes
    | mid-cycle activations to the V2 BillableEvent path when this is ON. It must
    | stay OFF until M4 can consume/finalize consolidated invoices, so no real
    | company accumulates deferred events no BillingRun can bill.
    |
    */

    'consolidated' => [
        'route_activations' => (bool) env('BILLING_CONSOLIDATED_ROUTE_ACTIVATIONS', false),
        // ADR-632 (M4): OPERATIONAL emission gate (OFF by default). Even with everything else green,
        // the consolidated BillingRun emits a real invoice ONLY when this is ON. Kept OFF in all
        // deployed envs until the full V2 chain (payment/dunning/read models/PDF) is ready.
        'emit' => (bool) env('BILLING_CONSOLIDATED_EMIT', false),
    ],

];
