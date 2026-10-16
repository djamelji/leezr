# Synthèse de maturité fonctionnelle par domaine

> Échelle : 🟢 fonctionnel & cohérent · 🟡 fonctionnel avec réserves / vérif partielle · 🟠 anomalie(s) ou vérif insuffisante · 🔴 cassé. Basée sur E2E staging (lecture seule), tests PHPUnit (3000+), audits antérieurs et cet audit.

| Domaine | Maturité | Fondement | Réserve principale |
|---|---|---|---|
| **Auth / Sessions** | 🟢 | [NAV][TEST] login 3 rôles | — |
| **Company Billing** | 🟢 | ANO-001 corrigé + [TEST] étoffés | régularisation PROD d'1 abo démo (ops) |
| **Company Dashboard / Modules / Documents** | 🟡 | [NAV] rendu propre | submit non exercé en NAV |
| **Workforce (7 pages)** | 🟡 | [NAV] rendu propre | persistance self-service non exercée en NAV |
| **RBAC / Entitlements** | 🟢 | [NAV] partiel + [TEST] solide | — |
| **Platform Companies / JobDomains / Markets / Catalog / Documentation** | 🟡 | [NAV] rendu | actions CRUD non exercées en NAV |
| **Platform Billing / Coupons** | 🟠 | ANO-002 (401 à confirmer) | repro déterministe requise |
| **Platform Plans / Modules / Users / Roles / Settings / Alerts** | 🟠 | [CODE]/[TEST] seulement | **non vérifiés au navigateur** |
| **Automations / Scheduler** | 🟢 | [TEST][API] + prod live (ADR-572) | — |
| **Billing transverse (ledger/dunning/essais/devise)** | 🟢 | remédiation finance (ADR-570/571) | — |
| **Isolation multi-tenant** | 🟢 | [TEST][CODE] (CompanyScope) | — |
| **i18n** | 🟡 | FR/EN complets ; AR ~partiel (fallback EN) | effort AR dédié (P3) |

## Verdict d'étape
Le **cœur transactionnel** (auth, billing, scheduler, multi-tenant, RBAC) est **fonctionnel et testé**. L'anomalie produit **la plus visible** (prochaine facture dans le passé) est **corrigée à la source** sur les deux surfaces. L'E2E réel confirme que **19 pages clés rendent proprement** en conditions navigateur.

**Ce qui reste pour un verdict « produit complet »** : parcours **CRUD réels au navigateur** (création/édition/suppression jusqu'à persistance) sur Company et Platform, et couverture **[NAV]** des pans Platform avancés (plans, modules, users, roles, settings, alerts) — aujourd'hui **[CODE]/[TEST]** seulement. Ces lots sont **identifiés et non déclarés faits**.

**Prochain lot d'audit recommandé** : (1) confirmer/clore ANO-002 (repro déterministe du 401 coupons) ; (2) campagne E2E **CRUD** sur une Company de test **dédiée** (données isolées) pour exercer les soumissions ; (3) couverture [NAV] Platform Plans/Modules/Users/Roles/Settings.
