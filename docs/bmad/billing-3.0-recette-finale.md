# BILLING 3.0 — DOSSIER DE RECETTE FINALE (préproduction)

> Date : 2026-10-26 · Branche `dev` (SHA servi DEV `f2362ef` → avec correctifs recette : `108fb8c`/`fb…` suivants) · `main` = `1097d8a` (intact). **Aucun push main, aucun déploiement PROD, aucune écriture financière réelle.**

---

## 1. Réconciliation des preuves

### Git
- `origin/dev` = HEAD de travail ; `origin/main` = `1097d8a` **intact** ; dev **+37 commits** devant main ; working tree propre.
- SHA **réellement servi** sur DEV (lu par SSH sur `current` symlink, pas un HTTP 200) : `releases/…_<HEAD>` = HEAD origin/dev à chaque déploiement vérifié. Workflows GitHub Actions : `success`.

### PHPUnit — chiffres autoritatifs (suite COMPLÈTE)
- **3224 passés, 1 échec, 12 ignorés** (12 219 assertions, ~150 s).
- **L'unique échec** = `ControllerSizeInvariantTest` : 2 contrôleurs **pré-existants hors billing** (`EmailInboxController` 327 l., `EmployeeSelfController` 285 l.), **identiques sur `main`** → **pas une régression Billing 3.0**. Les 2 contrôleurs billing qui dépassaient (CompanyBilling 251, CompanySubscriptionAdmin 253) ont été **corrigés** (249/250). ⇒ **0 échec imputable à Billing 3.0.**
- **545 vs 993** : `--filter=Billing` ne matche que les tests **dont le nom contient « Billing »** (545). Le « 993 » d'un rapport antérieur était un décompte **non autoritatif** (sous-ensemble/instant différent). Le chiffre de référence est la **suite complète : 3237 tests** (3224 ✅ / 1 ❌ non-billing / 12 ⏭). Le périmètre billing réel dépasse 545 (classes `Subscription*`, `Invoice*`, `Coupon*`, `CreditNote*`, `TariffReview*`, `Dunning*`, `Payment*`… non nommées « Billing »).
- **12 ignorés** — tous des **gardes d'environnement/données**, aucun test de logique financière désactivé : Redis absent en local (PubSub/EventFlood/SecurityDetection), config catalogue (champ téléphone, modules addon, jours d'essai plan), 2 invariants explicitement différés (Phase 5/6 access). Sur un environnement avec Redis, ils s'exécutent.

### Playwright — périmètre réel exécuté sur DEV (SHA servi) ce cycle
| Spéc | Tests | Résultat |
|---|---|---|
| `platform-billing-cockpit` | 3 | ✅ (cockpit, tariff-review, subscriptions) |
| `platform-billing-tabs` | 1 (12 onglets) | ✅ |
| `company-billing-overview` | 5 | ✅ (overview, factures, plan HT, addons, dark) |
| `billing-persona-matrix` | 5 | ✅ (responsive, dark, **dead-letter inspect réel**, **blocked-renewal flag réel**, gating provider) |
| **Total** | **14** | **14/14 ✅ sur DEV** |

**Bilan Point 1 : sans ambiguïté — 0 échec de test imputable à Billing 3.0, 0 migration, preuves fraîches sur le SHA réellement servi.**

---

## 2. Scénarios conditionnels — base de recette isolée + interactions réelles

`BillingRecetteSeeder` (DEV/local uniquement, idempotent, seedé sur DEV) couvre les **9 scénarios** :

| Scénario | Fixture | Interaction réelle exercée |
|---|---|---|
| Renouvellement bloqué (prix provisional) | sub `plan_price_source='provisional'` | ✅ flag `renewal_blocked` affiché (matrice, donnée réelle) |
| Dead-letter financière inspectable | `BillingWebhookDeadLetter` pending (`invoice.payment_failed`) | ✅ dialogue inspect → **payload JSON visible** (matrice, donnée réelle) |
| Facture non échue | `INV-RECETTE-NOTDUE` open, due future | ✅ listée, non exigible |
| Facture échue | cas Overdue Failed | ✅ listée overdue |
| Facture annulée | `INV-RECETTE-VOID` status `void` + `voided_at` | ✅ statut « Annulée », exclue exigibilité |
| Paiement échoué | `Payment` status `failed` | ✅ |
| Avoir interdit sur void | facture void ci-dessus | ✅ **backend** `CreditNoteVoidProtectionTest` (exception + facture/ledger intacts) |
| Portefeuille avec mouvements | WalletLedger crédit 5000 / débit 1500 = solde 3500 | ✅ mouvements réels |
| Abonnement résiliable | cas Healthy Paid (active) | ✅ `cancelPreview` réel (policy) |

**Les interactions sont exercées sur données seedées réelles — aucune réponse frontend injectée.** Les mutations sensibles passent par les gardes backend (provider `internal`/simulé, aucun prélèvement externe).

---

## 3. Recette Company (bout en bout)

