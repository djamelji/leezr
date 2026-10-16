# Registre de progression — Master Refactor & Harmonization 360° (octobre 2026)

> Mission d'harmonisation (pas de nouvelles fonctionnalités métier). 1 lot = 1 objectif = 1 commit local. Pas de push, rien de destructif. Baseline de référence : **2955 tests OK / 12 skipped / 0 échec**, build clean.
> Principe : corriger le confirmé et localisé ; **différer** les refactors à risque ou à décision produit pour protéger la baseline verte.

## Portée exclue (par consigne)
- Pas de développement des 6 modules horizontaux (ADR-563).
- Pas de nouveau JobDomain.
- Pas de reconstruction si une correction localisée suffit.
- Pas de push, pas d'opération destructive distante.

## Plan de lots

| Lot | Objectif | État |
|---|---|---|
| L0a | Commit documentation audit 360° + vision | — |
| L0b | Commit travail UI/i18n Workforce en attente (ADR-558/559/560) | — |
| L1 | Sécurité & séparation d'environnement (seeders env-guard, secret→env, garde migrate:fresh prod) | — |
| L2 | i18n : clés manquantes (9 `workforceMe.*`, 5 EN billing) | — |
| L3 | Bug `watch({debounce})` → `watchDebounced` (6 pages) | — |
| L4 | Backend atomicité (transactions upsert) + code mort (endpoints fantômes, bandeau mort) | — |
| LF | Suite complète + build + rapport final | — |

## Travaux DIFFÉRÉS (DEFER) — justification
- **Centimes → euros (Workforce)** : changement de comportement transverse (contrat/paie + tests) → non localisé, risque de régression sur la baseline. Nécessite un lot dédié testé.
- **Self-service congé employé scoped** : nouvelle surface/parcours = proche du développement de fonctionnalité → hors périmètre harmonisation.
- **Frontière Core (sortir `app/Core/Workforce/`, ADR-562)** : refactor structurel large → lot dédié après sécurisation de la baseline.
- **Workforce async (jobs `ShouldQueue`)** : changement de comportement + UX de progression → décision produit + tests dédiés.
- **SSL dev ré-émission / rotation secret** : actions ops (root/panneau ISPConfig / rotation côté fournisseur) — hors code, signalées à l'utilisateur.

## Journal des lots

| Lot | Commit | Fichiers | Tests/build | Résultat |
|---|---|---|---|---|
| L0a | `c41aea7` | docs/audit-2026-10/, docs/vision/, docs/refactor-2026-10/ | n/a | Documentation versionnée |
| L0b | `d504832` | 12 pages workforce + en/fr.json (ADR-558/559/560) | build vert (préalable) | Working tree propre |
| L1 | `c551478` | `PlatformSeeder.php`, `deploy/deploy_release.sh` | AuthTest 9✓, Platform 18✓, lint OK | Sécurité/env séparés |
| L2 | `1a4c9ee` | `fr.json`, `en.json` | build 9.12s✓, parité 200/200 | 9+5 clés i18n |
| L3 | `35bb350` | 6 pages workforce (7 watchers) | build 8.96s✓ | debounce réel |
| L4 | `4eada3c` | `UpsertPlatformRoleUseCase.php` | Role filter 151✓, lint OK | atomicité rôles |
| LF | (en cours) | — | suite complète | validation finale |

### Détails des corrections appliquées
- **L1** : admin `password` limité à local/testing ; hors dev → `LEEZR_PLATFORM_ADMIN_PASSWORD` sinon aléatoire fort (non réécrit) ; comptes dev@/prod@ hors production ; SMTP/IMAP via `env()` (secret retiré du code) ; `deploy_release.sh` production = `migrate --force` + `SystemSeeder` idempotent (plus de `migrate:fresh` destructif en prod). **Action ops requise : ROTER le secret email exposé (un changement Git ne révoque pas un secret).**
- **L2** : 9 clés `workforceMe.*` (bulletins employé) + 5 clés `companyBilling.overview.scheduledChange*` EN.
- **L3** : `watch({debounce})` (ignoré par Vue) → `watchDebounced` (VueUse) sur 7 recherches de listes.
- **L4** : transactions sur create/update de rôle plateforme.

### Lots 5-10 (reprise — couverture par domaine via scans traçables)

