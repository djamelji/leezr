# Plan de déploiement STAGING — audit sécurité & workflows (LOT 3)

> **Aucun push, aucun déploiement sans autorisation explicite.** Ce document prépare la livraison ; il ne l'exécute pas.

## 1. Inventaire des commits locaux (31 commits ahead of `origin/dev`)

Regroupés par thème (ordre chronologique inverse) :

| Thème | Commits | Nature |
|---|---|---|
| **Sécurité autorisations** | `066b7f7` (ANO-017 + audit transversal), `da3919d` + `2238e59` (ANO-014 workforce + planning/payroll/dsn/documents) | **fix backend** (routes), tests |
| **Workflows Workforce (fix)** | `988420c` (ANO-013 timesheet), `b279a86`+`3f06a40` (ANO-009/010), `8b2e4e4` (ANO-007), `42f5f13` (ANO-006) | fix front+back, tests |
| **Membres / Platform (fix)** | `73559fe` (ANO-012 update partiel), `4c7dfa8` (ANO-011 drawer user), `bf8460f` (ANO-004 save profil) | fix front+back, tests |
| **Billing / Modules (fix)** | `cee31d0`+`bf331d4` (ANO-003 seeder sellable), `3910adc`+`652e897` (ANO-001 is_current/overdue), `8a221a8` (i18n) | fix back + seeder |
| **ADR (docs)** | `25b4e57` (ADR-573/574) | docs |
| **Tests E2E + fixtures** | `00ac846`, `85f23b7`, `6e0dba9`, `188cb48`, `9fd82a7`, `4c4baeb`, `5d79bdd`, `a920d06`, `9d5a379`, `7837b7c`, `a88cb98`, `1fe0ce4`, `b683c3a`, `0303184` | tests + commandes E2E + docs audit |

**Dépendances d'ordre** : les commits sont cumulatifs sur `dev` ; livrer le lot **entier** (pas de cherry-pick partiel) — notamment ANO-014 (`da3919d`) **avant** son extension (`2238e59`) et avant ANO-017 (`066b7f7`).

## 2. Migrations / seeders / commandes / API

### 2.1 Migrations — **AUCUNE** ✅
`git diff --name-only origin/dev..dev` → **0 fichier** sous `database/migrations/`. **Zéro changement de schéma** → pas de `migrate` requis, risque DB structurel **nul**.

### 2.2 Seeders
| Seeder | Portée staging | Risque |
|---|---|---|
| `SystemSeeder` → `ModuleProductConfigSeeder` (ANO-003) | **Pertinent** : config produit `is_sellable` + `addon_pricing` | **Non destructif** : `skip` si `addon_pricing` déjà posé. Idempotent. À exécuter : `php artisan db:seed --class=SystemSeeder` (ou `ModuleProductConfigSeeder`). |
| `DevSeeder`, `BillingDemoSeeder` | **Dev-only** — ne PAS exécuter sur staging | aucun (non lancés) |

### 2.3 Nouvelles commandes artisan (8)
| Commande | Staging |
|---|---|
| `e2e:prepare-leave-fixture`, `e2e:prepare-payroll`, `e2e:reset-clock-day`, `e2e:reset-leave`, `e2e:reset-timesheets`, `e2e:reset-payroll`, `e2e:reset-module-addon` | **INERTES** — garde `app()->environment('local','testing')` → **refusent** de s'exécuter hors local/testing (vérifié). Déployées mais inoffensives. |
| `billing:regularize-current-subscriptions {--dry-run}` | Procédure séparée §6 — **dry-run d'abord** |

### 2.4 Changements d'API (comportement)
| Fichier | Changement | Impact comportemental |
|---|---|---|
| `routes/company.php` | **+permissions granulaires** sur workforce/planning/payroll/dsn/documents + `workforce/dashboard/statistics` | **BREAKING pour les rôles non-owner mal dotés** : un utilisateur non-owner sans la permission requise (`workforce.view`, `workforce.manage`, etc.) qui passait auparavant reçoit désormais **403**. **C'est l'intention du fix** (fermeture faille). Owner **bypasse** → jamais impacté. |
| `UpdateMemberRequest` | update partiel (ANO-012) | **Non breaking** : la création reste stricte ; l'update ne valide que les champs soumis. |
| `TimesheetController::statistics` | période optionnelle (ANO-006) | **Non breaking** : résilience accrue. |
| `CompanyBillingReadService` | `renewal_overdue` + `subscription_status` (ANO-001) | **Additif** (nouveaux champs lecture). |

## 3. Risques de modification des données existantes