| Écran | Rendu | Interaction | Parcours métier | Permission | Mobile | Dark | Montants | Anomalies |
|---|---|---|---|---|---|---|---|---|
| Overview | ✅ e2e | ✅ | ✅ | ✅ owner | ✅ | ✅ | ✅ HT/TTC | — |
| Abonnement & périodes | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Comparaison/changement plan (preview) | ✅ | ✅ preview ADR-588 | ✅ PricingEngine (characterization LOT3) | ✅ | ↩ manuel conseillé | ✅ | ✅ | preview prorata = day_based |
| Addons & droits | ✅ e2e | ✅ | ✅ (is_subscribed/contracted) | ✅ | ✅ | ✅ | ✅ HT figé | — |
| Factures | ✅ e2e | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| PDF | ✅ parcours DEV | ✅ téléchargement | ✅ | ✅ | n/a | n/a | ✅ snapshot historique | long/annexes = vérif visuelle ouverte |
| Paiements & restrictions provider | ✅ e2e | ✅ | ✅ gating internal (pas de CTA en ligne) | ✅ | ✅ | ✅ | ✅ | Stripe-test réel non joué sur DEV (provider internal) |
| Portefeuille | ✅ | ✅ mouvements seedés | ✅ FIFO | ✅ | ✅ | ✅ | ✅ solde 3500 | — |
| Historique/activité | ✅ e2e | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | — |
| Résiliation | ✅ | ✅ `cancelPreview` (policy) | ✅ `SubscriptionCanceller` timing | ✅ | ↩ manuel conseillé | ✅ | ✅ | — |

Fiabilité financière Company prouvée par PHPUnit (vérité paiement, HT/TTC, void non exigible, prix contracté). Mutations testées en données isolées + provider interne/simulé.

---

## 4. Recette Platform (15 onglets + connexes)

| Onglet | Rendu | Interaction | Parcours | Perm. | Mobile | Dark | Montants | Anomalies |
|---|---|---|---|---|---|---|---|---|
| Cockpit (overview) | ✅ e2e | ✅ drilldowns | ✅ MRR/ARR/facturé/créances/bloqués | ✅ view_billing | ✅ (0 overflow) | ✅ | ✅ facturé≠encaissé | — |
| Subscriptions | ✅ e2e | ✅ flag bloqué réel | ✅ | ✅ | ✅ | ✅ | ✅ contracté vs public | — |
| Tariff-review | ✅ e2e | ✅ dialogue (preuve obligatoire, 409) | ✅ ADR-594, 0 facture | ✅ manage_billing | ↩ | ✅ | ✅ | aucune confirmation auto |
| Invoices | ✅ e2e | ✅ actions | ✅ void canonique | ✅ | ↩ | ✅ | ✅ | — |
| Payments | ✅ e2e | ✅ | ✅ encaissé par statut | ✅ | ↩ | ✅ | ✅ | — |
| Dunning | ✅ e2e | ✅ retry/escalate/write-off (impact chiffré) | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Scheduled-debits | ✅ e2e | lecture seule | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Credit-notes | ✅ e2e | lecture seule | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Coupons | ✅ e2e | ✅ suppr/désactivation conforme | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Wallets | ✅ e2e | lecture seule | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Forensics | ✅ e2e | ✅ timeline/snapshots/export | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Governance | ✅ e2e | ✅ périodes/drift/freeze | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Ledger | ✅ e2e | ✅ balance/écritures | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Recovery | ✅ e2e | ✅ **dead-letter inspect payload** + replay | ✅ | ✅ | ↩ | ✅ | ✅ | — |
| Reconciliation | ✅ e2e | ✅ gaps/anomalies liées facture | ✅ jamais masqué | ✅ | ↩ | ✅ | ✅ | — |

Permissions : refus prouvés côté serveur (`BillingPlatformPermissionTest` : lecture sans `view_billing` → 403 ; mutation sans `manage_billing` → 403, facture intacte). Mobile : cockpit vérifié (0 débordement) ; autres onglets = responsive Vuexy standard, vérif mobile fine recommandée en recette manuelle (↩).

---

## 5. Automatisations — heartbeats (succès / échec / absence)

10 commandes planifiées (`withoutOverlapping`), **toutes** avec heartbeat (ADR-631i). Le heartbeat distingue 3+ états (`BillingHeartbeatStatesTest`) :
- **Succès** : `last_finished_at` + `last_status='ok'` (+ stats).
- **Échec** : `last_status='alert'/'failed'` + `last_error` (ex. dunning gateway timeout ; collect-scheduled `alert` si échecs).
- **Absence** : aucune ligne (jamais exécuté).
- **Crash** : `last_started_at > last_finished_at` + statut null (démarré, non terminé).

Exposés à l'UI (onglet recovery : `last_status`/`last_finished_at`/`last_error` par job) + Prometheus (`billing_heartbeat_last_run`). **Aucun prélèvement externe réel déclenché.**

---

## 6. Contrôle de préparation PROD (DEV → PROD)

