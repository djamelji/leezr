# Résultats E2E — Playwright (STAGING, lecture seule)

> Harness : `tests/e2e/smoke.spec.js`, `playwright.config.js` (Chrome, `baseURL` surchargeable). Exécuté contre **STAGING** (`E2E_BASE_URL=https://dev.leezr.com`) en **lecture seule** (login + navigation + contrôles de rendu + RBAC + responsive ; **aucune soumission de formulaire**, aucune mutation). Comptes seedés staging (DevSeeder).

## Résultat : **19 passed / 1 failed** (2,8 min)

### Parcours vérifiés au navigateur [NAV] — verts
- **Company/Employee** (`owner@leezr.test`) : `/dashboard`, `/billing`, `/members`, `/modules`, `/documents`, `/workforce`, `/workforce/employees`, `/workforce/time`, `/workforce/leave`, `/workforce/payroll`, `/workforce/dsn`, `/workforce/me` — rendu propre, **aucune clé i18n brute**, **aucune erreur console**.
- **Platform** (`admin@leezr.com`) : `/platform/companies`, `/platform/jobdomains`, `/platform/markets`, `/platform/catalog`, `/platform/documentation` — propres.
- **RBAC** : un user non-admin (`bob@leezr.test`) ne voit pas la gestion des membres (route gardée).
- **Responsive** : pas de débordement horizontal sur `/dashboard` (mobile 375px).

Chaque page vérifie : rendu sans erreur JS/console, absence de clés i18n brutes (regex sur un ensemble de namespaces).

### Échec : `/platform/billing/coupons` — 2× `401` en console
- **Observé** : la page **rend** (pas de clé brute, pas de crash) mais 2 ressources renvoient `HTTP 401` (console). La page charge en parallèle `/billing/coupons`, `/plans`, `/modules` (`_BillingCouponsTab.vue:247-249`).
- **Analyse** : `platform.2fa` et les gates `platform.permission` renvoient **403**, pas 401 ; et `/platform/companies` (même groupe `auth:platform` + `platform.2fa`) **passe**. Un run **local antérieur** était **20/20 vert**. → le 401 n'est pas expliqué par les gardes connues et n'est **pas reproduit en local**.
- **Classement** : **hypothèse à confirmer** (flaky/environnemental : parallélisme des 3 appels / état de session staging), **non une cause démontrée**. Voir `03-registre-anomalies.md` (ANO-002). **Non corrigé** (pas de correction à l'aveugle sans repro déterministe).
- Capture : `test-results/smoke-Platform-écran-rendu-…coupons/test-failed-1.png`.

## Limites E2E (honnêtes)
- Exécuté contre **staging**, pas prod (lecture seule sur données partagées, conforme à la consigne).
- **Soumissions réelles** (création/édition/suppression jusqu'à persistance) **non exercées** en E2E (évite de polluer les données partagées) — couvertes par les tests Feature PHPUnit [TEST].
- Parcours **employee self-service** complets (pointage/pause/congé avec persistance) : rendu vérifié [NAV], submit non exercé.
- Pas de simulation E2E de : Company suspendue, abonnement expiré en live, erreurs réseau — ces cas sont couverts [TEST] (RestrictedBusinessAccessTest, etc.).
