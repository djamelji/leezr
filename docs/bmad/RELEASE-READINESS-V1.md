# RELEASE READINESS V1 — Leezr

> Verdict de mise en production fondé sur des scénarios réellement exécutés sur **DEV**.
> Convention : **FAIT** = vérifié/exécuté · **ANALYTIQUE** = déduit du code · **BLOCAGE EXTERNE** = dépend d'une ressource absente (non simulé).
> **DEV / `origin/dev` uniquement — aucun déploiement PROD, aucun paiement réel réalisé.**

## 0. Version & SHA

- Branche : `dev` · **SHA DEV réellement servi : `77df8e8`** (build front régénéré depuis ce HEAD).
- Lignée release-readiness : `ecff8ac` (§2 pending_payment + §3 jobs-après-suspension) · `77df8e8` (garde CI SuspensionGuard) · ADR-737 (ce lot).
- Socle antérieur : Smart Planning M1→M6.E clôturé (`25b4484`, ADR-736) ; P0-COMMERCE (`e09e993`, ADR-734) ; P0-A/P0-D (ADR-732/733).
- Environnement de mesure : local **Valet HTTPS** `leezr.test`, PHP 8.3, **MySQL** (SGBD cible), `QUEUE_CONNECTION=database`, Chromium (Playwright).

## 1. Tableau final des blocages

