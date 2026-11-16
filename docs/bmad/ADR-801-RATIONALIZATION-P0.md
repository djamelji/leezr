# ADR-801 : rationalisation P0 (routes, accès Platform, permission support)

- **Base** : `dev` @ `99692bd4`. DEV uniquement, rien poussé.
- **SHA livré** : `5b179f0b`.
- **Périmètre** : les trois P0 d'ADR-800, et rien d'autre.
  - Aucune donnée modifiée, aucune migration, aucun pivot RBAC touché.
  - Aucune page supprimée, aucun composant supprimé.
- **Fichiers non commités préservés** : `CLAUDE.md`, `typed-router.d.ts` (régénéré par le build, voir §7), `docs/bmad/DMS-ARCHITECTURE.md`.

## 1. P0-1 : les sous-composants `_*.vue` ne sont plus des routes

### Règles de génération vérifiées

- `unplugin-vue-router` 0.8.8 propose une option globale `exclude: string[]` (défaut `[]`).
- Aucun couple `foo.vue` + `foo/` n'existe dans `resources/js/pages` : l'exclusion ne transforme aucune route parente.
- Les `_*.vue` sont tous importés par chemin relatif ou par alias de fichier, jamais par nom de route. Recherche statique : **0** référence à un nom ou à un chemin de route `_…`.

### Correction

`vite.config.js` : `VueRouter({ routesFolder: 'resources/js/pages', exclude: ['**/_*.vue'] })`.

### Routes avant / après

Comparaison de `typed-router.d.ts` généré avant et après le build.

| | Avant | Après |
|---|---|---|
| Routes générées | 290 | **98** |
| dont `/…/_Nom` | 192 | **0** |
| Routes non `_` retirées | — | **0** (aucune page métier ne disparaît) |
| Routes ajoutées | — | 0 |
| Redirections manuelles (`additional-routes.js`) | 10 | 10, cibles toutes existantes |

### Contrôle des liens directs

- Les noms de route (`name: '…'`, nav serveur `'to' => ['name' => …]`) et les chemins (`redirect:`, `router.push/replace`, `to="…"`) de `resources/js` et `app/` ont été comparés aux 98 routes.
- **0 chemin introuvable.**
- **6 noms introuvables, tous déjà cassés avant ADR-801** : ils n'existaient pas non plus dans les 290 routes d'avant. Ce sont des résidus, voir §7.

### Anciennes URL `_`

Elles ne rendent plus le composant. Selon leur forme, elles retombent :
- sur une route paramétrée existante, par exemple `/company/billing/_BillingInvoices` → `billing/:tab` → garde `billing.manage` → **403** pour un chauffeur ;
- sur la page d'erreur ;
- ou sur la connexion de la portée concernée.

## 2. P0-2 : les pages Platform déclarent la permission que leur API exige

### Guard central réutilisé

La branche Platform de `plugins/1.router/guards.js` accepte maintenant `meta.anyPermission`. C'est **le même contrat que la branche Company**, pas un second système.

- Refus : toast, puis retour vers `/platform`.
- `/platform` devient un **répartiteur** sans permission propre (il garde `module: 'platform.dashboard'`, dont il est la route ; le guard ne le renvoie jamais sur lui-même, même si ce module est désactivé) :
  1. tableau de bord, si `view_dashboard` est détenu et le module actif ;
  2. sinon, la **première entrée de menu** accessible (`usePlatformNav().firstAccessibleRoute`, menu filtré côté serveur et hydraté avant `ready`) ;
  3. sinon, le compte personnel.
- **Aucune boucle** : un refus sur `/platform` lui-même renvoie `false`.
- Aucun utilisateur n'est présumé super-admin. Seul le rôle `super_admin` contourne, comme avant, côté store **et** serveur (`PlatformUser::hasPermission`) ; ce comportement n'a pas été modifié.

### Matrice des pages

L'ADR-800 en annonçait 13 : `consolidated-preview` était un faux positif de l'extracteur, la page déclarait déjà `view_billing`. Il y avait donc **12 pages à traiter**.