| Lot | Commit | Objet | Tests |
|---|---|---|---|
| L5 | `d13ec3f` | Endpoints JobDomains cassés (404/405) → /field-definitions, /documents + normalisation réponse | FieldDefinitionTest + PlatformDocumentTypeCatalogTest (31✓) |
| L6 | `21e6bf6` | Formatage date locale-aware (2 fallbacks) | build✓ |
| L7 | `3f775ca` | 78 clés i18n brutes (coupons 62 + 16 autres) FR+EN | build✓, re-scan 1 restant (dynamique) |
| L8 | `1465cbd` | documentation.store → $platformApi (bon client/guard) | DocumentationModuleTest (33✓) |
| L9 | `74bbc80` | Atomicité P2 (ValidatePayroll, UpsertMarket, CreateCustomDocumentType) | 279✓ |
| L10 | `51e6acf` | Atomicité P3 ×8 (breaks, markets défaut, archives, deletes, payment) | 724✓ |

**Scans de couverture traçables (agents) :** 260 .vue / 5610 appels t() (i18n) · ~488 appels API / 52 stores (URLs) · 101 UseCases (transactions). Inventaires dans les rapports d'agents.

**Résultat des scans :** API URLs = 0 endpoint cassé restant (jobdomains corrigé L5) · i18n = 78/79 corrigées · transactions = 11/14 corrigées (L4+L9+L10).

### Phase finale — validation fonctionnelle (L11-L12)

| Lot | Commit | Objet | Tests |
|---|---|---|---|
| L11 | `29896cc` | Atomicité DB documents #3/#10 (étude idempotence : fichier écrit en amont, tx purement DB) | 228✓ |
| L12 | `30fd853` | Atomicité versioning template #9 (remplace rollback manuel partiel) | 44✓ |

→ **14/14 cas d'atomicité du scan désormais traités** (L4 + L9 + L10 + L11 + L12).

### Matrice des parcours vérifiés (preuve d'exécution — tests Feature)

| Espace · parcours | Résultat |
|---|---|
| PLATFORM · auth/users/companies | 22✓ |
| PLATFORM · jobdomains/markets/roles | 57✓ (1 skip) |
| PLATFORM · billing/paiements/coupons | 110✓ |
| PLATFORM · documents/i18n/ai/doc | 68✓ |
| COMPANY · auth/register/2FA | 105✓ |
| COMPANY · billing/abonnement/paiement | 653✓ |
| COMPANY · modules/membres/RBAC | 550✓ (2 skip) |
| COMPANY · documents/logistics/workflows/support | 401✓ |
| WORKFORCE · employés/contrats/org | 69✓ |
| WORKFORCE · temps/congés/planning | 87✓ |
| WORKFORCE · paie/DSN/bulletins | 533✓ |
| EMPLOYEE · self-service/pointage | 19✓ |
| TRANSVERSE · multi-tenant/sécurité | 45✓ (en classes isolées ; le filtre large affiche un faux-échec = contamination cross-classes connue, cf. ci-dessous) |
| **SUITE COMPLÈTE** | **2955✓ / 12 skip / 0 échec** |

### Limites de couverture (honnêteté)
- **Pas de navigateur** → validation UI live (rendu, console, réseau) NON exécutée ; couverture via tests Feature HTTP + build.
- **Pas de runner de test JS** (ni vitest ni jest) → les corrections frontend (i18n, debounce, client API) sont validées par le build + revue de code, pas par test unitaire JS.
- **Artefact de tests connu** : un `php artisan test --filter` large à travers de nombreuses classes produit des faux-échecs (`UniqueConstraintViolationException jobdomains.key` dans des setUp multiples). **La suite complète** (RefreshDatabase isole chaque classe) passe à 2955 — c'est le juge de vérité. (Optimisation paratest/sleep = axe perf séparé, non bloquant.)

### Validation visuelle Playwright (Chrome système, leezr.test) — preuves d'exécution

Faisabilité : **OK** (Chrome système → `channel:'chrome'`, aucun download ; app servie sur leezr.test). Harnais committé : `playwright.config.js` + `tests/e2e/smoke.spec.js` (`pnpm test:e2e`).

**31 écrans parcourus (1er passage), 0 flaggé** (console/JS/réseau/i18n) :
- Company : dashboard, billing, members, modules, documents, workflows, support, notifications, roles.
- Workforce : hub, employees, time, leave, planning, payroll, dsn, documents, organization.
- Employee : /workforce/me.
- Platform : dashboard, companies, billing, catalog, jobdomains, markets, roles(access), documentation, settings, operations, users, support.

