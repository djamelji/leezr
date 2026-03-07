# Billing Staging Scenarios

> Reproductible scenarios to validate ADR-228/229/232/233 hardening.
> Last updated: 2026-03-05

---

## Automated Tests

All scenarios are covered by `tests/Feature/BillingTripleRecoveryTest.php`:

```bash
php artisan test --filter=BillingTripleRecoveryTest
```

| Test | Scenario | What it validates |
|------|----------|-------------------|
| `test_scenario_a_webhook_lost_polling_activates` | Webhook lost, company polls success page | Leg 2 activates subscription, 1 invoice, 1 payment |
| `test_scenario_b_webhook_lost_cron_activates` | Webhook lost, cron runs after 10+ min | Leg 3 activates subscription via Stripe poll |
| `test_scenario_c_triple_trigger_idempotent` | All 3 legs fire (webhook + polling + cron) | Exactly 1 activation, 1 invoice, 1 payment |
| `test_scenario_d_concurrent_activations_serialized` | Two near-simultaneous activator calls | lockForUpdate serializes, no double charge |
| `test_scenario_e_polling_after_cron_returns_cached` | Cron already activated, company polls | Returns cached status, no duplicate work |

---

## Manual Staging with Stripe CLI

### Prerequisites

```bash
brew install stripe/stripe-cli/stripe
stripe login
stripe listen --forward-to https://leezr.test/api/webhooks/stripe
```

### Scenario A: Webhook Lost + Polling Recovery

1. Start checkout flow on UI (company page `/plan`)
2. Kill `stripe listen` before completing payment
3. Complete Stripe Checkout in browser
4. Return to success page — observe polling call to `GET /api/billing/checkout/status`
5. Verify: subscription activates via polling, no webhook needed

### Scenario B: Webhook Lost + Cron Recovery

1. Same as A, but don't return to success page
2. Wait 10 minutes (or run manually):
   ```bash
   php artisan billing:recover-checkouts
   ```
3. Verify: cron detects stale session, polls Stripe, activates subscription

### Scenario C: Dead Letter Replay

1. Modify `StripeEventProcessor` to throw on `checkout.session.completed` (temporary)
2. Complete a checkout — webhook arrives, fails, creates dead letter
3. Verify: `php artisan billing:ops-status` shows dead letters pending (exit 1)
4. Revert the modification
5. Run: `php artisan billing:webhook-replay`
6. Verify: dead letter replayed, subscription activated

### Scenario D: Cron Catch-Up

1. Stop the scheduler for 1 hour
2. Create several subscriptions ready for renewal
3. Start scheduler — observe `billing:renew` processing all backlog in one run
4. Verify: heartbeat updated, all renewals processed (catch-up via `<= now()`)

### Scenario E: State Machine Guard

1. In `php artisan tinker`:
   ```php
   $sub = Subscription::where('status', 'cancelled')->first();
   $sub->update(['status' => 'active', 'is_current' => 1]);
   // Should throw InvalidSubscriptionTransition in local/test
   ```
2. Verify: exception thrown, no state corruption

---

## Ops Health Check

After staging scenarios, verify system health:

```bash
# Should return exit 0 after clean scenarios
php artisan billing:ops-status

# Should return exit 0
php artisan billing:health-check

# Check heartbeat freshness
php artisan tinker --execute="App\Core\Billing\BillingJobHeartbeat::all()->each(fn(\$h) => dump(\$h->job_key . ': ' . \$h->last_status))"
```