| Page | Module | Méta ajoutée | Permissions exigées par ses API (relevé `route:list`) | Onglets |
|---|---|---|---|---|
| `access/:tab` | platform.users | `anyPermission` : `manage_platform_users`, `manage_roles`, `view_audit_logs` | les mêmes, plus `manage_platform_user_credentials` et `view_company_users` sur des actions internes | déjà filtrés |
| `account/:tab` | platform.settings | **aucune** (compte personnel) | aucune (`me/*`, 2FA) | — |
| `catalog/:tab` | platform.modules | `anyPermission` : `manage_modules`, `manage_jobdomains`, `presets.view`, `manage_field_definitions`, `manage_document_catalog`, `manage_plans` | idem, plus des actions internes (`presets.edit/publish`, `manage_billing`…) | déjà filtrés |
| `communications/:tab` | platform.support | `anyPermission` : `manage_support`, `manage_documentation` | idem | déjà filtrés |
| `companies` | platform.companies | `permission` : `view_companies` | `view_companies` (liste), `manage_companies` (actions) | — |
| `dashboard/:tab` | platform.dashboard | `permission` : `view_dashboard` | `view_dashboard` (+ widgets gardés un par un) | — |
| `documents/:id` | platform.documents | `permission` : `manage_document_catalog` | idem | — |
| `markets/:key` | platform.markets | `permission` : `manage_markets` | idem | — |
| `messaging/:tab` | platform.email | `permission` : `manage_email` | idem (tous les onglets) | — |
| `operations/:tab` | platform.alerts | `anyPermission` : `view_system_health`, `view_alerts`, `security.alerts.view`, `view_ai`, `view_usage_monitoring`, `manage_automations`, `realtime.metrics.view` | idem | déjà filtrés |
| `settings/:tab` | platform.settings | `anyPermission` : `manage_theme_settings`, `manage_session_settings`, `manage_maintenance`, `manage_billing`, `manage_notifications`, `manage_markets`, `manage_translations` | général, thème et flags : `manage_theme_settings` ; sessions : `manage_session_settings` ; maintenance : `manage_maintenance` ; facturation : `manage_billing` ; notifications : `manage_notifications` ; international : `manage_markets` ou `manage_translations` | **filtrage ajouté**, même modèle que access/catalog, avec repli sur le 1ᵉʳ onglet autorisé |
| `support/:id` | platform.support | `permission` : `manage_support` | idem | — |
| `index` (répartiteur) | platform.dashboard (conservé) | aucune permission ; garde de module non bloquante pour `/platform` | — | — |

- **Restent volontairement sans permission** : `account/:tab` (personnel), `index` (répartiteur) et `presentation-lab` (harnais DEV, backlog P3-1).
- Un test d'invariant l'impose désormais : toute autre page Platform non publique doit déclarer `permission` ou `anyPermission`, avec des clés qui existent au catalogue.

## 3. P0-3 : `assign_support`

### Inventaire

- **Seul point d'entrée** : `PUT api/platform/support/tickets/{id}/assign` → `PlatformSupportTicketController::assign`. Aucun service intermédiaire, aucune autre vérification de permission dans le code.
- **Déclaration** : `PlatformSupportModule` déclare `assign_support`, et son bundle unique attribue `manage_support` **et** `assign_support` ensemble.
- **Rôles DEV**, lecture seule :
  - `assign_support` : `admin` ;
  - `manage_support` : `admin`, `support_only` ;
  - `super_admin` : contournement de rôle.

### Politique de compatibilité

- L'assignation est gardée par `assign_support|manage_support`.
- Personne ne perd l'accès, car `manage_support` reste suffisant.
- Personne n'en gagne aujourd'hui : **aucun rôle DEV ne détient `assign_support` sans `manage_support`**.
- **Aucun pivot modifié.**

### Matrice