**2e passage ciblé (interactions/permissions/responsive)** :
- Onglet **coupons** (`/platform/billing/coupons`) : **0 clé brute** + dialog création OK → valide le fix i18n des 62 clés **en conditions réelles**.
- **/workforce/me** : 0 clé brute → valide les 9 clés `workforceMe.*`.
- **Responsive 375px** : débordement horizontal = 0.
- **RBAC** : `bob@leezr.test` (user non-admin) sur `/members` → « 404 Page introuvable » + 0 bouton d'action → gestion non exposée (ACL appliquée côté UI).

**Harnais `pnpm test:e2e` : 20/20 tests verts** (2.2 min).

**Anomalies reproductibles trouvées : 0.** La validation visuelle **confirme** la santé fonctionnelle (cohérent avec la suite 2955✓ et l'harmonisation). Un 404 ressource console vu une fois = **non reproductible**, sans impact fonctionnel (non retenu).

### Parcours NON vérifiés visuellement (limites)
- Soumissions de formulaires réelles avec persistance (création employé/contrat/coupon jusqu'au succès) : dialogs **ouverts** et rendus validés, mais le submit complet n'a pas été exercé (éviterait de polluer la base dev). Couvert par les tests Feature HTTP côté backend.
- Actions destructives (suppressions) : non déclenchées en visuel (couvertes par Feature tests).
- Flux multi-étapes (wizard d'inscription complet, paiement Stripe réel) : non parcourus en E2E.
- Thème sombre / langues AR-ES à l'écran : non vérifiés visuellement (AR incomplet connu).

### Anomalies confirmées NON corrigées (gating criterion #6 — réel blocage / produit / externe)
- **billing.tax_exemption.\*** : clés i18n **dynamiques** (préfixe + variable) → nécessite l'énumération métier des motifs d'exonération (donnée produit).
- **AR i18n ~96 % manquant** : effort de **traduction** dédié (fallback EN fonctionnel).
- **Centimes→euros, self-service congé, frontière Core (ADR-562), Workforce async** : **décisions produit / refactors structurels** hors périmètre « harmonisation de l'existant ».
- **Rotation secret `@Crinshow31`, ré-émission SSL dev, backups/monitoring** : **opérations externes** (root/panneau ISPConfig/fournisseur) — impossibles avec les accès actuels.
- **Endpoints jobdomains inline (createField/deleteField/createDocumentType)** : appelés par `platform/jobdomains/[id].vue` mais pointent vers des routes 404/405 → **feature cassée au runtime** (pas du code mort). Fix = corriger les routes/endpoints backend + test manuel de la page (navigateur indisponible). Risque de régression si corrigé à l'aveugle → différé, à faire avec vérification end-to-end.
- **Bandeau conformité mort** (`company/workforce/index.vue:225`) : `complianceWarnings` jamais peuplé → bannière jamais affichée. Retrait cosmétique différé (aucun impact visuel actuel).
- **Centimes→euros, self-service congé, frontière Core (ADR-562), Workforce async** : refactors à risque/décision produit — lots dédiés ultérieurs.
- **SSL dev ré-émission + rotation secret** : actions ops (root/panneau ISPConfig / fournisseur), hors code.
- **AR i18n ~96% manquant** : effort de traduction dédié (fallback EN fonctionnel).

---

# Remédiation Finance & Facturation (octobre 2026)

> Déclenchée par `docs/audit-2026-10/10-audit-finance-facturation.md`. Décision produit : essai expiré sans abonnement valide → **accès restreint** (lecture + gestion abo/paiement, pas d'opérations métier ; données conservées ; réactivation automatique + idempotente). Ne jamais confondre suspension de Company et expiration d'abonnement. Pas de prod, pas de `migrate:fresh` distant, pas d'opérations massives sur données réelles, écritures de régularisation traçables.

## LOT 1 — Scheduler : source de vérité cohérente ✅
**Constat (audit §1)** : `routes/console.php` = 20 `Schedule::command`, `ScheduledTaskRegistry` = 18 → 2 tâches fantômes invisibles du cockpit (`alerts:escalate`, `registration:detect-abandoned`). Commentaire périmé « 14 tasks ».

**Fait :**
- Ajout des 2 entrées manquantes au `ScheduledTaskRegistry` avec métadonnées exactes (cron/fréquence/intervalle) alignées sur `console.php` (`*/15` escalate, `0 8` detect-abandoned). Commentaire corrigé.
- Clés i18n fr/en : `automations.tasks.alertsEscalate`, `automations.tasks.registrationDetectAbandoned`.
- **Test anti-dérive** `ScheduledTaskRegistryConsistencyTest` (4 tests, 143 assertions) : bijection stricte registry ↔ `console.php` + métadonnées complètes. Toute future `Schedule::command` sans entrée registry (ou l'inverse) casse le test.
- `php artisan schedule:list` confirme les 20 tâches wirées ; le registry en est désormais le miroir 1:1 supervisable depuis `/platform/operations/automations`.

**Ops (hors accès — à exécuter sur dev/prod, commandes + résultat attendu) :**
Le vrai cron `schedule:run` n'est **pas** installé par `deploy/deploy_release.sh` → `ScheduledTaskRun`=0, `globalHealth()=dead` (mesuré local). Correctif ops durable à poser sur le VPS (crontab de l'utilisateur applicatif) :
```
* * * * * cd /var/www/leezr/current && php artisan schedule:run >> /dev/null 2>&1
```
Vérification attendue après installation : `php artisan schedule:list` (20 lignes), puis sous 5 min `ScheduledTaskRegistry::globalHealth()['status']` passe de `dead` à `ok`, et le cockpit affiche les runs. **La durabilité réelle ne dépend pas de ce cron** : le LOT 2 ajoute un contrôle à la requête qui empêche un essai expiré de rester actif même scheduler arrêté.

## LOT 2 — Essai expiré → accès restreint (exigence dure) ✅
**Décision produit appliquée** : essai expiré / abonnement terminé → la Company garde **lecture + gestion abo/paiement**, perd les **opérations métier** (écritures). Données conservées. Réactivation **automatique + idempotente**. Distinct de la suspension de Company.

**Fait :**
- `Subscription::isExpiredTrial()` (statut `trialing` + `trial_ends_at` passé) et `restrictsBusinessAccess()` (essai expiré OU `expired`/`suspended`/`cancelled`). **Contrôle à la requête** : un essai dont la fenêtre est écoulée est restreint **même si le scheduler n'a jamais retourné le statut** → un cron arrêté ne peut plus laisser un essai expiré pleinement actif (corrige la cause T2+T1).
- `Company::governingSubscription()` (courant, sinon le plus récent → capte un essai déjà basculé `expired`, `is_current=null`) + `Company::hasBusinessAccess()`. Null (pas d'abo) = non restreint (onboarding/free). **Réactivation automatique/idempotente** : la décision est purement dérivée de l'état → dès qu'un abo valide existe, l'accès revient sans étape.
- Middleware `EnsureBusinessAccess` (`business.access`) dans le groupe `company.context` : lectures (GET/HEAD/OPTIONS) toujours permises ; mutations `billing/*`, `company/plan`, UX/sécurité en allowlist ; **toute autre mutation métier → 402** `subscription_required` + `restricted_access:true` en accès restreint. Séparé de la suspension (403 dans `SetCompanyContext`).
- Front `api.js` : branche 402 → toast d'avertissement (message backend).
- Tests `RestrictedBusinessAccessTest` (13) : logique d'état (active/live-trial/essai-expiré-sans-scheduler/flipped-expired/cancelled/past_due/sans-abo), réactivation idempotente, **distinction expiration ≠ suspension**, et intégration HTTP (mutation métier bloquée 402, lecture OK, mutation billing OK, abo actif non bloqué).

**Suite complète : 2972 passed / 12 skipped / 0 échec** (baseline 2955 + 17 nouveaux tests) — gate global sans aucune régression.

## LOT 3 — Dunning aligné sur le cycle essai gratuit / payant / impayé (audit T3/T5) ✅
**Modèle cohérent :**
- **Essai gratuit** (aucune facture encaissable) → `expire-trials` termine (trialing → expired → restreint via LOT 2). Pas de dunning.
- **Essai payant** (facture de conversion impayée) → **dunning** (trialing → past_due → retries → suspend/cancel). Jamais expiré en silence (plus d'orphelin T5).
- **Facture impayée** sur abo actif → dunning existant (inchangé).

**Fait :**
- `DunningTransitioner::markOverdue` : la transition past_due couvre désormais `['active','trialing']` (était `active` seul) → un essai payant impayé entre réellement en dunning. Un essai gratuit (sans facture) n'est jamais touché ici (corrige T3).
- `BillingExpireTrialsCommand` : `whereDoesntHave('invoices', open|overdue)` → n'expire plus un essai payant avec facture ouverte (il appartient au dunning) → supprime la facture orpheline sur abo expiré (corrige T5). Relation `Subscription::invoices()` ajoutée.
- Commentaires trompeurs « skip trialing — no dunning during trial » remplacés par la distinction free/paid explicite.
- Tests `TrialDunningLifecycleTest` (3) : essai payant impayé → past_due ; expire-trials termine le gratuit mais saute le payant en collecte ; non-régression active → past_due.

**Régression billing : 726 billing-domain tests OK** (dont `TrialConversionE2ETest`).

## LOT 4 — Grand livre double-entrée fiable (audit F1/F3/F4) ✅
> Changements de **logique de posting** (prospectifs). Aucune réécriture d'écritures historiques. Toute régularisation du passé passerait par `recordAdjustment` (traçable), non déclenchée automatiquement.

**Fait :**
- **F4 — idempotence** : garde `LedgerService::alreadyPosted(entry_type, reference_type, reference_id)` sur `recordInvoiceIssued`/`recordPaymentReceived`/`recordRefundIssued`/`recordWriteOff` → un rejeu webhook, la recovery dunning ou un double-confirm ne produit plus de double posting. `recordAdjustment` volontairement exclu (répétable).
- **F3 — isolation TVA** : `recordInvoiceIssued` crédite désormais `REVENUE` = **HT** (`subtotal`) et `VAT_PAYABLE` = **TVA** (`tax_amount`), débite `AR` = TTC (`amount_due`). Crédit wallet → split **proportionnel** HT/TVA sur l'`amount_due` (reste équilibré, pas de double comptage). TVA=0 → comportement inchangé (pas d'entrée VAT). `VAT_PAYABLE` ajouté aux comptes standards du `trialBalance`.
- **F1 — CASH pour tous les paiements** : hook modèle `Payment::booted()` → tout paiement `succeeded` avec `invoice_id` poste `CASH ← AR`, pas seulement le webhook Stripe. Les lignes `Payment` représentent du cash externe (stripe/internal/offline/sepa) ; le wallet est géré dans le wallet-ledger et ne crée pas de `Payment` → pas de double comptage. Idempotent (garde F4) donc sûr à côté de l'appel Stripe explicite et des rejeux. **Non-throwing** : échec ledger loggué, ne casse jamais le flux de paiement (contrat ADR-142 D3f).

**Tests :** `LedgerReliabilityTest` (7) — idempotence facture/paiement, isolation TVA (+ split wallet proportionnel + TVA-exempt), paiement non-Stripe solde l'AR, paiement échoué ne poste rien. **Régression : 808 tests billing-domain OK.**

**Différé (documenté, P2) :** `ReconciliationEngine` (F9) ne réconcilie que Stripe (pas internal/wallet/équilibre ledger) ; `FinancialForensicsService` (F8) n'a pas de détection d'anomalie. Non traités ici (risque + P2) ; la réconciliation comptable de test est fournie en synthèse finale.

## LOT 5 — Tarifs / devises par Market (audit F2/F10) ✅
**Constat clé** : le multi-devise est **intentionnel et testé** (`BillingCurrencyMarketTest` : société GB → GBP). Le « prix identique quelle que soit la devise » = **choix commercial assumé** (même nombre appliqué par devise, sans FX), **pas un bug** → ne pas inventer de prix. Le vrai bug technique = **MRR agrégeant des devises mélangées** étiqueté EUR.

**Fait (sans inventer de prix) :**
- `config/billing.php` : `reporting_currency` (devise d'étiquette de l'agrégat scalaire) remplace le `'EUR'` codé en dur ; documentation du modèle nominal sans FX. (Retrait de ma tentative initiale « mono-devise / supported_pricing » qui contredisait le design multi-devise réel.)
- `PlanRegistry` : commentaire trompeur « prices in DOLLARS » corrigé → **nombre nominal en unités majeures appliqué dans la devise du marché, sans FX** (ADR-570). Prix inchangés.
- `BillingMetricsCalculationService` : `computeMrrByCurrency()` + clé `mrr_by_currency` → chaque devise exacte ; `currency` piloté par config.
- **ADR-570** (tarification nominale multi-devise sans FX) + **ADR-571** (décision produit accès restreint + synthèse remédiation).
- Tests `PricingCurrencyTest` (3). Régression `BillingCurrencyMarketTest`/`BillingLotFTest` OK.

**Différé (P2, commercial)** : grille de prix par devise ou conversion FX sur plans — avant tout 2ᵉ marché non-EUR commercialisé.