| Risque | Évaluation |
|---|---|
| Schéma | **Nul** (0 migration). |
| Données métier | **Nul** au déploiement : aucun commit ne mute de données existantes. Les commandes E2E sont gardées. |
| **Régression d'accès** (authz) | **Réel, maîtrisé** : des rôles non-owner sans permissions workforce seront bloqués (403). **Mitigation** : §5 valide que les rôles « manager » staging portent bien les permissions ; sinon, les ajouter via la gestion des rôles (donnée, pas code). |
| Seeder `SystemSeeder` | Idempotent non destructif (skip si déjà configuré). |
| Front | Nécessite `pnpm build` (nouveaux `data-test`, drawers, stores). Pas de risque données. |

## 4. Procédure de déploiement STAGING + rollback

**Pré-requis** : branche `dev`, STAGING uniquement (user `jliouidevleezr` / web3), jamais prod.

### Déploiement
1. **Pré-vol** (local) : `php artisan test` vert, `pnpm build` clean, `git status` propre (fait — §Clôture).
2. Push `dev` → `origin/dev` *(sur autorisation)*.
3. Sur STAGING : `git pull` (déploiement atomique existant), puis :
   - `composer install --no-dev` (aucune dépendance nouvelle attendue).
   - `php artisan config:clear && php artisan route:clear && php artisan cache:clear`.
   - **Pas de `migrate`** (0 migration) — vérifier `php artisan migrate:status` = à jour.
   - `php artisan db:seed --class=SystemSeeder` (idempotent, config produit).
   - `pnpm install && pnpm build` (assets).
   - `php artisan route:cache && php artisan config:cache`.
4. Smoke immédiat (§5).

### Rollback
- **Code** : le déploiement atomique conserve la release précédente → `rollback` = repointer le symlink sur l'ancienne release + `route:cache`/`config:cache`. Aucune migration à annuler (0).
- **Seeder** : `ModuleProductConfigSeeder` non destructif → rien à défaire ; si besoin, restaurer `addon_pricing` depuis sauvegarde (il n'écrase pas l'existant).
- **Données** : aucune mutation → rollback code suffit.
- **Authz** : si un rôle légitime est bloqué, rollback non nécessaire — ajouter la permission manquante au rôle (données) ou, en dernier recours, rollback code.

## 5. Tests de validation post-déploiement (staging)

1. **Auth multi-rôles** : login owner company + login platform admin → 200, redirection dashboard.
2. **Sécurité (le cœur du lot)** :
   - Salarié sans permission → `GET /api/workforce/employees`, `/dashboard/statistics`, `POST /employees` → **403**.
   - Owner → mêmes endpoints → **200/201**.
   - Cross-tenant : user company A + `X-Company-Id=B` → **403**, sans données.
3. **Workflows** (sur données de démo staging, non destructif) : ouvrir `/company/workforce/me` (pointage/congé self-service), `/time`, `/payroll`, `/leave` → rendus propres, pas de 403 pour owner.
4. **Non-régression rôles** : confirmer qu'un rôle « manager » attendu conserve l'accès (sinon doter la permission).
5. **Billing** : `/company/billing/plan` → prochaine facture cohérente (pas de date passée pour un abo actif).
6. `php artisan schedule:list` / health scheduler = ok (inchangé ce lot).

## 6. Procédure de régularisation des abonnements historiques — **DRY-RUN d'abord**

> Corrige ANO-001 résiduel : abonnements actifs laissés `is_current=NULL` (prochaine facture gelée). **Aucun effet facturation** (pose `is_current=1` uniquement, idempotent, respecte la contrainte unique).

**Étape 1 — DRY-RUN (lecture seule, obligatoire)** :
```
php artisan billing:regularize-current-subscriptions --dry-run
```
→ liste les candidats (`[DRY-RUN] Would set is_current=1: sub #…`). **Aucune écriture.** Revoir la liste, vérifier qu'aucune company n'a déjà un `is_current=1` concurrent (ces cas sont **reportés, non modifiés** → décision manuelle).

**Étape 2 — LIVE (sur sign-off uniquement)** :
```
php artisan billing:regularize-current-subscriptions
```
→ pose `is_current=1` sur les orphelins sûrs. Idempotent (ré-exécutable). Journalisé (`Log::channel('billing')`).

⚠️ **Le mode par défaut (sans `--dry-run`) ÉCRIT.** Toujours exécuter l'étape 1 d'abord.

## 7. Go / No-Go

- ✅ 0 migration, seeders idempotents, commandes E2E inertes sur staging.
- ✅ PHPUnit + build + Playwright verts (voir clôture).
- ⚠️ **Point d'attention** : la fermeture de la faille authz peut bloquer des rôles non-owner mal dotés → valider §5.4 après déploiement.
- 🚫 **Prod** : hors périmètre tant que staging n'est pas validé.