| Action | super_admin | `manage_support` (admin, support_only) | `assign_support` seul | ni l'un ni l'autre |
|---|---|---|---|---|
| Assigner un ticket | ✔ avant / ✔ après | ✔ / ✔ | **✘ avant / ✔ après** | ✘ / ✘ |
| Lister, lire, métriques | ✔ / ✔ | ✔ / ✔ | ✘ / ✘ | ✘ / ✘ |
| Résoudre, fermer, priorité | ✔ / ✔ | ✔ / ✔ | ✘ / ✘ | ✘ / ✘ |
| Messages, notes internes | ✔ / ✔ | ✔ / ✔ | ✘ / ✘ | ✘ / ✘ |
| Pages `communications/support`, `support/:id` | ✔ / ✔ | ✔ / ✔ | ✘ / ✘ | ✘ / ✘ |

**Seul changement de comportement** : un rôle personnalisé qui recevrait `assign_support` seul (mode avancé de la matrice) peut assigner par l'API, et rien d'autre.

Il ne peut pas encore le faire **depuis l'interface** : la liste des tickets exige `manage_support`. Ouvrir la lecture aux assignateurs serait une élévation : c'est une décision du propriétaire, voir §7.

## 4. Tests

| Série | Résultat |
|---|---|
| `Adr801RationalizationP0Test` (nouveau) : exclusion du routeur, aucun `definePage` dans un `_*.vue`, invariant des pages Platform (≥ 25 pages lues, clés existantes), **refus HTTP sur 14 endpoints** des 12 pages pour un utilisateur Platform sans la permission, 401 sans authentification, accès accordé avec la permission, matrice support (assignation seule ⇒ 9 refus sur les autres actions ; `manage_support` conserve tout ; ni l'un ni l'autre ⇒ 403 sans effet) | **7/7** |
| Associés : `SupportTicketTest`, `BillingPlatformPermissionTest`, `PageLayoutMetaTest` | **41/41** (avec la série ci-dessus) |
| Suite complète (une seule fois, en fin de lot) | **4489 réussis, 12 ignorés, 4 échecs**. 3 sont les échecs historiques `ControllerSizeInvariantTest`, hors lot. Le 4ᵉ, `PageModuleAlignmentTest::admin_module_pages_declare_meta_module`, **venait d'ADR-801** : j'avais retiré `module` du répartiteur `/platform`, propriétaire de la route du module `platform.dashboard`. Il est corrigé (module rétabli, voir §2) et revérifié (`PageModuleAlignmentTest` + `Adr801…` + `PageLayoutMetaTest` : 19/19). La suite complète n'a **pas** été relancée après ce correctif, qui ne touche que le frontend ; le build et la recette navigateur ont été rejoués : 3/3 |
| Build frontend | propre (`✓ built`) |

### Recette navigateur

Lecture seule : toute requête API non-GET est bloquée, sauf la connexion. Spécification Playwright hors dépôt (scratchpad de session). Captures dans `docs/bmad/adr801/`.

| Scénario | Compte | Résultat |
|---|---|---|
| Platform autorisée | `dev@leezr.com`, rôle `admin` (**pas** `super_admin`) | Les 10 pages testées s'ouvrent : access, catalog, communications, companies, dashboard, markets, messaging, operations, settings, account |
| Platform non autorisée, sans `view_dashboard` | `usage-only@leezr.com` (`view_usage_monitoring`) | `/platform` → Opérations, seul l'onglet Usage visible. Les **10 pages refusées** (dashboard, companies, settings, messaging, catalog, support/1, markets, access, communications, documents/1) reviennent vers Opérations. **Aucune boucle** |
| Platform non autorisée, `view_dashboard` seul | `e2e.limited.933372` | `/platform` → tableau de bord ; companies, settings/billing, support/1 et operations sont refusées et renvoient au tableau de bord |
| Company sans accès Platform | `bob@leezr.test` (chauffeur) | `/platform/companies` → connexion Platform |
| Chauffeur sans Factures | `bob@leezr.test` | `/company/billing/overview` et `/company/billing/_BillingInvoices` → **403**, aucun appel `/api/billing/*` ; l'écran Factures n'est plus affiché |
| Support : assignation et administration séparées | — | Couvert par les tests HTTP (§3). **Non joué au navigateur**, car les comptes `support-only` et `roles-only` ne peuvent pas se connecter en DEV (§7) et créer un rôle aurait modifié les données DEV |

