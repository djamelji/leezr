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

### Anomalies confirmées NON corrigées (DEFER avec justification — gating criterion #6)
- **Transactions #3 GenerateDocument, #10 UploadOwnDocument** : I/O fichier (vault/Storage) interleavé avec les écritures DB → wrapper une transaction autour d'un `Storage::` est incorrect (fichier non rollback). Fixable par **réordonnancement** (écrire le fichier d'abord, wrapper uniquement les 2 écritures DB) — à faire avec vérification runtime. Risque P3, atténué par idempotency key / re-upload idempotent.
- **Transaction #9 UpdateDocumentTemplate** : possède déjà un rollback manuel partiel ; gain marginal, à harmoniser avec les autres ultérieurement.
- **billing.tax_exemption.\*** : clés i18n **dynamiques** (préfixe + variable) → nécessite l'énumération des motifs d'exonération (donnée métier).
- **AR i18n ~96 % manquant** : effort de traduction dédié (fallback EN fonctionnel).
- **Endpoints jobdomains inline (createField/deleteField/createDocumentType)** : appelés par `platform/jobdomains/[id].vue` mais pointent vers des routes 404/405 → **feature cassée au runtime** (pas du code mort). Fix = corriger les routes/endpoints backend + test manuel de la page (navigateur indisponible). Risque de régression si corrigé à l'aveugle → différé, à faire avec vérification end-to-end.
- **Bandeau conformité mort** (`company/workforce/index.vue:225`) : `complianceWarnings` jamais peuplé → bannière jamais affichée. Retrait cosmétique différé (aucun impact visuel actuel).
- **Centimes→euros, self-service congé, frontière Core (ADR-562), Workforce async** : refactors à risque/décision produit — lots dédiés ultérieurs.
- **SSL dev ré-émission + rotation secret** : actions ops (root/panneau ISPConfig / fournisseur), hors code.
- **AR i18n ~96% manquant** : effort de traduction dédié (fallback EN fonctionnel).
