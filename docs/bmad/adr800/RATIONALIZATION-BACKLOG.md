# ADR-800 : backlog de rationalisation (lots indépendants)

> **Actions possibles** : CONSERVER · FUSIONNER · DÉPLACER · REMPLACER · DÉPRÉCIER · RETIRER.
>
> **Règles communes à tous les lots :**
> - aucune suppression sans preuve et sans redirection de compatibilité ;
> - pas de changement Billing ni paie ;
> - aucune donnée Company modifiée sans GO explicite ;
> - suite PHPUnit complète et build en fin de lot.
>
> Les preuves sont dans les autres livrables de `docs/bmad/adr800/`.

## Tableau de synthèse

| Lot | Priorité | Action | Objet | Effort estimé |
|---|---|---|---|---|
| P0-1 | P0 | RETIRER (routes) | 192 routes `_*.vue` sans garde | Petit |
| P0-2 | P0 | FUSIONNER (gardes) | 12 pages Platform sans `meta.permission` (le « sans aucune garde » était un faux positif, voir ADR-801) | Petit |
| P0-3 | P0 | REMPLACER | `assign_support` non vérifiée ; l'assignation exige `manage_support` | Petit |
| P1-1 | P1 | FUSIONNER | Listes `members` / `workforce/employees` → `people` | Petit |
| P1-2 | P1 | FUSIONNER | Routes Documents RH, Signatures, Modèles → `documents/:tab` | Petit |
| P1-3 | P1 | FUSIONNER / DÉPRÉCIER | Presets d'organisation (3 entrées) ; catalogue Presets en lecture ; overlays | Moyen |
| P1-4 | P1 | FUSIONNER | Deux écrans de règles de temps de travail → `_WorkingTimeSetup` | Moyen |
| P1-5 | P1 | RETIRER / BRANCHER | 9 permissions sans effet, plus `jobdomain.manage` et `PUT company/jobdomain` | Moyen |
| P1-6 | P1 | CONSERVER + exposer | Fonctions testées mais inaccessibles (revue des bulletins, campagnes, relance antivirus…) | Moyen |
| P1-7 | P1 | REMPLACER | Assistant commun de module, avec l'étape « Accès » par capacité | Grand |
| P1-8 | P1 | FUSIONNER | Porte d'écriture `theme.view` → matrice des rôles | Petit |
| P2-1 | P2 | REMPLACER puis RETIRER | Bascule vers l'initialisation versionnée ; retrait du flag et des lectures historiques | Grand |
| P2-2 | P2 | REMPLACER | 3 moteurs d'exigences → 1 (bascule mesurée) | Grand |
| P2-3 | P2 | RETIRER | Code mort prouvé (7 classes, 18 composants, `DependencyResolver`) | Petit |
| P2-4 | P2 | RETIRER ou adopter | Moteur `FeatureFlag` sans usage | Petit |
| P2-5 | P2 | DÉPRÉCIER | Endpoints orphelins confirmés et commandes de migration historiques | Petit |
| P2-6 | P2 | RETIRER (données DEV) | Rôles E2E et recette résiduels, brouillons | Petit, **sur GO** |
| P2-7 | P2 | DÉPLACER (affichage) | Modules coquilles logistics (`preview`) | Petit |
| P3-1 | P3 | DÉPLACER | Pages labo (`dev-lab`, `ui-lab`, `presentation-lab`) réservées au DEV | Petit |
| P3-2 | P3 | CONSERVER | 10 redirections historiques (mesure avant retrait) | — |
| P3-3 | P3 | REMPLACER | Secret `email/inbound` accepté dans le corps | Petit |
| P3-4 | P3 | DÉPLACER | `app/Core/Workforce` (95 fichiers) vers le module (doctrine ADR-562) | Grand, sans gain d'usage |

---

## P0 : sécurité, accès incorrects

### P0-1 : les sous-composants `_*.vue` sont des routes sans garde

