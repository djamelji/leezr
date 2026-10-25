# Billing 3.0 — LOT 5 : audit opérationnel des automatisations (2026-10-25)

> Objectif : vérifier que chaque automatisation est **planifiée**, **s'exécute réellement** et est **supervisable** (on sait si/quand elle a tourné, et si elle a échoué).

## Automatisations planifiées (`routes/console.php`) — toutes `withoutOverlapping`

| Commande | Cadence | Rôle | Heartbeat (supervision) |
|---|---|---|---|
| `scheduler:heartbeat` | chaque minute | preuve que le cron tourne | n/a (c'est la sonde) |
| `billing:renew` | quotidien | renouvellements (ADR-575/593) | ✅ |
| `billing:process-dunning` | quotidien | relances / impayés | ✅ |
| `billing:reconcile` | hebdo | réconciliation ledger↔documents | ✅ |
| `billing:recover-webhooks` | 10 min | rejeu webhooks échoués | ✅ |
| `billing:recover-checkouts` | 10 min | sessions de paiement bloquées | ✅ |
| `billing:check-dlq` | horaire | seuil dead-letter queue (alerte) | ✅ |
| `billing:collect-scheduled` | quotidien 06:00 | **encaissement SEPA** | ✅ *(ajouté ADR-631i)* |
| `billing:expire-trials` | quotidien | fin des essais gratuits | ✅ *(ajouté ADR-631i)* |
| `billing:check-trial-expiring` | quotidien | notification essai expirant | ✅ *(ajouté ADR-631i)* |
| `billing:check-expiring-cards` | quotidien | notification carte expirant | ✅ *(ajouté ADR-631i)* |

## Supervision (ADR-233 + ADR-631i)

- `BillingJobHeartbeat` enregistre `last_started_at` / `last_finished_at` / `last_status` / `last_error` / `last_run_stats` par `job_key`.
- Exposé à l'UI : `PlatformBillingController::recoveryStatus` → `heartbeats` (onglet **recovery**) + Prometheus `billing_heartbeat_last_run{job=...}` (`BillingMetricsExportController`).
- Dead-letters : visibles + **inspectables** (payload JSON, ADR-631g) + rejouables depuis l'UI.
- Alerte DLQ : `billing:check-dlq` passe le heartbeat en statut `alert` au-delà du seuil ; canal `Log::channel('billing')->critical`.

## Finding corrigé (ADR-631i)

**Couverture heartbeat partielle** : 6 commandes financières critiques (renew, dunning, reconcile, recover-webhooks, recover-checkouts, check-dlq) étaient supervisées ; **4 ne l'étaient pas** — dont `billing:collect-scheduled` (encaissement SEPA, **financier**) et `billing:expire-trials` (change l'état d'abonnement). Elles tournaient **en aveugle** : un admin ne pouvait pas savoir si elles s'étaient exécutées.

**Correctif** : instrumentation `BillingJobHeartbeat::start()/finish()` des 4 commandes (statut `alert` si échecs sur collect-scheduled). Dry-run **n'enregistre pas** de heartbeat (ne pas simuler une exécution réelle). Verrouillé par `BillingAutomationHeartbeatTest`.

## Résultat

- **10 automatisations** planifiées, exécutables, **100 % supervisables** (heartbeat → UI + Prometheus).
- Rejeu/alertes diagnosticables sans SQL.
- Aucune exécution financière réelle déclenchée pendant l'audit.
