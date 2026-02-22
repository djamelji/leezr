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

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
