# Billing 3.0 — LOT 7E : audit exhaustif des paramètres de configuration (2026-10-25)

> Contrôle réel de **toutes** les configurations Billing éditables depuis `platform/settings` (onglet facturation) + policies de paiement. Objectif : **aucun paramètre décoratif, inactif ou trompeur**.

## Dimensions transverses

| Dimension | PlatformBillingPolicy (30 clés) | Legacy policies (3 clés) |
|---|---|---|
| **Source de vérité** | `PlatformBillingPolicy` singleton (1 ligne, cache 1h), `::instance()->field` | `PlatformSetting::instance()->billing['policies']` |
| **Édition (UI)** | `_SettingsBilling.vue` → `useBillingPolicyStore` | idem → `paymentsStore` |
| **Endpoint écriture** | `PUT /billing/billing-policy` → `PlatformBillingPolicyController@update` | `PUT /billing/policies` → `BillingConfigController@updatePolicies` |
| **Permission** | `platform.permission:manage_billing` | `platform.permission:manage_billing` |
| **Validation** | inline `validate()` (enum `Rule::in`, bornes min/max, prefix `^[A-Z0-9\-]+$`) + invariants `UpdateBillingPolicyUseCase` (numéros non-décroissants ; `retry_intervals_days` count = `max_retry_attempts` & strictement croissant) | inline `validate()` (`required` + bornes) |
| **Traçabilité** | ✅ `AuditAction::BILLING_POLICY_UPDATED` avec diff (`UpdateBillingPolicyUseCase:49`) | partielle (service) |
| **Portée** | **globale** (singleton, pas par market) | globale |
| **Impact contrats existants** | **forward-only** : s'applique aux futures factures/renouvellements/relances. Factures historiques immuables (`billing_snapshot`) ; prix contractuels protégés (ADR-587/593, pas de rétro) | forward-only |

## Paramètres CONSOMMÉS (28) — comportement réel vérifié

| Clé | Consommateur backend (comportement réel) |
|---|---|
| invoice_prefix | `InvoiceNumbering:35` — préfixe du numéro de facture |
| invoice_next_number | `InvoiceNumbering:36/41` — prochain numéro + incrément |
| invoice_due_days | `InvoiceIssuer:60/100` — échéance de la facture |
| credit_note_prefix | `InvoiceNumbering:80` |
| credit_note_next_number | `InvoiceNumbering:81/86` |
| tax_mode | `TaxResolver:61` (+ `InvoiceIssuer`, `PlanChangeExecutor`, `PriceBreakdown`) — mode de taxe |
| default_tax_rate_bps | `TaxResolver:44` — taux par défaut |
| upgrade_timing | `PlanChangeTimingResolver:35` (+ `PricingEngine`) |
| downgrade_timing | `PlanChangeTimingResolver` / `SubscriptionCanceller:31` |
| interval_change_timing | `PlanChangeTimingResolver:34` |
| trial_plan_change_behavior | `PlanChangeExecutor:105/190` |
| trial_requires_payment_method | `RegisterCompanyUseCase:206` |
| trial_charge_timing | `RegisterCompanyUseCase:210` (+ `StripePaymentAdapter`) |
| allow_negative_wallet | `WalletLedger:268` |
| auto_apply_wallet_credit | `InvoiceIssuer:230` (+ `InvoicePayNowService`) |
| grace_period_days | `DunningEngine:72` |
| max_retry_attempts | `DunningEngine:205` |
| retry_intervals_days | `DunningEngine:260` (+ `DunningTransitioner`, `AdminAdvancedMutationService`) |
| failure_action | `DunningTransitioner:66` |
| addon_deactivation_timing | `AddonCreditListener:44` (+ preview) |
| trial_expiry_notification_days | `BillingCheckTrialExpiringCommand:28` |
| reconciliation_lookback_days | `ReconciliationEngine:53/121` |
| default_billing_interval | `BillingCheckoutController:25` (+ `AddonBillingListener:73`) |
| admin_approval_required | `InternalPaymentAdapter:42` (+ `NullPaymentGateway`) |
| allow_sepa | `PaymentPolicy:97` |
| sepa_requires_trial | `PaymentPolicy:62` |
| sepa_first_failure_action | `DunningEngine:280` |
| max_payment_methods | `CompanyPaymentMethodController:40` |

## Paramètres DÉCORATIFS détectés (5) → RETIRÉS de l'UI (ADR-631h)

| Clé | Preuve de non-consommation | Décision |
|---|---|---|
| `proration_strategy` | Aucun lecteur ; `ProrationCalculator` calcule toujours en day_based (codé en dur) | Retiré de l'UI. (Honorer `none` = ADR futur avec tests de calcul proraté.) |
| `addon_billing_interval` | Aucun lecteur ; le listener addon lit `default_billing_interval` (`AddonBillingListener:73`), pas cette clé | Retiré de l'UI |
| `payment_method_expiry_check_days` | `BillingCheckExpiringCardsCommand` code en dur la fenêtre mois courant + suivant | Retiré de l'UI |
| `payment_required` | Aucun lecteur comportemental (seulement defaults + validation de `BillingConfigController`) | Retiré de l'UI |
| `annual_only` | Aucun lecteur comportemental | Retiré de l'UI |

**Note** : les colonnes/clés restent en base (round-trip conservé, aucune migration destructive) ; seuls les **contrôles d'édition trompeurs** sont retirés. Les valeurs stockées transitent inchangées. Un câblage futur de l'un de ces paramètres sera un ADR dédié (analyse métier + tests), jamais un contrôle « inactif » affiché.

## Bug corrigé (ADR-631h)

`CompanySubscriptionAdminController::cancelPreview` appelait `\App\Core\Billing\BillingPolicy::get('downgrade_timing', $market_key)` — **classe inexistante** (fatal error si exécuté) **et** API par-market non implémentée. Corrigé pour lire la **même** source que la résiliation réelle : `PlatformBillingPolicy::instance()->downgrade_timing`. Aperçu et action ne peuvent plus diverger. Verrouillé par `CancelPreviewPolicyTest`.

## Résultat

- **28 paramètres actifs**, chacun avec consommateur backend identifié et comportement réel.
- **0 paramètre décoratif** restant dans l'UI de configuration.
- **1 call-site fatal** corrigé + testé.
- Permissions, validation, traçabilité (audit diff), portée et impact contrats documentés et vérifiés.
