# Billing Production Runbook

> Post ADR-228/229/232/233 hardening.
> Last updated: 2026-03-05

---

## 1. Business Invariants

| Invariant | Guard | Enforcement |
|-----------|-------|-------------|
| `is_current=1` only for `trialing`, `active`, `past_due` | Model boot (ADR-232) | Throws in test/local, logs critical in prod |
| Terminal states (`cancelled`, `expired`) have no outgoing transitions | Model boot (ADR-232) | Same |
| One `is_current=1` per company | UNIQUE(company_id, is_current) DB constraint | DB-level, cannot bypass |
| No double charge for same checkout | `CheckoutSessionActivator` idempotent (lockForUpdate + status check) | Application-level |
| No double payment for same provider_payment_id | `Payment::updateOrCreate` on `provider_payment_id` | Application-level |
| Webhook events processed once | `WebhookEvent` UNIQUE(provider_key, event_id) | DB-level |
| Checkout sessions tracked locally | UNIQUE(provider_key, provider_session_id) on `billing_checkout_sessions` | DB-level |
| Expected confirmations deduplicated | UNIQUE(provider_key, provider_reference) on `billing_expected_confirmations` | DB-level |

## 2. Scheduled Commands

| Command | Frequency | withoutOverlapping | Isolatable | Heartbeat |
|---------|-----------|-------------------|------------|-----------|
| `billing:renew` | daily | yes | yes | yes |
| `billing:process-dunning` | daily | yes | yes | yes |
| `billing:reconcile` | weekly | yes | yes | yes |
| `billing:recover-webhooks` | every 10 min | yes | yes | yes |
| `billing:recover-checkouts` | every 10 min | yes | yes | yes |

### On-demand commands (not scheduled)

| Command | Use case |
|---------|----------|
| `billing:ops-status` | Dashboard operational health (exit 0/1) |
| `billing:health-check` | Structural consistency check (exit 0/1) |
| `billing:webhook-replay` | Replay dead letter queue entries |
| `billing:ledger-check` | Verify ledger trial balance |
| `billing:period-close` | Close a financial period |

## 3. Incident Procedures

### 3.1 Stripe Down / Webhooks Backlog

**Symptoms:** `billing:ops-status` shows overdue expected confirmations, dead letters pending.

**Actions:**
1. Check Stripe status: https://status.stripe.com
2. Run `billing:ops-status` — check expected confirmations count
3. Wait for Stripe recovery — `billing:recover-webhooks` will auto-recover every 10 min
4. If dead letters accumulated: `php artisan billing:webhook-replay --dry-run` then `php artisan billing:webhook-replay`
5. Verify: `php artisan billing:ops-status` should return exit 0

### 3.2 Stuck `pending_payment` Subscriptions

**Symptoms:** Company can't access their plan, subscription shows `pending_payment`.

**Actions:**
1. Triple recovery should auto-resolve:
   - **Leg 1** Webhook — arrives within minutes (if Stripe up)
   - **Leg 2** Polling — company's success page polls `GET /billing/checkout/status`
   - **Leg 3** Cron — `billing:recover-checkouts` polls Stripe every 10 min
2. Check locally: `php artisan tinker` → `BillingCheckoutSession::where('status', 'created')->where('created_at', '<=', now()->subMinutes(30))->count()`
3. Force recovery: `php artisan billing:recover-checkouts`
4. If still stuck: check Stripe dashboard for session status, manually investigate

### 3.3 Dead Letter Queue Growing

**Symptoms:** `billing:ops-status` shows dead letters pending.

**Actions:**
1. `php artisan billing:webhook-replay --dry-run` — review what would be replayed
2. `php artisan billing:webhook-replay` — replay all pending (max 3 attempts per entry)
3. For a specific entry: `php artisan billing:webhook-replay --id=42`
4. If replay fails 3 times: dead letter marked `exhausted` — manual investigation needed

### 3.4 Dunning / Payment Failures

**Symptoms:** Companies receiving PaymentFailed notifications, subscriptions going past_due.

**Actions:**
1. `php artisan billing:ops-status` — check past_due/suspended counts
2. Check `billing.log` for dunning retry details
3. Dunning retries are automatic (daily cron), policy-driven intervals [1, 3, 7] days
4. After max retries: failure_action applies (suspend or cancel per billing policy)
5. Reactivation: automatic when all overdue invoices paid (bounded reactivation)

### 3.5 Reconciliation Drift

**Symptoms:** `billing:reconcile` reports drift.

**Actions:**
1. `php artisan billing:reconcile --dry-run` — see what drift exists
2. `php artisan billing:reconcile --repair` — auto-repair safe drift types
3. Review audit logs for correlation ID
4. Manual investigation for unsafe drift types

## 4. What to Check First

```bash
# 1. Overall health (exit 0 = OK, 1 = anomalies)
php artisan billing:ops-status

# 2. Structural consistency (exit 0 = OK, 1 = warnings)
php artisan billing:health-check

# 3. Recent billing activity (tail the billing log)
tail -100 storage/logs/billing.log

# 4. Heartbeat freshness
php artisan tinker --execute="App\Core\Billing\BillingJobHeartbeat::all()->each(fn(\$h) => dump(\$h->job_key . ': ' . (\$h->last_finished_at?->diffForHumans() ?? 'never') . ' — ' . (\$h->last_status ?? 'unknown')))"
```

## 5. Exit Code Reference

| Command | Exit 0 | Exit 1 |
|---------|--------|--------|
| `billing:ops-status` | No anomalies | Stale checkouts, overdue confirmations, pending dead letters, or failed heartbeats |
| `billing:health-check` | No structural issues | Missing invoices, orphan addons, stale open invoices, or Stripe drift |
| `billing:renew` | All renewals processed | Always 0 (failures logged, not exit-coded) |
| `billing:recover-webhooks` | Recovery complete | Always 0 (failures logged) |
| `billing:recover-checkouts` | Recovery complete | Always 0 (failures logged) |

---

## 6. Acceptance Checklist

- [ ] **Checkout stuck impossible** — 3 independent legs (webhook + polling + cron) ensure `pending_payment` resolves
- [ ] **Dead letter queue replay safe** — `billing:webhook-replay` idempotent, max 3 attempts, HTTP 200 always returned to Stripe
- [ ] **Heartbeats up to date** — `billing:renew`, `billing:process-dunning`, `billing:reconcile`, `billing:recover-webhooks`, `billing:recover-checkouts` all instrumented
- [ ] **State machine guard active** — `Subscription::TRANSITIONS` enforced on model save, `InvalidSubscriptionTransition` thrown in test/local
- [ ] **Catch-up queries safe** — all cron queries use `<= now()` (not equality), late runs auto-catch-up
- [ ] **Webhook tolerance correct** — Stripe signature verification at 300s (SDK default), no stale event rejection
- [ ] **Company isolation enforced** — `CheckoutStatusController` validates `company_id` ownership (403 if foreign)
- [ ] **Idempotency everywhere** — `CheckoutSessionActivator` lockForUpdate + status check, `Payment::updateOrCreate`, `WebhookEvent` unique constraint
- [ ] **No double charge** — activator checks `subscription.status !== pending_payment` before processing, concurrent calls serialized by row lock
- [ ] **Log channel consistent** — all billing operations log to `billing` channel (not default)
- [ ] **Scheduled commands protected** — `withoutOverlapping()` on all scheduled entries, `Isolatable` on all mutation commands
