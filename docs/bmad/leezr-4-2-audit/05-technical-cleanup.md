# Leezr 4.2 — 05 · Assainissement (inventaire, sans suppression)

> **Rien n'a été supprimé.** Chaque élément porte sa preuve et une décision proposée : **Conserver** · **Consolider** · **Déprécier** (marquer, rediriger, prévenir) · **Supprimer plus tard après vérification** (références dynamiques, usages historiques, données DEV/PROD lues avant). Aucune table, colonne, route publique ou donnée historique n'est visée par une suppression directe.

## 1. Pages et routes front

| ID | Élément | Preuve | Décision | Condition préalable |
|---|---|---|---|---|
| TEC-01 | **16 pages-alias** (`/company/members`, `/company/plan`, `/company/billing`, `/company/people/hr`, `/company/people/roles`, `/company/workforce`, `/workforce/configuration`, `/workforce/contract/templates`, `/workforce/signature`, `/workforce/requirements`, `/workforce/documents`, `/workforce/employees`, `/dashboard`, `/platform/jobdomains/:id`, `/company/people/:personRef/:tab?` wrapper…) | Fichiers < 30 lignes, redirection vérifiée au navigateur (crawl : « → cible ») | **Conserver 12 mois** puis **supprimer plus tard** | Journal d'accès : 0 visite sur 90 jours ; aucun lien interne (grep) ; aucun e-mail envoyé avec ces URL |
| TEC-02 | Laboratoires `ui-lab`, `company/dev-lab`, `platform/presentation-lab` | `meta.devOnly` + garde `guards.js:26` ; inclus dans le bundle de production | **Consolider** : exclure du build de production (import conditionnel) | — |
| TEC-03 | Personnalisateur de thème Vuexy (engrenage flottant) | Visible pour tous les profils (captures) | **Déprécier** en production (UXP-12) | Vérifier que la préférence thème reste accessible ailleurs (menu utilisateur) |
| TEC-04 | `public/mockServiceWorker.js.disabled` | Fichier inerte depuis le LOT 1 (`6673e925`) | **Supprimer plus tard** | Aucun |

## 2. API et contrôleurs

| ID | Élément | Preuve | Décision |
|---|---|---|---|
| TEC-05 | `GET /api/whoami` (« Temporary identity diagnostic ») | Aucun appel front, aucun test ; commentaire « temporary » | **Déprécier** puis supprimer (SEC-11) |
| TEC-06 | `GET/PUT /api/platform/world-settings` | Aucune référence front ni test ; l'écran International utilise d'autres routes | **Vérifier** l'usage réel (logs) → **déprécier** |
| TEC-07 | Routes sans référence front statique : `POST billing/pay-now`, `PUT billing/subscription/billing-day`, `POST profile/disclosures(+/withdrawal)`, `POST register/funnel-track`, `POST workforce/planning/shifts/overstaffing-preview` | Recherche statique (testées en PHP) — appel possible par URL dynamique | **Vérifier** (appels dynamiques, `sendBeacon`) avant toute décision |
| TEC-08 | `/api/company/billing/timeline` appelé par le front alors que la route est `/api/billing/timeline` | UXC-01 | **Corriger** (P1) + test de contrat front ↔ routes |
| TEC-09 | 11 contrôleurs > 250 lignes (`ShiftController` 782, `SignatureController` 686, `MemberDocumentController` 353, `EmployeeController` 343, `EmailInboxController` 327, `WeekTemplateController` 316, `LeaveController` 312, `ContractController` 294, `EmployeeSelfController` 287, `DocumentRequestController` 278, `CompanyBillingController` 261) + `DB::` dans 2 contrôleurs | 3 échecs historiques `ControllerSizeInvariantTest` | **Consolider** (extraire use cases / read models) au fil des lots qui touchent ces fichiers — jamais en refonte isolée |

## 3. Catalogues, permissions, configuration

| ID | Élément | Preuve | Décision |
|---|---|---|---|
| TEC-10 | 13 rôles Platform `e2e_*` et comptes `e2e.*@leezr.com`, `prod@leezr.com` (base locale) | `data/catalog.json` | **Supprimer plus tard** : lire d'abord DEV/PROD ; retrait par commande de recette, jamais sur PROD sans décision |
| TEC-11 | Sociétés de test dans la base locale/DEV (`[RECETTE]…`, `OrgTest…`, `[M6E LOAD]…`, `NW`, `WT`) | `data/catalog.json` | **Conserver** (fixtures de recette, ADR-814) ; ne jamais les copier vers PROD |
| TEC-12 | Puce « Configuration historique » = chemin de configuration de module sans contrat de configuration guidée (ADR-803/804) | Capture Modules | **Consolider** : migrer les modules restants vers le contrat `setup`, puis supprimer le chemin historique |
| TEC-13 | Liste de champs historique du JobDomain (`default_fields`, affichée « legacy » dans l'onglet Avancé) coexistant avec les presets versionnés (ADR-793/798) et la personnalisation ADR-835 | `_JobdomainAdvanced.vue`, ADR-835 | **Conserver** comme source d'héritage ; documenter qu'elle n'est plus éditée à la main |
| TEC-14 | Permissions inertes (9, ADR-800) — plan de retrait ADR-807 | ADR-800 / ADR-807 | **Déprécier** selon le plan existant (pas de nouvelle décision) |
| TEC-15 | Module `workforce_referral` déclaré mais absent de `platform_modules` (base locale) | UXP-02 | **Consolider** : la fiche lit le manifeste ; `system:sync` à jouer sur DEV |
| TEC-16 | Deux définitions du MRR (dashboard/billing vs liste entreprises) | UXP-08 | **Consolider** en un read-model unique |

## 4. Dépendances front (candidats non importés — à vérifier)

`jwt-decode` (héritage de l'auth JWT de Vuexy, Sanctum est en place), `prismjs` / `vue-prism-component`, `roboto-fontface`, `@tiptap/extension-highlight|character-count|subscript|superscript`, `rollup-plugin-visualizer`, `tsx`, `@stylistic/stylelint-*`.
Décision : **vérifier** (configs, imports dynamiques, scripts) puis retirer celles réellement inutiles dans un lot outillage. Gain : taille d'installation, surface de dépendances.

## 5. Ce qui doit être conservé (faux amis)

- Routes publiques de signature (`/sign/:token`), onboarding public, webhooks signés : servent des liens déjà envoyés par e-mail.
- `/_recipe/identity` : sonde de recette (404 en production, nécessaire à la garde E2E).
- `public/webhook.php` : coquille 410 volontaire jusqu'au retrait du webhook GitHub (ADR-833).