- **Problème.**
  - `vite.config.js` déclare `VueRouter({ routesFolder: 'resources/js/pages' })` sans `exclude`, ce qui génère 192 routes `/…/_Nom`.
  - Elles n'ont **aucune méta**. `guards.js` n'y applique donc ni module ni permission.
  - Recette (chauffeur `bob@leezr.test`) : `/company/billing/overview` → 403, mais `/company/billing/_BillingInvoices` affiche l'écran. L'API renvoie 403 : **aucune fuite de données prouvée**.
- **Fichiers.** `vite.config.js`. `typed-router.d.ts` est régénéré, et ce fichier n'est **pas commité** aujourd'hui : il faut en tenir compte.
- **Solution.** `exclude: ['**/_*.vue']`.
- **Comportement conservé.** Tous les imports relatifs des `_*.vue`. Aucun lien ni aucun nom de route `_` n'est utilisé (vérifié : 0 référence).
- **Risques.** Un import par alias de route (aucun trouvé). Le typage `typed-router` change.
- **Tests.**
  - `PageLayoutMetaTest`.
  - Build.
  - E2E : une route `_` donne 404 / `$error`.
  - Les parcours principaux restent verts.
- **Critère d'acceptation.** 98 routes générées, plus les redirections.
- **Supprimable après validation.** Rien : seule la génération change.

### P0-2 : gardes UI incomplètes sur des pages Platform

- **Problème.**
  - ~~`platform/billing/consolidated-preview` n'a ni `module` ni `permission`~~ : **faux positif**, corrigé par ADR-801.
  - 12 pages Platform n'ont que `meta.module` : `access/:tab`, `account/:tab`, `catalog/:tab`, `communications/:tab`, `companies`, `dashboard/:tab`, `documents/:id`, `markets/:key`, `messaging/:tab`, `operations/:tab`, `settings/:tab`, `support/:id`.
  - L'API est gardée (`EnsurePlatformPermission`). L'écran s'affiche puis échoue en 403.
- **Solution.**
  - Aligner `meta.permission` (ou `anyPermission`) sur la garde API de la page.
  - Pour les pages à onglets, utiliser `anyPermission` sur la page et un filtrage par onglet.
- **Comportement conservé.** Accès inchangé pour les utilisateurs qui ont les droits.
- **Risques.** Un onglet qui devient invisible pour un rôle limité qui le voyait vide.
- **Tests.**
  - E2E avec les rôles Platform limités existants (`roles_only`, `support_only`, `finance_reader`…).
  - un nouveau test d'invariant (il n'existe que `BillingPlatformPermissionTest`, limité au Billing) « page Platform ⇒ meta.permission ».
- **Critère d'acceptation.** Aucune page Platform sans garde ; un invariant automatisé l'empêche à l'avenir.

### P0-3 : `assign_support` ne protège rien ; l'assignation exige `manage_support`

- **Problème.**
  - Un rôle Platform « peut assigner » sans « gérer » ne peut pas assigner.
  - Un rôle « gère » sans « assigner » peut assigner.
- **Fichiers.** `routes/platform.php` (`support/tickets/{id}/assign`).
- **Solution.** `EnsurePlatformPermission:assign_support|manage_support`. C'est additif : personne ne perd l'accès.
- **Tests.** Feature : assignation avec `assign_support` seul (200), sans aucune des deux (403).
- **Critère d'acceptation.** La case cochée a l'effet annoncé.

## P1 : doublons, parcours incohérents, configuration redondante

### P1-1 : une seule liste de personnes

- **Problème.** D1 et D2 : `members/index.vue` (1 224 l.) et `workforce/employees/index.vue` (562 l.) doublonnent `/company/people`.
- **Solution.** Rediriger, comme les détails le font déjà (ADR-768), en conservant les filtres connus dans la requête.
- **Comportement conservé.** Les liens profonds (`?status=`, `?department=`) sont traduits vers `people`.
- **Risques.**
  - Un filtre avancé présent seulement dans l'ancienne liste. **Inventorier les filtres avant de rediriger.**
  - Le store `useJobdomainStore`, importé par `members/index.vue`.
