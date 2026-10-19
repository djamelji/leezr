# Matrice des autorisations par route × scope (LOT 1 — audit transversal)

> Méthode : extraction du **middleware réellement résolu** par Laravel (`php artisan route:list --json`, 626 routes) + analyse automatique des endpoints **sensibles** (POST/PUT/PATCH/DELETE + export/download/generate/statistics) dépourvus de gate de permission, puis **probes empiriques** avec sessions réelles. `v-can` (front) n'est **jamais** considéré comme une protection.

## Couches de sécurité (ordre d'exécution)

| Scope | Auth | Contexte tenant | Module | Permission | Structure |
|---|---|---|---|---|---|
| **Company** | `auth:sanctum` | `SetCompanyContext` (`X-Company-Id` → **`isMemberOf` sinon 403**) + `EnsureBusinessAccess` + `SessionGovernance` | `company.access:use-module,{key}` (ModuleGate : global + entitlement + activation) | `company.access:use-permission,{key}` (`hasCompanyPermission`, **owner bypasse**) | `company.access:manage-structure` (rôle administratif) |
| **Platform** | `auth:platform` | `session.governance` + `platform.2fa` | `module.active:{key}` | `platform.permission:{key}` | — |
| **Public** | — (webhooks, login, audience) | — | — | signature provider (ADR-137) / throttle | — |

## Isolation multi-tenant — **VÉRIFIÉE**
- `SetCompanyContext` refuse (403) tout `X-Company-Id` dont l'utilisateur n'est pas membre (`isMemberOf`), **avant** tout contrôleur → pas de divulgation.
- Prouvé E2E (`security-cross-tenant.spec.js`) : `owner` (membre C1) → `X-Company-Id=2` sur `employees`, `members`, `shipments` → **403**, aucun tableau de données dans le corps.
- `CompanyScope` global (ADR-432) + `BelongsToCompany` : double défense (requête scellée à la company du contexte).

## Endpoints sensibles sans gate de permission — triage (47 détectés)
| Catégorie | Verdict | Détail |
|---|---|---|
| Self-service `/workforce/me/*`, `/profile/*`, `/notifications/*`, `/dashboard/onboarding\|widgets`, `/heartbeat` | **OK** | données **propres** à l'utilisateur ; permission non pertinente |
| Platform self `/platform/me/*`, `/platform/2fa/*`, `/platform/dashboard/*`, `/platform/theme-preference` | **OK** | données propres au platform-user |
| Public `login/logout/forgot/reset`, `audience/*`, `webhooks/*`, `runtime-error`, `email/inbound` | **OK** | non authentifié par design ; webhooks **signés** (ADR-137) ; `metrics-export` = **bearer token** (ADR-311, fail-closed, 403 sans token) |
| **`workforce/dashboard/statistics`** | **DÉFAUT → corrigé** | **ANO-017** : module-only → fuite stats RH à tout salarié ; gate `workforce.view` ajouté |

## Enforcement prouvé (empirique, sessions réelles)
| Cas | Résultat |
|---|---|
| Salarié sans permission → API management workforce (employés, timesheets, congés, paie, planning, stats) | **403** (ANO-014 + ANO-017) |
| Owner (bypass) → mêmes endpoints | **200** |
| Salarié → fiche RH d'un collègue (`employees/{id}`) | **403** |
| Company A → ressource Company B (header tampered) | **403**, sans divulgation |
| Platform user sans permission → `/platform-users`, settings, plans, automations | **403** (`platform-rbac-workflow`) |
| Fonctionnalité masquée UI → accès API direct | **403** (le gate serveur ne dépend pas de `v-can`) |

## Groupes de gestion — état des gates (company.php)
| Groupe | Gate |
|---|---|
| theme | module + `theme.manage` / `manage-structure` ✅ |
| billing | module + `billing.manage` ✅ |
| settings, roles, jobdomain, members, documents, modules, audit | module + permission **par route** (vérifié ; `SecurityInvariantTest` : dispatcher → 403) ✅ |
| logistics_shipments | module + `shipments.*` par route ✅ |
| workflows/automations | module + `automations.view/manage` ✅ |
| **workforce / planning / payroll / dsn / documents RH** | module + permission par route ✅ (**ANO-014** + **ANO-017**) |
| workforce/me (self-service) | module-only ✅ (données propres) |

## Risques résiduels (non bloquants)
- `BillingMetricsExportController` compare le bearer token avec `!==` (non constant-time). Impact pratique quasi-nul (token statique serveur). **Durcissement recommandé** : `hash_equals`. Non corrigé (hors défaut confirmé).
- Webhooks `billing`/`payments` : signature vérifiée dans le contrôleur (payments : ADR-137 confirmé). Billing dead-letter à re-auditer si réactivé.