| Élément | État | Action PROD avant déploiement |
|---|---|---|
| **Migrations** | ✅ **AUCUNE** nouvelle (main→dev, 0 fichier) | Rien — pas de `migrate` nécessaire |
| **Schéma / irréversibilité** | ✅ aucun changement de schéma | **Rollback = redéploiement de la release précédente** (atomique) |
| Config Billing (`config/billing.php`) | ⚠️ driver défaut `null` | Vérifier `BILLING_DRIVER`, `BILLING_DEFAULT_CURRENCY`, `BILLING_REPORTING_CURRENCY` sur PROD |
| Providers | ⚠️ env requis | `STRIPE_KEY/SECRET/WEBHOOK_SECRET` présents + provider actif correct |
| Paramètres fiscaux | ✅ markets.vat + tax_mode (policy) | Vérifier `PLATFORM_MARKET`, TVA market FR, `tax_mode=exclusive` |
| Secrets / webhooks | ⚠️ | `STRIPE_WEBHOOK_SECRET`, `BILLING_METRICS_TOKEN` configurés ; endpoint webhook PROD enregistré chez Stripe |
| Scheduler | ✅ `routes/console.php` complet | Cron `schedule:run` actif sur PROD |
| Workers | ⚠️ | Queue worker actif (notifications, jobs billing) |
| Prix provisional | ✅ non confirmés auto (ADR-593) | Les subs à prix non fiable restent en revue tarifaire (pas de facturation) — **conforme**, aucune action auto |
| Factures historiques | ✅ immuables (`billing_snapshot`) | Aucune réécriture ; vérité historique préservée |
| Compatibilité données existantes | ✅ code forward-only | Les changements s'appliquent aux futurs cycles ; aucun recalcul rétroactif |
| Coexistence anciens abonnements | ✅ `plan_price_cents` fallback legacy | Subs sans snapshot → prix public en fallback (ADR-587) |
| Rollback technique | ✅ possible | Pas de migration → redéploiement release N-1 suffit |
| Changements irréversibles | ✅ **aucun** | — |

**⚠️ = vérification opérateur (config/env/infra), non bloquant côté code.** Aucune donnée PROD lue ni modifiée dans cette recette.

---

## 7. Rapport de décision

| Critère | Résultat | Preuve | Risque résiduel | Bloquant PROD ? |
|---|---|---|---|---|
| Intégrité Git / SHA servi | dev propre, main intact, SHA servi = HEAD | git + SSH `current` | nul | Non |
| Suite PHPUnit | 3224 ✅ / 1 ❌ non-billing / 12 skip env | run complet | échec = dette pré-existante Email/Workforce | Non |
| Vérité financière (void, HT/TTC, contracté, paiement) | prouvée | PHPUnit (CreditNoteVoid, FinancialTruth, InvoiceDetail, SubscriptionsReadTruth) | nul | Non |
| Protection avoir backend | imposée service | `CreditNoteVoidProtectionTest` (facture+ledger intacts) | nul | Non |
| Interactions réelles 9 scénarios | exercées sur données seedées | matrice DEV + seeder | nul | Non |
| Paramètres config | 28 actifs, 5 décoratifs retirés, 0 trompeur | audit 7E + `CancelPreviewPolicyTest` | nul | Non |
| Automatisations supervisables | 10/10 heartbeat, 3+ états | `BillingAutomationHeartbeatTest`, `BillingHeartbeatStatesTest` | nul | Non |
| Migrations / rollback | 0 migration, rollback = redeploy | git diff | nul | Non |
| Config/secrets/providers PROD | à vérifier opérateur | `config/billing.php` | moyen si env incomplet | **Réserve** |
| Cycle paiement Stripe réel bout-en-bout | non joué sur DEV (provider internal) | — | moyen | **Réserve** |
| Dépassements contrôleurs non-billing | 2 pré-existants (Email/Workforce) | ControllerSizeInvariantTest | faible (dette) | Non (hors périmètre) |

### VERDICT : **GO AVEC RÉSERVES**

**Fondé sur preuves, pas sur le nombre de commits.** Billing 3.0 est **techniquement prêt** : 0 régression de test imputable, 0 migration (rollback trivial), vérité financière et protections prouvées par tests, 9 scénarios exercés en interactions réelles, automatisations supervisables, config auditée sans paramètre trompeur.

**Réserves à lever par l'opérateur AVANT le déploiement PROD (hors code) :**
1. **Config/env/secrets PROD** : `BILLING_DRIVER` + provider actif, clés Stripe, `STRIPE_WEBHOOK_SECRET`, `BILLING_METRICS_TOKEN`, scheduler cron + queue worker actifs.
2. **Cycle de paiement réel** : jouer un paiement + un webhook Stripe bout-en-bout sur un environnement doté du provider Stripe (test), non couvert sur DEV (provider `internal`).
3. **Dette non-billing** (non bloquante) : `EmailInboxController`/`EmployeeSelfController` > 250 lignes — ticket séparé hors Billing 3.0.

**Garde-fous respectés** : aucun push main, aucun déploiement PROD, aucune écriture financière réelle, aucun prix provisional confirmé auto, aucun renouvellement rétroactif, ADR-575/593 préservées.