- **Tests.** E2E des listes et des redirections ; `M7` existants.
- **Supprimable après validation.** Les deux fichiers de liste, après un cycle de recette.

### P1-2 : une seule entrée Documents

- **Problème.** D3, D4 et D5 : `workforce/documents`, `workforce/signature` et `workforce/contract/templates` restent routables seules, en plus de leur intégration dans `documents/:tab`.
- **Solution.**
  - Redirections vers `documents/:tab` (onglet correspondant).
  - Les composants restent importés.
  - `_ContractSettingsSection` devient un lien vers Documents → Modèles.
- **Tests.** Les E2E Documents existants (ADR-766, 778) et les liens des notifications (`phpRef = oui` pour `workforce/documents`).
- **Critère d'acceptation.** Un seul chemin par objet ; les notifications ouvrent le bon onglet.

### P1-3 : JobDomain, éditer tous les presets au même endroit

- **Problème.** D6, D8, overlays orphelins, modèles de contrat hors de la fiche.
- **Solution.**
  - **(a)** L'onglet Organisation édite et publie en place, en réutilisant `PlatformOrganizationPresetController`. On retire le refus `abort_if($kind === 'organization')` de `JobdomainPresetWorkspace::draft`, ce qui lui permet de déléguer.
  - **(b)** `/platform/organization-presets` redirige vers l'onglet ; son menu est retiré.
  - **(c)** Catalogue → Presets passe en bibliothèque, en lecture, avec un lien « ouvrir dans le JobDomain ».
  - **(d)** Overlays de marché : **décision du propriétaire** entre réintégration (variante de preset par `market_key`) et dépréciation.
  - **(e)** Modèles de contrat : un lien contextuel depuis l'onglet Champs & documents.
- **Comportement conservé.** Versionnement, `revision`, garde d'archivage, `presets.*`.
- **Risques.** Le preset d'organisation est partagé par plusieurs domaines : afficher l'avertissement « partagé avec » (déjà calculé par `JobdomainPresetWorkspace`).
- **Tests.** `Adr797*`, `Adr798*`, `Adr799*` et E2E `adr799-*`.
- **Supprimable après validation.** `platform/organization-presets/index.vue` et `[id].vue`, l'endpoint `simulate`, `_MarketOverlaySelector.vue` si (d) = dépréciation.

### P1-4 : un seul écran de temps de travail

- **Problème.** D7.
- **Solution.**
  - Paramètres Workforce → onglet Temps de travail monte `_WorkingTimeSetup` (déjà autonome).
  - `_WorkforceRulesSection` passe dans un repli « mode avancé (toutes les règles, provenance) », en lecture ou en édition fine.
- **Comportement conservé.** API `/workforce/config/*`, conservée pour le mode avancé ; garde de valeur légale minimale dans `WorkforceRuleResolver::setRule`.
- **Tests.** `Adr799WorkingTimeFrameworkTest`, `WorkforceNormativeResolutionTest`, E2E `adr799` (B).
- **Critère d'acceptation.** Une seule façon de modifier chaque règle dans le parcours normal ; les statuts « contrôlé / enregistré / non vérifié » sont visibles partout.

### P1-5 : permissions sans effet

- **Problème.**
  - 9 permissions cochables sans effet (MODULE-RBAC-MAP §2).
  - `jobdomain.manage` ne garde que `PUT company/jobdomain`, qui est mort.
- **Solution, au cas par cas, avec décision du propriétaire :**
  - **brancher** : `assign_support` (P0-3) ;
  - **retirer** des manifests : `shipments.delete` (aucune route), `workforce_documents.sign`, `workforce_documents.manage_templates` (doublons conceptuels de `workforce_signature.*` et `workforce_contract.manage_templates`), `workforce.admin`, `workforce.leave_adjust`, `shipments.manage_fields`, `manage_audience`, `documentation.view` — sauf si un besoin existe ;
  - **retirer** la route `PUT company/jobdomain` et l'action `setJobdomain`.
