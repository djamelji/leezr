# W-E2 — Administration visuelle des presets organisationnels (plan, NON implémenté)

> Dette identifiée en W-E/W-F : l'administration Platform des presets est **API-only** (CRUD + publish/archive
> via `PlatformOrganizationPresetController`, gardé `manage_jobdomains`). Aucun éditeur visuel : la structure
> (départements, fonctions, hiérarchie) se manipule aujourd'hui en **JSON brut**. W-E2 livre l'UI.

## Objectif
Permettre à un administrateur Platform autorisé de créer/éditer/versionner/publier des presets d'organisation
**sans manipuler de JSON**, via une interface visuelle d'arborescence.

## Portée proposée
- **Page Platform** « Presets d'organisation » (sous Platform, permission `manage_jobdomains`, pas d'accès implicite) :
  - Liste filtrable (jobdomain, market, profil, statut) — consomme `GET /platform/organization-presets`.
  - Cycle de vie : draft → **publish** → archive ; un preset publié est immuable (nouvelle version pour modifier).
- **Éditeur visuel de structure** :
  - Arbre de **départements** (ajout/renommage/déplacement/suppression, parent/enfant) — pas de saisie de clé technique (clés auto-dérivées, éditables en mode avancé).
  - **Postes/fonctions** rattachés à un département, avec niveau.
  - (Futur W-Q) établissements/services/équipes lorsque ces entités existeront.
  - Aperçu de l'arbre + diff de version.
- **Association** Market × JobDomain × `org_profile_key` (sélecteurs depuis les registres réels — jamais de saisie libre d'un jobdomain/market non déclaré).
- **Validation** : structure cohérente (parents existants, pas de cycle), labels requis, profil valide pour le jobdomain.

## Réutilisation
- API W-E existante (CRUD/publish/archive) ✅. 
- `OrgProfileRegistry`, `JobdomainRegistry`, `MarketRegistry` pour les sélecteurs.
- Composants Vuexy (treeview/cards/dialogs) — aucun design-system parallèle.

## Tests
Backend : validation structure, immuabilité d'un publié, RBAC `manage_jobdomains`, versionnement. 
Frontend/E2E : création→édition arbre→publish→résolution côté Company (le preset publié devient proposable).

## Hors scope W-E2
Application sur les Companies (reste W-E) ; établissements/équipes (W-Q).