## 5. Fichiers modifiés

- `vite.config.js` (exclusion)
- `resources/js/plugins/1.router/guards.js` (`anyPermission` Platform ; pas de boucle sur `/platform`, que ce soit pour une permission ou un module)
- `resources/js/pages/platform/index.vue` (répartiteur)
- 11 pages Platform (méta) et `settings/[tab].vue` (filtrage des onglets)
- `routes/platform.php` (route d'assignation)
- `tests/Feature/Adr801RationalizationP0Test.php`
- Errata ADR-800 (`FRONTEND-INVENTORY`, `MODULE-RBAC-MAP`, `RATIONALIZATION-BACKLOG`, `ADR-800-AUDIT-SUMMARY`) : faux positif `consolidated-preview` et 6 lignes de garde mal extraites

## 6. Changements de comportement documentés

1. Une URL `/…/_Composant` n'affiche plus le composant.
2. Un utilisateur Platform sans la permission d'une page est renvoyé vers sa première page accessible, au lieu de voir un écran vide en 403.
3. Après connexion, un compte sans `view_dashboard` arrive sur sa première entrée de menu, et non plus sur un tableau de bord vide.
4. Paramètres Platform : seuls les onglets autorisés apparaissent.
5. `assign_support` permet d'assigner (API).

## 7. Résidus et risques

| # | Constat | Gravité | Proposition |
|---|---|---|---|
| R1 | **Connexion impossible en DEV** pour les comptes Platform limités dont l'id n'existe pas dans `users` (15 à 19 : `finance-reader`, `ai-only`, `roles-only`, `gov-mgr`, `support-only`). `sessions.user_id` a une clé étrangère vers `users` (`2026_03_18_000001_fix_sessions_user_fk`), alors que la session Platform y écrit l'id d'un `platform_user` : SQL 1452, puis 500 sur `/api/platform/me`. `dev@leezr.com` ne fonctionne que parce que l'id 2 existe aussi dans `users`. **Antérieur à ADR-801** | Élevée (tout environnement à sessions en base où les ids divergent ; PROD à vérifier) | Lot dédié : sessions Platform séparées ou retrait de la clé étrangère. **Rien modifié ici** (migration et données) |
| R2 | 6 noms de route cités mais inexistants, **déjà cassés avant** : `platform-documents` (retour de `documents/[id].vue`, et `navActiveLink`), `platform-jobdomains`, `platform-fields`, `platform-modules` (`NavSearchBar`), `platform-notifications` (`NotificationToast`), `platform-no-tenant` (`invariants.js`) | Moyenne (bouton retour et recherche cassés) | Lot P1 : pointer vers `platform-catalog-tab` avec l'onglet concerné |
| R3 | Un rôle `assign_support` seul ne peut assigner que par l'API : la liste des tickets exige `manage_support` | Faible | Décision du propriétaire : ouvrir la lecture de la file aux assignateurs ou non |
| R4 | L'assignation accepte n'importe quel utilisateur Platform comme assigné (comportement inchangé) | Faible | Restreindre aux détenteurs de `manage_support` ou `assign_support` (lot ultérieur) |
| R5 | Les anciennes URL `_` retombent sur des routes paramétrées, par exemple `/company/people/_PersonAddDrawer` → fiche personne introuvable | Faible (aucun lien n'y mène) | Aucune |
| R6 | `typed-router.d.ts` (non commité) a été régénéré par le build : 290 → 98 routes. L'état et le diff non commités d'avant le lot sont sauvegardés dans le scratchpad de session | Information | Le commiter dans un lot qui le décide |
| R7 | `presentation-lab` reste sans permission (harnais DEV) | Faible | P3-1 |
| R8 | Le contournement `super_admin` (rôle) est inchangé, côté store et serveur | Information | — |

## 8. SHA

Code : `5b179f0b` (DEV, non poussé).