- **Comportement conservé.** Aucune autorisation effective ne change, puisque ces clés ne protègent rien.
- **Risques.**
  - Les pivots `company_role_permission` et `platform_role_permission` contiennent ces clés. Il faut une migration **additive** de nettoyage (supprimer des lignes de pivot devenues inertes), précédée d'un export. À valider : elle touche des données Company.
  - Les presets `rbac` qui référencent ces clés : les valider avec `RbacPresetAdapter::validate`.
- **Tests.**
  - `PermissionCatalog*`.
  - Un nouvel invariant « toute permission déclarée est vérifiée au moins une fois (route, `hasPermission` ou `meta`) ».
- **Critère d'acceptation.** L'invariant est vert.

### P1-6 : rendre accessibles les fonctions déjà construites

- **Problème.** Ces actions sont testées mais n'ont aucun bouton :
  - revue des bulletins : `split`, `regroup`, `period`, `preview`, `pages/exclude`, `revoke` ;
  - campagnes : `launch`, `cancel`, `recipients` ;
  - `rescan` d'un dépôt d'intégration ;
  - `reset-key` d'un signataire ;
  - `profile/disclosures`.
- **Solution.** Exposer chaque action dans l'écran existant correspondant (`_DocumentsPayslipImport`, `_DocumentsCampaigns`, `_OnboardingReviewDialog`, détail signature, Mon espace), avec les composants Vuexy du stock.
- **Décision du propriétaire.** Pour chaque action : exposer, ou déprécier l'endpoint.
- **Tests.** Les tests Feature existent déjà (DmsM5x, ADR-796) ; il faut ajouter un E2E par action exposée.

### P1-7 : assistant commun de configuration des modules

- **Problème.**
  - Un module activé n'est pas un module prêt (MODULE-RBAC-MAP §6).
  - Les droits se règlent rôle par rôle, loin du module.
- **Solution.** Voir TARGET-ARCHITECTURE §3 :
  - `setupSteps` dans le manifest ;
  - étape « Accès » générique par bundle ;
  - réutilisation de `_WorkingTimeSetup`, `_OrganizationPresetCard` et de l'étape congés ;
  - état `company_module_setup`, en table **additive** ;
  - ouverture après `enableModule()`.

  L'assistant Workforce en devient la première instance.
- **Dépendances.** P1-5 (catalogue de permissions propre), P1-4.
- **Risques.** Appliquer des droits sans confirmation explicite : **interdit**, un récapitulatif est obligatoire.
- **Tests.**
  - Feature : l'étape Accès écrit exactement les permissions des bundles choisis, et refuse une permission `is_admin` sur un rôle non administratif.
  - E2E : activer `workforce_planning`, régler, confirmer, réouvrir.
- **Critère d'acceptation.** Activer un module mène à « prêt » en un seul parcours ; la désactivation puis la réactivation retrouvent les réglages.

### P1-8 : une seule porte d'écriture des droits

- **Problème.** `ThemeRoleVisibilityController` attache `theme.view` hors de la matrice des rôles (M7).
- **Solution.** La matrice affiche la capacité « voir le thème » ; le panneau Thème devient un lien vers elle.
- **Tests.** `ThemeRoleVisibility*` existants, adaptés.

## P2 : simplification des services, retrait contrôlé de l'historique

### P2-1 : un seul chemin de création de société

- **Problème.** M1, M2, M3.
- **Solution.**
  - **(1)** Convertir `default_roles` et `workforce_presets` de `logistique` en presets (`legacy-drafts`), puis publier et lier, sur GO du propriétaire.
  - **(2)** `jobdomains:creation-parity logistique` : 0 écart non voulu.
  - **(3)** Activer `JOBDOMAIN_VERSIONED_INIT` sur DEV et faire la recette d'inscription.
  - **(4)** Faire de `VersionedCompanyInitializer` le seul chemin, puis retirer le flag.
  - **(5)** Colonnes historiques en lecture seule, puis dépréciées. **Pas de suppression de colonne sans lot dédié.**
