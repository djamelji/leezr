# BILLING 3.0 — AUDIT POST-PROD : PARCOURS COMPANY/PLATFORM + BILL-011

> DEV `5d2afbd` (déployé/servi) · PROD `d9e9467` (inchangé — aucun push main, aucun déploiement PROD). Corrections sur DEV uniquement, validées. Horloge PROD au relevé : 2026-10-26 23:28 UTC.

---

## 1. Incident moyens de paiement — causes racines (reproduites sur DEV, compte owner)

| Scénario | Observé (avant) | Cause racine |
|---|---|---|
| Lien onboarding `/company/billing?tab=payment-methods` | **404 « Page introuvable »** | Lien en **query-string legacy** ; le file-based router exige le **segment** `/company/billing/:tab`. La query est ignorée, et `/company/billing` nu n'avait **aucune route index** → 404. |
| `/company/billing` nu | **404** | Pas de route index. |
| `/company/billing/payment-methods` (canonique) | **rend** (onglets + contenu), 0 erreur JS | OK — route valide, onglet `payment-methods` mappé. |
| « Page blanche » PROD | — | Correspond au **même lien 404** et/ou au **bouton trompeur** (empty-state proposait « ajouter une carte » alors que `driver=null` → formulaire inutilisable). |

**Preuve** : reproduction Playwright sur DEV (owner) — `?tab=` → 404, bare → 404, canonique → rend sans erreur.

## 2. Corrections (DEV, 5 lots = 5 commits)

| Lot | Commit | Correctif |
|---|---|---|
| **1 — Navigation/route** | `08a5f4b` | Lien onboarding → **route nommée canonique** `{ name:'company-billing-tab', params:{ tab:'payment-methods' } }` ; ajout `billing/index.vue` qui **redirige** `/company/billing` (et tout `?tab=` legacy) vers l'onglet (plus de 404). |
| **2 — État provider** | `0606c2d` | `_BillingPaymentMethods` : quand `can_pay_online=false` (null/internal) → **état « Paiement en ligne indisponible » professionnel** + **lien support fonctionnel** (`company-support`) ; **aucun** bouton/tuile/formulaire d'ajout (pas de piège, pas de Stripe monté). Cartes existantes restent en lecture seule. |
| **3 — Politique onboarding** | `b51f469` | L'étape `payment_method` n'est **incluse** que si le provider peut enregistrer une carte (Stripe) ; sinon **exclue du dénominateur** (plus de blocage « 5/6 »). Frontend déjà dynamique. |
| **4 — Audit transversal** | `5d2afbd` | Balayage Company+Platform : **le lien onboarding était le SEUL** `?tab=` de navigation. Aucun autre `to:`/`push` cassé. |
| **5 — E2E** | `5d2afbd` | Suite `company-billing-navigation.spec.js` testant les **navigations complètes** (le défaut méthodologique précédent). |

## 3. Validation DEV (SHA servi `5d2afbd`)

- **Playwright `company-billing-navigation` : 6/6** ✅
  - bare `/company/billing` → redirige (no 404) ✅
  - legacy `?tab=payment-methods` → redirige vers l'onglet canonique (no 404) ✅ **← le bug 404 est résolu**
  - canonique `payment-methods` → **état réel, jamais blanc, 0 erreur JS** ✅
  - **refresh navigateur** sur la route → rend ✅
  - provider non card-capable → **aucun bouton piège**, état indisponible + lien support ✅
  - CTA onboarding payment-method **absent** (DEV owner provider non-Stripe → étape exclue, cohérent Lot 3) ✅
- **PHPUnit `OnboardingFilteredTest` : 8/8** ✅ (5 étapes sans provider card-capable ; 6 avec Stripe)
- **Build** clean ✅

## 4. BILL-011 — anomalie fiscale `tax_mode='none'`

