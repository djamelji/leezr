# Remédiation Finance — Matrice de scénarios & réconciliation (octobre 2026)

> Livrables de clôture de la remédiation (ADR-571 / ADR-570). Chaque ligne est **adossée à un test** exécutable. Référence lots : `docs/refactor-2026-10/registre-progression.md`.

## 1. Matrice accès métier par état d'abonnement

Décision produit : essai expiré / abonnement terminé → **accès restreint** (lecture + billing/paiement conservés, opérations métier bloquées). Décidé **à la requête** (indépendant du scheduler). Distinct de la suspension de Company.

| État abonnement | `is_current` | Fenêtre essai | Accès métier (écritures) | Lecture | Billing/paiement | HTTP mutation métier |
|---|---|---|---|---|---|---|
| `active` | 1 | — | ✅ complet | ✅ | ✅ | 2xx |
| `trialing` (fenêtre future) | 1 | future | ✅ complet | ✅ | ✅ | 2xx |
| `trialing` (fenêtre écoulée, **scheduler non passé**) | 1 | passée | ⛔ **restreint** | ✅ | ✅ | **402** |
| `expired` (essai basculé) | null | passée | ⛔ restreint | ✅ | ✅ | 402 |
| `cancelled` | null | — | ⛔ restreint | ✅ | ✅ | 402 |
| `suspended` (abonnement) | null | — | ⛔ restreint | ✅ | ✅ | 402 |
| `past_due` (dunning en cours) | 1 | — | ✅ (grâce dunning) | ✅ | ✅ | 2xx |
| `pending` / `pending_payment` (onboarding) | — | — | ✅ (pré-activation) | ✅ | ✅ | 2xx |
| **Aucun abonnement** (onboarding / free implicite) | — | — | ✅ (non gaté ici) | ✅ | ✅ | 2xx |
| **Company `suspended`** (≠ abo) | — | — | ⛔ **403** global (`SetCompanyContext`) | ⛔ | paiement only | 403 |

**Réactivation** : purement dérivée de l'état → dès qu'un abonnement valide (`active`) existe, l'accès revient **sans étape** (automatique + idempotent).

*Tests* : `RestrictedBusinessAccessTest` (13), `TrialDunningLifecycleTest` (3).

## 2. Matrice cycle de vie Essai / Dunning

| Type d'essai | Facture de conversion | Fin d'essai | Résultat |
|---|---|---|---|
| **Gratuit** (aucune facture encaissable) | — | `expire-trials` | `trialing` → `expired` → accès restreint |
| **Payant, conversion payée** | payée | `renew` | `trialing` → `active` |
| **Payant, conversion impayée** | `open`/`overdue` | dunning | `markOverdue` : `trialing` → `past_due` → retries → suspend/cancel. `expire-trials` **saute** (facture ouverte → appartient au dunning, pas d'orphelin) |
| **Abo actif, renouvellement échoué** | `open`/`overdue` | dunning | `active` → `past_due` → suspend/cancel |

*Tests* : `TrialDunningLifecycleTest`, `TrialConversionE2ETest`.

## 3. Réconciliation comptable de test

Scénario end-to-end (`LedgerReconciliationScenarioTest`) — multi-méthodes, TVA, idempotence :

- Facture 1 : **1000 HT + 200 TVA = 1200 TTC** → payée **offline** (hors Stripe).
- Facture 2 : **500 HT + 100 TVA = 600 TTC** → **write-off** (bad debt).
- Rejeu des postings (idempotence F4).

**Balance générale obtenue :**

| Compte | Solde (cents) | Vérifie |
|---|---|---|
| `AR` | 0 | 1200 payé + 600 write-off soldent les 1800 émis |
| `CASH` | +1200 | **F1** : paiement non-Stripe solde l'AR |
| `REVENUE` | −1500 | **F3** : HT seul (1000+500) |
| `VAT_PAYABLE` | −300 | **F3** : TVA isolée (200+100) |
| `BAD_DEBT` | +600 | TTC passé en perte |
| **Σ débit − Σ crédit** | **0** | équilibre global |
| Par `correlation_id` | 0 | chaque écriture équilibrée |

**Conclusion** : grand livre équilibré, TVA isolée, AR soldé quelle que soit la méthode, postings idempotents.

## 4. Scheduler — vérification ops (hors accès code)

Le cron OS `schedule:run` n'est pas installé par le déploiement. À poser sur le VPS (utilisateur applicatif) :
```
* * * * * cd /var/www/leezr/current && php artisan schedule:run >> /dev/null 2>&1
```
**Attendu après installation** : `php artisan schedule:list` → 20 tâches ; sous 5 min `ScheduledTaskRegistry::globalHealth()['status']` passe de `dead` à `ok` ; le cockpit `/platform/operations/automations` affiche les runs. Le registry est désormais le **miroir 1:1** de `console.php` (test anti-dérive `ScheduledTaskRegistryConsistencyTest`). **Durabilité indépendante du cron** : le contrôle à la requête (§1) empêche un essai expiré de rester actif même scheduler arrêté.

## 5. Différé (documenté, P2 — hors exigences dures)

| Réf | Sujet | Raison du report |
|---|---|---|
| F9 | `ReconciliationEngine` ne réconcilie que Stripe | P2 ; réconciliation interne/wallet/équilibre ledger = lot dédié |
| F8 | `FinancialForensicsService` sans détection d'anomalie | P2 ; agrégation seule |
| F5/F6/F7 | Seeders de démo (conventions ledger divergentes) | P2 ; données de démo, pas de prod |
| F12 | Numérotation facture : compteur annuel jamais remis à 1 | P2 ; ni trou ni collision, non-conformité cosmétique |
| F2/F10 | Grille de prix par devise / FX | **Décision commerciale** ; à faire avant 2ᵉ marché non-EUR (ADR-570) |
| — | Régularisation d'écritures historiques | **Ops/décision** ; via `recordAdjustment` traçable, jamais automatique sur données réelles |