- **Comportement conservé.** Les sociétés existantes ne sont pas touchées.
- **Tests.** `CreationParity`, `Adr798*`, inscription E2E.
- **Supprimable après validation.** Les branches de lecture historique de `JobdomainGate::assignToCompany`, `jobdomains.legacy_editing`, `JOBDOMAIN_VERSIONED_INIT`.

### P2-2 : un seul moteur d'exigences

- **Problème.** M4 et M5.
- **Solution.**
  - Mesurer avec `requirements:compare` sur DEV.
  - Plan de bascule par population : d'abord la société (`CompanyRequirementResolver` déjà isolé), puis les personnes.
  - La vue `legacy` passe en mode avancé.
- **Contrainte.** La bascule générale du resolver a été interdite par les lots précédents : **GO explicite requis**.

### P2-3 : retrait du code mort prouvé

- **Objet.** Les 7 classes PHP, les 18 composants Vue et `DependencyResolver` avec son test (le test devient celui de `DependencyGraph`).
- **Préalable.** Les « morts probables » de §3.2 : relire l'ADR qui les cite.
- **Tests.** Suite complète et build.

### P2-4 : moteur `FeatureFlag`

- **Décision du propriétaire.**
  - (a) Le retirer : table, service, 7 endpoints (6 Platform, 1 Company), onglet.
  - (b) Ou l'adopter pour remplacer les flags d'environnement (`JOBDOMAIN_*`), ce qui donne une stratégie de retrait unique.
- **Recommandation.** (a). Les flags restants ont tous une stratégie de retrait propre.

### P2-5 : endpoints et commandes historiques

- **Endpoints à déprécier** (réponse 410 avec journalisation pendant un cycle, puis retrait) : `world-settings`, `funnel-track`, `metrics-export`, `dashboard/health`, `organization-presets/{id}/simulate`, `overlays`, selon P1-3 (d).
- **Commandes à archiver** après leur bascule : `billing:v2-migration-audit`, `documents:m0-backfill`, `role:backfill-archetypes`, `requirements:compare`, `jobdomains:creation-parity`.

### P2-6 : hygiène des données DEV (sur GO)

- **Objet.**
  - 12 rôles Platform `e2e_*` ;
  - 6 rôles `recette-*` de la société 1, après vérification qu'aucun membre n'y est rattaché ;
  - 2 brouillons de presets.
- **Méthode.** Identifiants exacts, procédure documentée.

### P2-7 : modules coquilles

- **Objet.** `logistics_fleet`, `logistics_tracking`, `logistics_analytics`.
- **Solution.** Les présenter en « bientôt disponible », non activables, dans `/company/modules`. Le statut `preview` existe déjà.
- **Point ouvert.** La désactivation de `logistics_fleet` pour la société 1 est une décision du propriétaire (donnée Company).

## P3 : optimisation et nettoyage cosmétique

- **P3-1.** `dev-lab`, `ui-lab` et `presentation-lab` : `meta.devOnly` et une garde `import.meta.env.DEV`.
- **P3-2.** Redirections historiques : les conserver. Retrait seulement après mesure, et il n'existe aujourd'hui aucune télémétrie de navigation.
- **P3-3.** `EmailInboundController` : accepter le secret uniquement en en-tête.
- **P3-4.** Déplacement de `app/Core/Workforce` : lot technique isolé, à planifier hors de la simplification d'usage.
- **P3-5.** Propriétaire explicite de chaque entrée de menu partagée (une clé, un module).

## Dépendances entre lots

- P0-1, P0-2, P0-3, P1-1, P1-2, P1-8, P2-3 et P2-4 sont **indépendants**.
- P1-3 doit précéder P2-1.
- P1-5 doit précéder P1-7.
- P1-4 doit précéder P1-7.
- P2-1 doit précéder la fin de M2 et M3.
- P2-2 attend un GO spécifique.
