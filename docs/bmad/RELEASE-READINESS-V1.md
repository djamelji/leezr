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
| §4 | **Recette Stripe sandbox réelle** | **BLOQUANT** | `STRIPE_KEY/SECRET/WEBHOOK_SECRET` **absents de `.env`** → checkout/webhook/ledger/réconciliation **non exécutés de bout en bout**. Les tests Stripe (fake-adapter + HMAC réel) couvrent le rail unitairement, **pas** une recette sandbox réseau. **Non simulé** (conforme §4). |
| §5 | Isolation tenant — **worker persistant staging** | **BLOQUANT (infra)** | Prouvé sur DEV (`tenancy:worker-proof`, queue database) mais **Horizon/redis-queue persistant non validé en staging** (pas de `.env.staging` ni worker persistant accessible). Procédure consignée (ADR-733.2 §5quinquies). |
| §5b | Isolation — SSE après révocation membership | **ACCEPTÉ AVEC JUSTIFICATION** | Inter-tenant prouvé sûr + fuite intra-tenant corrigée (ADR-733.2). Révocation en cours de flux : fenêtre ≤ `stream_timeout` 300 s, intra-tenant, pas d'escalade. Re-check périodique = amélioration future. |
| §5c | Isolation — `DB::table('<tenant>')` direct | **ACCEPTÉ AVEC JUSTIFICATION** | Occurrences filtrées manuellement par `company_id` ; gardes CI existantes (`TenantScopeBypassGuardTest` withoutGlobalScopes, `ControllerSizeInvariantTest` no-DB-in-controller). Règle CI dédiée `DB::table` = résiduel documenté. |
| §5d | Mode strict tenancy (18 chemins « B ») | **ACCEPTÉ AVEC JUSTIFICATION** | Strict non activable PROD tant que les 18 chemins legacy ne sont pas enveloppés (ADR-733.2). Sert de filet CI en test. Aucune fuite inter-tenant prouvée. |
| §6 | Répétition M4 à l'échelle | **ACCEPTÉ AVEC JUSTIFICATION** | Moteur fonctionnellement sûr (atomique, borné 2000 cellules, idempotent, refus explicite). N+1 franc (~15-25 req/cellule, ~30-50k req pour 50×8). 100×8 **refusé** par le guard (DomainException) → pas de risque d'exécution. Optimisation = plan séparé (ne pas relever 2000, ne pas réécrire le moteur — §6). |
| §7b | Contraste couleur vue Postes | **ACCEPTÉ AVEC JUSTIFICATION** | *serious* (non critique) sur petits chips (token amber Vuetify) ; info redondante avec le texte. Passage `flat` testé → aggrave (blanc/amber) ; conservé `tonal`. Pass palette = tâche design séparée. |
| §7c | Stall intermittent `/api/runtime-error` | **ACCEPTÉ AVEC JUSTIFICATION** | Artefact de harnais (erreur JS transitoire + report qui pend) sous bascule company rapide automatisée ; grille réellement visible ~32 ms. Non reproduit en usage réel. À investiguer hors release. |
| §7d | Parcours mobile 12-points complet | **ACCEPTÉ AVEC JUSTIFICATION** | Couverture ciblée 390 px (m6c/m6d verts) + réserve desktop-only de la grille. Matrice 360/390/430 + tablette exhaustive = recette dédiée. |

**Synthèse : 3 RÉSOLU · 7 ACCEPTÉ AVEC JUSTIFICATION · 2 BLOQUANT (dont 1 externe Stripe, 1 infra staging).**

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

> **NO-GO PROD** — à ce stade, avec une trajectoire GO claire et courte.

**Justification (tests verts ≠ autorisation commerciale) :** les blocages *fixables sur DEV* sont **RÉSOLUS** (accès pending_payment, jobs après suspension) et le socle est vert (3920 PHPUnit, 34+16 e2e, build clean). **MAIS** le critère final — « facturer correctement un abonnement en environnement de test » — **n'est pas prouvé de bout en bout** : la recette **Stripe sandbox réelle** n'a pas pu être exécutée (clés absentes, **non simulée** conformément à la consigne), et l'**isolation worker persistant** n'a pas été validée en staging réel. Ce sont deux prérequis **externes/infra**, pas des défauts de code.

**Conditions de passage à GO PROD :**
1. Fournir des **clés Stripe sandbox** + exécuter la recette §4 complète (checkout, carte test, accepté/refusé, webhook signé/dupliqué, facture, ledger, entitlement, réconciliation, régularisation) → verte.
2. Valider l'**isolation worker persistant** en staging (Horizon/redis-queue) via la procédure ADR-733.2.
3. (Recommandé) activer le mode strict en staging, traiter la règle CI `DB::table`, et la passe de contraste Postes.

Une fois (1) et (2) verts, le verdict peut basculer **GO** : le socle commercial et produit est prêt côté code et testé sur DEV.