**Architecture fiscale (ADR-310) :**
- **`TaxContextResolver`** = résolveur de **taux** autoritatif : domestique = taux standard du Market ; B2B intra-UE + TVA valide = **0 (reverse_charge_intra_eu)** ; B2C intra-UE = standard ; extra-UE = **0 (export_extra_eu)** ; VIES indisponible = 0 (reverse charge présumé).
- **`TaxResolver::resolveRateBps`** = taux basé Market (fallback `policy.default_tax_rate_bps`).
- **`TaxResolver::compute(subtotal, rateBps)`** = applique le **mode** : `inclusive` = extraction ; **`default` (dont `none` ET `exclusive`) = TVA ajoutée**. `rateBps<=0` → 0.

**Tous les chemins « zéro taxe » sont pilotés par le TAUX** (exonération/reverse-charge via TaxContextResolver, ou Market à 0), **jamais** par `tax_mode`.

**Détermination :** `tax_mode='none'` **n'est PAS une instruction de non-taxation**. C'est **(a) une ancienne valeur désignant le comportement par défaut (exclusive)** ET **(b) un défaut de contrat UI↔moteur** : l'option est libellée « Aucune taxe (mode: aucun) » alors que le moteur applique la TVA du Market. C'est pourquoi les factures FR portent 20 % malgré `tax_mode=none` — **sortie correcte, label trompeur**.

**Valeurs backend acceptées** : `['none','exclusive','inclusive']` (enum validé). Checkout/preview/renewal/addons passent tous par `TaxResolver::compute` (même sémantique). Factures historiques = snapshots **immuables**.

**Proposition SÛRE (aucun changement fiscal — à décider) :**
- ❌ **NE PAS** mapper `none → 0` (zéroterait les prochaines factures).
- ✅ **UI-only, computation identique** : soit **retirer l'option `none`** (défaut `exclusive`), soit la **relibeller** « Taxe standard (selon le Market) ». Aucun montant calculé ne change.
- Vérifier (fait) : aucun code ne traite `none` comme zéro ; seul `inclusive` diverge de `default`.
- **Non implémenté** (respecte « aucun changement fiscal/config PROD sans autorisation »). Décision métier requise.

## 5. Heartbeats post-déploiement (Phase 8) — ⏳ NON VÉRIFIÉ

Horloge PROD au relevé = **2026-10-26 23:28 UTC** → fenêtres quotidiennes (00:00) / 06:00 **non encore passées**. Les 4 jobs (`collect-scheduled`, `expire-trials`, `check-trial-expiring`, `check-expiring-cards`) n'ont **aucune ligne** heartbeat → **non exécutés**, **non déclenchés manuellement**. Contrôle **maintenu ouvert** (à relever après les fenêtres). Les 6 autres restent `ok`.

## 6. Audit Platform (parcours financiers)
Couvert par les lots précédents (ADR-631x) + specs E2E `platform-billing-cockpit`/`tabs` (rendu+nav sur DEV). L'audit transversal (§4) n'a trouvé **aucun** lien de navigation cassé côté Platform. Les actions sensibles ont déjà leur prévisualisation d'impact (dunning write-off, coupon, tariff-review) et leurs permissions (`view_billing`/`manage_billing`). Approfondissement interaction par persona → suite E2E à étendre (Platform) si souhaité.

## 7. Plan PROD (en attente d'autorisation)
- Promouvoir les lots 1-5 (navigation + états provider + onboarding) : `git merge --ff-only` DEV→main puis push → déploiement PROD. **Rollback** = release `20261024191921_1097d8a` (inchangée).
- Décider de BILL-011 (relabel/retrait UI de `none`, ou mapping `none→0` si une vraie non-taxation est voulue — impact fiscal, à trancher).
- Relever les 4 heartbeats après leurs fenêtres.
- **Aucun** push main / déploiement PROD / changement fiscal sans votre autorisation.

## Anomalies NON corrigées (listées)
- **BILL-011** : label `tax_mode='none'` trompeur (factures correctes) — proposition sûre documentée, non appliquée.
- Dette hors-billing : `EmailInboxController`/`EmployeeSelfController` > 250 lignes (pré-existant).