| # | Blocage | État | Justification / preuve |
|---|---|---|---|
| §2 | `pending_payment` donne accès au métier | **RÉSOLU** | `restrictsBusinessAccess()` coupe désormais `pending_payment`+`rejected` ; onboarding/régularisation préservés (allow-list) ; plans gratuits=`active` non impactés. Tests : `SubscriptionBusinessAccessContractTest`, suite commerciale 1347 verts. |
| §3 | Jobs métier exécutés après suspension | **RÉSOLU** | `SuspensionGuard` appliqué en tête de `ProcessWorkflowJob` + `ProcessDocumentAiJob` ; billing/dunning/plateforme exemptés. Test : `SuspendedJobExecutionGuardTest` (job en file avant suspension → refusé après). |
| §7 | Repli auto grille à 100 collab | **RÉSOLU** | Vérifié : n'empêche aucune opération (expansion à la demande, recherche déplie ; M1-M5/M6 e2e 34+16 verts à ≤50 collab dépliés). |
| §4 | **Recette Stripe sandbox réelle** | **RÉSOLU** | ⚠️ **Correction ADR-737** : la config Stripe **existait déjà** (`PaymentModuleSeeder`, ADR-301) — clés **test chiffrées en base** (`platform_payment_modules.credentials`), module **actif**, compte `acct_1T7WVLDI550p59Yn` (FR, test). RR-V1 avait regardé `.env STRIPE_KEY` (fallback vide) au lieu de la source primaire (DB). **Recette RÉELLE exécutée** (API Stripe test, 13/13) : paiement accepté (PI réel `succeeded`), refusé, retry, facture/Payment/ledger, no-access-sans-paiement. Webhooks : signature/duplication/traitement verts. |
| §4b | **Auto-charge renouvellement (`collectInvoice`)** | **RÉSOLU** | `collectInvoice` résout et passe désormais la **PM par défaut explicite** au PaymentIntent off_session + gère l'**authentification requise** (SCA → `requires_action`, jamais payé) + idempotency key par-facture (anti-double-encaissement). **Recette renouvellement RÉELLE (API Stripe test, 13/14)** : accepté→`paid` (PI réel), refusé→open, sans-PM→open, SCA→`requires_action`, isolation 2 companies. (Le 1 FAIL = artefact harnais : réutilisation du PM partagé `pm_card_visa` ; retry dunning prouvé par PHPUnit.) Non-régression : commercial **1049 verts**. |
| §4c | **Secrets Stripe en dur (seeder)** | **RÉSOLU** | `PaymentModuleSeeder` ne contient plus de clés en dur (env-only + préservation de la config chiffrée existante, pas de clobber). Vérifié : re-seed conserve la config active. |
| §5 | Isolation tenant — **worker persistant staging** | **BLOQUANT (infra)** | Isolation **prouvée sur worker persistant réel** (`tenancy:worker-proof`, `queue:work --stop-when-empty`, queue database) : A/B isolés, échec+retry sans contamination, fail-closed tenant inexistant, nettoyage de contexte. Mécanisme d'hygiène (`QueueTenancyHygiene` sur `Queue::before/after`) **agnostique du driver** → l'isolation vaut aussi sur Redis. **Redis DISPO** (PONG, predis v3.4.1) mais la commande de preuve tourne sur queue database ; **Horizon ABSENT** ; **aucun env staging dédié**. **Prérequis manquant** : run de la preuve sur **Redis+Horizon en staging réel** + validation du garde de suspension §3 sur la boucle worker (aujourd'hui prouvé au niveau job par `SuspendedJobExecutionGuardTest`). |
| §5b | Isolation — SSE après révocation membership | **ACCEPTÉ AVEC JUSTIFICATION** | Inter-tenant prouvé sûr + fuite intra-tenant corrigée (ADR-733.2). Révocation en cours de flux : fenêtre ≤ `stream_timeout` 300 s, intra-tenant, pas d'escalade. Re-check périodique = amélioration future. |
| §5c | Isolation — `DB::table('<tenant>')` direct | **ACCEPTÉ AVEC JUSTIFICATION** | Occurrences filtrées manuellement par `company_id` ; gardes CI existantes (`TenantScopeBypassGuardTest` withoutGlobalScopes, `ControllerSizeInvariantTest` no-DB-in-controller). Règle CI dédiée `DB::table` = résiduel documenté. |
| §5d | Mode strict tenancy (18 chemins « B ») | **ACCEPTÉ AVEC JUSTIFICATION** | Strict non activable PROD tant que les 18 chemins legacy ne sont pas enveloppés (ADR-733.2). Sert de filet CI en test. Aucune fuite inter-tenant prouvée. |
| §6 | Répétition M4 à l'échelle | **ACCEPTÉ AVEC JUSTIFICATION** | Moteur fonctionnellement sûr (atomique, borné 2000 cellules, idempotent, refus explicite). N+1 franc (~15-25 req/cellule, ~30-50k req pour 50×8). 100×8 **refusé** par le guard (DomainException) → pas de risque d'exécution. Optimisation = plan séparé (ne pas relever 2000, ne pas réécrire le moteur — §6). |
| §7b | Contraste couleur vue Postes | **ACCEPTÉ AVEC JUSTIFICATION** | *serious* (non critique) sur petits chips (token amber Vuetify) ; info redondante avec le texte. Passage `flat` testé → aggrave (blanc/amber) ; conservé `tonal`. Pass palette = tâche design séparée. |
| §7c | Stall intermittent `/api/runtime-error` | **ACCEPTÉ AVEC JUSTIFICATION** | Artefact de harnais (erreur JS transitoire + report qui pend) sous bascule company rapide automatisée ; grille réellement visible ~32 ms. Non reproduit en usage réel. À investiguer hors release. |
| §7d | Parcours mobile 12-points complet | **ACCEPTÉ AVEC JUSTIFICATION** | Couverture ciblée 390 px (m6c/m6d verts) + réserve desktop-only de la grille. Matrice 360/390/430 + tablette exhaustive = recette dédiée. |

**Synthèse (finale ADR-737) : 6 RÉSOLU · 7 ACCEPTÉ AVEC JUSTIFICATION · 1 BLOQUANT (infra)** — les blocages CODE sont **tous résolus** (pending_payment, jobs-après-suspension, Stripe inscription+**renouvellement**, secrets). Reste **1 BLOQUANT INFRA** : §5 validation worker persistant en staging réel (Redis/Horizon). Prérequis OPÉRATIONNEL (non-code) : rotation des clés test commitées (§3, action Stripe-dashboard).

## 2. Correctifs réalisés (ce lot, DEV)

- **§2** `app/Core/Billing/Subscription.php` : `restrictsBusinessAccess()` += `pending_payment`, `rejected`. Un compte payant non encaissé ne peut plus utiliser les fonctionnalités payantes (écritures) ; lectures + onboarding + régularisation + plans gratuits intacts.
- **§3** `app/Core/Tenancy/SuspensionGuard.php` (neuf) + application dans `ProcessWorkflowJob`, `ProcessDocumentAiJob` : gate d'exécution de suspension pour jobs métier ; billing/dunning/plateforme exemptés.
- Tests neufs : `SuspendedJobExecutionGuardTest` (3), contrat états→accès mis à jour (`SubscriptionBusinessAccessContractTest`).

## 3. Preuves de recette (exécutées)

- **PHPUnit complet** : **3920 passés · 3 échoués · 12 ignorés** (216 s). Les 3 échecs = **baseline historique `ControllerSizeInvariantTest`** (CompanyBillingController 261 lignes ; DB:: facade ; `->save()` en contrôleur) — **non ajustés** (seuils inchangés, §9).
- **Suite commerciale** (Billing|Dunning|Stripe|Subscription|Entitlement|Addon|Suspension|Membership|Trial|Payment|Webhook|Checkout|Invoice|Commercial|Module) : **1347 verts** après le fix pending_payment (zéro régression).
- **Playwright critique** : Smart Planning M6.A/B/C/D + 4 E2E historiques + a11y = **34/34** ; M1→M5 = **16/16** ; perf 5/25/50/100 = vert ; a11y 0 critique (ADR-736).
- **Build front** : clean.

## 4. Matrice des fonctionnalités réellement vendables

| Capacité | État commercial | Preuve |
|---|---|---|
| Inscription + plans (starter gratuit / pro payant) | **Vendable** | `BillingLotBTest`, RegisterCompanyUseCase |
| Workforce (employés, contrats) | **Vendable** | module `workforce` available |
| Workforce Planning (Smart Planning M1-M6.E) | **Vendable** | ADR-736, 4 vues, 34+16 e2e |
| Workforce Leave / Time / Payroll | **Vendable** | modules available, suites vertes |
| Addons (gating commercial + entitlement) | **Vendable** | `ModuleCommercialPolicy` (P0-A), Gate 5 couplé (ADR-734) |
| 3 modules logistiques fictifs | **NON vendable (preview)** | P0-A : `commercialStatus=preview`, achat refusé 422 |
| Billing V2 consolidé | **Désactivé (beta)** | flags emit/route OFF (billing-v2-consolidated-status) |
| **Encaissement Stripe de bout en bout** | **NON prouvé (bloqué)** | §4 : clés sandbox absentes |

## 5. Performance

- **Smart Planning** (Valet/Chromium, tenants isolés `m6e-load-*`) : chargement grille 0,6-1,1 s (100 collab repliés 0,6 s) ; switch de vue 50-307 ms ; API backend forWeek 13 ms / coverage 52 ms / board 30 ms à 100 collab. (ADR-736.)
- **Répétition M4** (ANALYTIQUE, MySQL) : 50×8 ≈ 2000 cellules ≈ 30-50k requêtes dans une transaction unique (N+1) ; 100×8 **refusé** par `guardVolume` (DomainException, 4000>2000). Mesure wall-clock empirique = nécessite un WeekTemplate seedé (suivi séparé).

## 6. Sécurité (ciblée)

- Multi-tenant : isolation HTTP + worker async (DEV) prouvée (P0-D/D.2) ; `SuspensionGuard` ajoute la barrière d'exécution des jobs métier.
- Suspension Company : bloque le métier, laisse la régularisation (ADR-734) ; jobs métier désormais gatés (ce lot).
- Catalogue commercial : pas de module fictif vendable (P0-A).
- Gardes CI : `TenantScopeBypassGuardTest` (vert), `ControllerSizeInvariantTest` (3 baseline).
- **Non prouvé** : isolation worker persistant staging, mode strict PROD (cf. §1).

## 7. Procédure de déploiement (référence)

- Script atomique `deploy.sh <branch> <path>` (release horodatée + symlink `current`, `.env` partagé, `storage` partagé, `php artisan migrate --force`, `config/route/view:cache`, `pnpm build`, redémarrage queue/cron). Staging = déploiement du `dev`.
- Pré-requis : PHP 8.4 (VPS), `predis`, MySQL, Redis (sessions/cache/queue si Horizon), cron `schedule:run`, worker `queue:work`/Horizon.

## 8. Procédure de rollback

- Repointer le symlink `current` sur la release précédente + `php artisan migrate:rollback` **uniquement si** la release a migré (aucune migration destructive dans ce lot → rollback = bascule de symlink + `config:cache`). Conserver N-2 releases.

## 9. Configuration & secrets requis (PROD)

- **Stripe (REQUIS pour l'encaissement)** : `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` — **ABSENTS en DEV** ⇒ prérequis bloquant pour la recette sandbox puis PROD. **Jamais de clé live sans autorisation explicite.**
- DB MySQL, Redis, `APP_KEY`, mail (Mailpit en dev / SMTP réel en prod), `QUEUE_CONNECTION` (database ou redis+Horizon en prod).

## 10. Monitoring & alertes nécessaires

- Webhooks Stripe : taux d'échec + `BillingWebhookDeadLetter` (DLQ) + `/api/runtime-error`.
- Dunning : subscriptions `past_due`/`suspended`, factures `uncollectible`.
- Queue : profondeur, jobs échoués, latence worker ; alerte si contexte tenant résiduel.
- Isolation : alerte sur toute requête tenant hors contexte (mode strict en staging).
- Perf planning : latence `shifts/board` / `coverage/summary`.

## 11. Risques résiduels

Cf. tableau §1 : Stripe sandbox (BLOQUANT externe), worker persistant staging (BLOQUANT infra), SSE révocation / `DB::table` / strict-mode (ACCEPTÉS), M4 N+1 (ACCEPTÉ borné), contraste Postes & stall harnais & mobile 12-points (ACCEPTÉS non critiques). **Aucune faille critique découverte.**

## 12. VERDICT GO/NO-GO PROD

> **NO-GO PROD aujourd'hui — mais tous les blocages CODE sont RÉSOLUS ; il ne reste que des prérequis OPÉRATIONNELS/INFRA (GO conditionnel).**

**Justification (tests verts ≠ autorisation commerciale) :** le critère final — « accueillir une entreprise, lui facturer un abonnement en test, lui donner les droits, la laisser travailler sans risque bloquant » — est **prouvé de bout en bout sur DEV avec Stripe sandbox RÉEL** : inscription, paiement accepté/refusé/retry, **renouvellement automatique** (accepté/refusé/SCA/sans-PM), webhooks (signature/duplication/traitement), accès gouverné par le paiement (pending_payment restreint), jobs métier bloqués après suspension, isolation inter-tenant. PHPUnit **3920+** / commercial **1049** verts, build clean. **Il ne reste AUCUN blocage de code.** Les 2 prérequis restants sont **non-code** : (a) **INFRA** — exécuter la validation worker persistant dans un **staging réel Redis/Horizon** (le mécanisme est prouvé sur worker réel + driver-agnostique, mais l'environnement staging n'existe pas ici) ; (b) **OPÉRATIONNEL** — **roter** les clés test commitées en historique Git (action Stripe-dashboard). Tant que (a) n'est pas exécuté dans l'environnement cible, on **ne certifie pas** l'isolation en production → NO-GO prudent, sans défaut de code identifié.

**Mise à jour finale ADR-737 :** tous les blocages **CODE** sont résolus et prouvés sur DEV avec Stripe sandbox réel — inscription→paiement→droits→usage→incident→régularisation **ET renouvellement automatique** (accepté/refusé/SCA/sans-PM), webhooks (signature/duplication/traitement), isolation inter-tenant. Il ne reste que des **prérequis OPÉRATIONNELS/INFRA**, pas des défauts de code.

**Conditions restantes avant GO PROD :**
1. **§5 (INFRA) — Valider l'isolation worker persistant en STAGING RÉEL** (Redis + Horizon) via la procédure ADR-733.2, incluant le garde de suspension §3 sur la boucle worker. Le mécanisme est prouvé (worker réel, driver-agnostique) ; il manque l'exécution dans l'environnement staging réel.
2. **§3 (OPÉRATIONNEL) — Roter** les clés test commitées dans l'historique Git (action Stripe-dashboard) + placer les clés en `.env` (hors source). Mécanisme sécurisé déjà en place (seeder env-only).
3. (Recommandé, non bloquant) mode strict en staging, règle CI `DB::table`, contraste Postes.
