# ADR-803 : assistant commun de configuration des modules (pilote Workforce)

- **Base** : `dev` @ `6e4368f3`. DEV local uniquement, rien poussé. SHA livré : voir §14.
- **Préservés, non commités** : `CLAUDE.md`, `typed-router.d.ts` (régénéré, toujours 98 routes), `DMS-ARCHITECTURE.md`.
- L'identité de recette ADR-801.1 est conservée.
- **Contraintes respectées** :
  - aucune société réelle modifiée, aucun rôle existant modifié automatiquement, aucun preset appliqué ;
  - aucune bascule JobDomain ou Requirements, aucun changement Billing ou paie, `@core` intact ;
  - une seule migration, **additive** (`company_module_setups`, §6).

## 0. Prérequis

### 0.1 E2E historiques affectés par ADR-802

- **Actualisés.** Les 6 specs qui utilisaient l'ancien dialogue de création (`emp-add`, `emp-first-name`, `emp-submit`) passent désormais par le tiroir unique d'Équipe : `hrux4-first-day`, `hrux5-dev-rh`, `workforce-employee-lifecycle`, `workforce-e3a`, `e3r-r4`, `m7-lot4-recette`.
  - Ils s'appuient sur un helper partagé : `createEmployeeViaTeam()` dans `tests/e2e/helpers.js`.
  - `e3r-r2` attendait encore un lien de menu vers `/company/workforce/employees`, remplacé par Équipe depuis ADR-766. Il est actualisé.
- **Exécutés.** Les specs **en lecture seule** liés aux écrans changés : voir §10.
- **Non exécutés, volontairement.** Les specs qui **écrivent** sur la société réelle n°1 : création de salarié, invitations, action groupée de `workforce-wg2-employees`, scripts `e2e:*`. Les rejouer contreviendrait à « aucune modification des Companies réelles ». Ils sont actualisés et vérifiés syntaxiquement (`node --check`).
- La rejoue complète de 16 specs (51 tests) a été interrompue : environ 5 minutes par test. Sur les 10 tests exécutés, un seul échec, celui de `e3r-r2` décrit ci-dessus, aujourd'hui corrigé.

### 0.2 Preset partagé modifié silencieusement : risque confirmé, corrigé

- **Avant.** Publier une nouvelle version d'une famille depuis un JobDomain changeait la version effective de **tous les domaines qui la suivent sans épingle**, sans prévenir. Un test d'ADR-799 vérifiait même ce comportement.
- **Après.** Côté serveur, publier une famille suivie par d'autres domaines (lien sans version épinglée) est **refusé** (`409 shared_preset`, avec la liste des domaines) tant qu'aucun choix explicite n'est fait :
  - `mode=shared` : publier pour tous les domaines qui suivent la famille (audit `presets.shared_publication`) ;
  - `mode=variant` : le brouillon devient une **famille indépendante** propre au domaine, et les autres ne changent pas.
- Les domaines qui ont épinglé une version ne sont jamais concernés.
- **La même règle s'applique au catalogue** (`POST /platform/presets/{kind}/{id}/publish`), qui ne permet donc pas de la contourner.
- **Interface** : l'onglet JobDomain ouvre une confirmation (« Publier pour tous » / « Créer une variante pour ce domaine »).
- Les tests ADR-798 et ADR-799 qui figeaient l'ancien comportement ont été adaptés (choix explicite).

## 1. Composants réutilisés (rien n'est reconstruit)

| Besoin | Existant réutilisé |
|---|---|
| Capacités et permissions | `ModuleManifest::bundles`, la **seule source** (aucune permission dupliquée dans un JobDomain ou un preset) |
| Écriture des droits | `CompanyRole::syncPermissionsSafe` (RBAC existant, avec son interdit « permission d'administration sur rôle opérationnel ») |
| Activation | `ModuleActivationEngine` inchangé ; l'événement `ModuleEnabled` reçoit un écouteur de plus, qui ne fait **que** créer l'état |
| Actif / inactif | `ModuleGate::isActive` |
| Suggestions | `PresetService::recommendationsFor` + preset RBAC (`roles[].bundles`), proposé jamais appliqué |
| Réglages Workforce | `_WorkingTimeSetup.vue` / `WorkingTimeSetupService` (ADR-799) |
| Prérequis Workforce | `companies.convention_confirmed_at`, `WorkforceRuleResolver` |
| Étapes d'interface | `AppStepper` (stock Vuexy) ; registre `moduleSetupSteps.js`, sur le modèle de `settingsPanelRegistry.js` |
| Audit | `AuditLogger::logCompany` (`module.access_configured`, `module.configured`) |
| Catalogue des modules | `ModuleCatalogReadModel` (+ `setup_status`) |

**Code ajouté** :
- backend : `ModuleSetupService`, `ModuleSetupController` (validation seule), `CompanyModuleSetup`, `ModuleReadiness`, `WorkforceSetupReadiness`, `CreateSetupOnModuleEnabled` ;
- frontend : `ModuleCapabilityAccess.vue` (réutilisable), `_ModuleSetupWizard.vue`, `moduleSetupSteps.js`.

## 2. Contrat de capacités d'un module

Il est déclaré dans le manifest, et il est **optionnel** :

```php
bundles: [ ['key' => 'workforce.team', 'label' => …, 'permissions' => [...],
            'is_admin' => bool,             // réservé aux rôles de gestion (déjà existant)
            'sensitive' => bool,            // ADR-803 : présenté à part
            'scope' => 'own'|'team'|…],     // ADR-803 : ses données / son équipe ; défaut « collectif »
         … ],
setup: ['steps' => ['access', 'working_time'], 'readiness' => WorkforceSetupReadiness::class],
```

- **Fonctionnalités métier** = bundles, libellés métier via `permissionCatalog.bundles.*` (déjà traduits).
- **Permissions associées** = celles du bundle, déclarées par le module.
- **Capacités spécifiques et sensibles** : chaque bundle est une capacité distincte (8 pour Workforce), et non un « consulter / gérer » générique.
- **Réglages et étapes** : `setup.steps`, chaque clé correspondant à un composant spécialisé existant.
- **Dépendances** : `requires` du manifest (affiché à la confirmation).
- **Suggestions** : le preset RBAC recommandé, sans duplication.
- **Conditions pour être prêt** : une classe `ModuleReadiness`, vérifiée côté serveur.

## 3. Parcours d'activation

1. `/company/modules` → Activer : moteur d'activation inchangé (devis si payant, dépendances, droits commerciaux).
2. Si le module déclare un contrat, l'état « À configurer » est créé (écouteur) et la société est **redirigée vers l'assistant**. **Aucun droit n'est attribué.**
3. Assistant (page `/company/modules/{key}`, aucune route ajoutée) : **Accès** → **réglages du module** → **Confirmation**.
4. Après confirmation, le module est « Prêt ». Une puce d'état figure sur sa carte dans la liste.

## 4. Interface des accès

`ModuleCapabilityAccess.vue` affiche une ligne par capacité, en trois groupes :
- accès collectifs ;
- accès à **ses propres données ou à son équipe directe** ;
- **droits sensibles**, avec un avertissement.

Chaque ligne présente une puce par rôle :
- la puce est cochée si le rôle détient la capacité entière, avec la mention « (partiel) » s'il n'en détient qu'une partie ;
- une icône signale ce que propose le preset ;
- la puce est désactivée si la capacité est réservée aux rôles de gestion et que le rôle est opérationnel.

Fonctionnement :
- **Seuls les changements explicites** (cellule touchée) sont envoyés. Une capacité détenue partiellement n'est jamais élargie ni réduite sans clic.
- « Appliquer la proposition » coche les suggestions sans rien enregistrer.
- **Aperçu obligatoire** (« +N droits, −M droits » par rôle, avec la raison d'un éventuel blocage), puis « Appliquer ».
- Les **identifiants techniques** n'apparaissent qu'en **mode avancé**.
- Le rôle propriétaire est affiché comme « accès complet, non modifiable ».
- **Ce n'est pas un second écran de rôles** : la vue transverse reste Équipe → Rôles. Le composant y est réutilisable, mais n'y est pas branché dans ce lot.

## 5. Interface des réglages

Les étapes déclarées par le module sont résolues vers des composants **existants**. Pour Workforce : l'étape « Temps de travail », c'est-à-dire **le même formulaire que l'assistant Workforce et que les Paramètres** (ADR-802).

Il n'y a pas d'éditeur générique ni de JSON : l'éditeur JSON historique de la page module reste replié en « Paramètres avancés ».

« Ces réglages me conviennent » permet de valider une étape déjà remplie.

## 6. États de configuration

- **Table additive** `company_module_setups` : `company_id`, `module_key`, `status`, `completed_steps`, `revision`, `configured_at`, `configured_by` ; unique (société, module) ; trait `BelongsToCompany`.
- Les choix d'accès vivent **dans le RBAC**. La table ne suit que l'avancement.

| État | Signification |
|---|---|
| `available` | module inactif, jamais configuré |
| `to_configure` | activé depuis ADR-803, rien n'est encore fait |
| `in_progress` | au moins une étape faite, ou un changement après « prêt » |
| `ready` | confirmé, avec **tous les prérequis vérifiés à la confirmation** |
| `suspended` | désactivé : l'état et les droits sont conservés |
| `legacy` | activé **avant** ADR-803 : il fonctionne comme avant, n'est jamais bloqué et n'est pas présenté comme « prêt » |

Prérequis Workforce (`WorkforceSetupReadiness`) :
- les accès ont été **décidés** (étape appliquée, même pour « conserver l'existant ») ;
- le cadre conventionnel est **confirmé** (ADR-799) ;
- une durée hebdomadaire de référence est **effectivement résolue**.

## 7. Sécurité et permissions

| Garantie | Mécanisme | Test |
|---|---|---|
| Aucun droit à l'activation | l'écouteur ne crée que l'état | `new_activation…without_granting_any_right` |
| Aucun élargissement implicite | changements explicites seulement ; révocation qui ne retire que ce qu'aucune autre capacité détenue n'exige (une capacité *contenue* dans celle retirée est considérée comme impliquée) | `revoke_keeps…`, `capability_with_several_permissions…` |
| Aucun droit sensible par défaut, ni sur un rôle opérationnel | groupe dédié, `is_admin` + `syncPermissionsSafe` → blocage `operational_role` | `sensitive_capability_refused…` |
| Personnel ≠ collectif | `scope` de bundle, affiché séparément | `personal_capability…` |
| Gestion des modules ≠ gestion du RBAC | assistant : `modules.manage` ; **écriture des accès : `roles.manage` en plus** | `module_manager_without_rbac_right…` |
| Pas d'auto-élévation | un acteur non propriétaire ne peut attribuer que des permissions **qu'il détient** (`beyond_your_rights`), contrôle plus strict que l'éditeur de rôles historique | `actor_cannot_grant_rights…` |
| Rôle d'une autre société | refusé (422) | `role_of_another_company…` |
| Contournement serveur | toutes les règles sont serveur ; aperçu recalculé à l'application | HTTP négatifs |
| Aperçu, historique, audit | aperçu obligatoire, audit `module.access_configured` (diff) et `module.configured` | `capability_with_several_permissions…` |
| Concurrence | empreinte (droits du module sur tous les rôles) → `409 stale_preview` ; révision → `409 stale_revision` | `stale_preview…`, `stale_revision…` |
| Idempotence | réappliquer = aucun changement ; double confirmation = un seul audit | `reapplying…`, `full_journey…double_confirmation…` |
| Pas d'achat implicite | l'assistant n'active, n'achète et ne facture rien ; module inactif → confirmation refusée | `setup_endpoints_never_activate_or_buy`, `deactivation…` |

## 8. Compatibilité historique

- **Les modules déjà activés** (dont Workforce sur les sociétés DEV) sont en état `legacy` : ils fonctionnent comme avant, rien ne les bloque, aucune ligne n'est créée par la simple lecture, et l'assistant est disponible pour revoir la configuration.
- **Les modules sans contrat** (24 modules Company sur 25) n'ont pas d'assistant (`setup_status = null`). Leur parcours historique est inchangé.
- **Aucun rôle existant n'a été modifié.** Recette DEV : les compteurs `company_role_permission`, `company_roles`, `company_modules`, raisons d'activation, `company_policies` et `company_module_setups` sont identiques avant et après.

## 9. Module pilote : Workforce

| Étape éprouvée | Test |
|---|---|
| Activation → « À configurer », 0 droit | ✔ |
| Proposition des accès (preset) / aucun preset | ✔ / ✔ |
| Modification par rôle (rôle personnalisé administratif et opérationnel) | ✔ |
| Réglages spécifiques (temps de travail) | ✔ (étape, et prérequis réels) |
| Confirmation → prêt ; prérequis manquants → 422 `not_ready` | ✔ / ✔ |
| Modification ultérieure → `in_progress` puis nouvelle confirmation | ✔ |
| Interruption et reprise (étapes conservées) | ✔ |
| Désactivation → `suspended` ; réactivation → choix et droits conservés | ✔ |
| Multi-société | ✔ |

Salarié sans compte : l'assistant ne touche que les **rôles**. Les fiches salariés, avec ou sans compte, ne sont ni lues ni modifiées.

## 10. Tests

| Série | Résultat |
|---|---|
| `Adr803ModuleSetupTest` (nouveau, HTTP) | **21/21** |
| `Adr803SharedPresetPublicationTest` (nouveau, HTTP) | **5/5** |
| Régressions ciblées : presets, JobDomain, modules, RBAC, catalogue, navigation, ADR-793/797/798/799/801/802 (30 fichiers) | **258/258**, après adaptation explicite d'ADR-798 et ADR-799 au refus de la publication silencieuse |
| E2E historiques ADR-802 en lecture seule (`e3r-r2`, `workforce-e3a`, `e3r-r4`, `adr786-rbac-audit`, `workforce-hr-nav-group`, `ux0-intent-navigation`) | **21/23**. `e3r-r4` est corrigé, puis rejoué : 6/6. **2 échecs antérieurs à ADR-802**, preuve à l'appui : `workforce-e3a` › « fiche refondue » attend `emp-edit`, absent du code dès `d7cb183e` (la fiche salarié redirige vers Équipe depuis ADR-768) ; `workforce-hr-nav-group` attend un groupe de menu « Ressources humaines », alors qu'ADR-802 n'a modifié aucun fichier de navigation Company (`git diff d7cb183e 6e4368f3`). Specs non mis à jour : ils testent des écrans antérieurs, hors périmètre |
| Recette navigateur ADR-803 (lecture seule : seul l'aperçu est autorisé) | **2/2**. Société 1, owner : assistant en « Configuration historique », groupes collectif / propres données / sensibles, **aucun identifiant technique en mode normal**, visibles en mode avancé ; aperçu « +5 droit(s), −0 droit(s) » ; l'application est interceptée, avec 1 changement explicite et une empreinte de 40 caractères ; l'étape Temps de travail affiche le formulaire ADR-799 ; la confirmation affiche les 3 prérequis ; mobile sombre sans défilement horizontal. Manager et chauffeur : page 403, API `setup` 403. **Données inchangées** (paramètres, rôles, pivots, modules, raisons d'activation, états) |
| Suite complète finale (une fois, après tous les correctifs) | **4543 réussis, 12 ignorés, 3 échecs**. Ce sont les 3 tests de `ControllerSizeInvariantTest`. Les contrôleurs cités sont **identiques** à ceux d'ADR-802 ; le nouveau `ModuleSetupController` n'y figure pas. Aucun nouvel échec |
| Build | propre, 98 routes |

## 11. Mesure des clics et des étapes

Actions comptées à partir des écrans, par lecture du code (« avant ») et sur la recette navigateur (« après ») ; ce n'est pas une mesure de télémétrie.

| Parcours | Avant ADR-803 | Après |
|---|---|---|
| Activer Workforce puis donner « gestion d'équipe » à un rôle | Activer (1) → aller dans Équipe → Rôles (2) → ouvrir le rôle (1) → trouver le module dans la matrice et cocher (≥ 1) → enregistrer (1), soit **≥ 6 actions sur 2 pages**, sans guidage ni aperçu | Activer (1) → redirection automatique → cliquer la puce du rôle (1) → Voir les changements (1) → Appliquer (1), soit **4 actions sur 1 page**, avec aperçu |
| Le même choix pour 3 rôles | ≥ 3 × 4 actions = 12 | 3 puces + 2 = **5** |
| Savoir si le module est prêt | aucun indicateur | puce d'état + liste des prérequis |
| Droits techniques visibles | matrice des permissions | aucun (mode avancé seulement) |

## 12. Modules restant à adapter (ordre suggéré)

Le contrat existe maintenant, chaque module s'adapte en déclarant `setup` et une classe de prérequis :

1. `workforce_leave` (types de congés, valideurs) ;
2. `workforce_planning` (modèles, import du preset planning) ;
3. `workforce_signature` (prestataire, niveaux) ;
4. `workforce_contract` (modèles) ;
5. `workforce_documents` ;
6. `workforce_payroll` (paie : hors ce lot) ;
7. `logistics_shipments` ;
8. modules socle (`core.*`) : pas d'assistant nécessaire (pas d'activation) ;
9. modules `preview` (fleet, tracking, analytics) : sans objet.

## 13. Risques résiduels

| # | Risque | Proposition |
|---|---|---|
| R1 | L'éditeur de rôles historique (Équipe → Rôles) **n'a pas** l'interdit d'auto-élévation de l'assistant | Brancher `ModuleCapabilityAccess` dans Équipe → Rôles et appliquer la même règle (lot suivant, sur GO) |
| R2 | Révocation : une capacité *contenue* dans celle retirée est retirée avec elle (règle annoncée dans l'aperçu). Le RBAC stockant des permissions et non des bundles, l'intention « garder la sous-capacité » n'est pas inférable | Assumé ; l'aperçu montre l'effet exact |
| R3 | Un module payant activé par un parcours de paiement côté serveur n'est pas redirigé vers l'assistant : il n'y a pas de navigation | La puce « À configurer » le signale dans la liste |
| R4 | Pas de suggestion en DEV : aucun preset RBAC n'est publié pour `logistique` (ADR-800, M1) | Publier le preset depuis le JobDomain (P2-1) |
| R5 | Les E2E qui écrivent sur la société 1 ne sont pas rejoués | Fixture isolée de recette (lot de maintenance E2E) |
| R6 | La recette navigateur de l'assistant est en lecture seule : l'application réelle est prouvée par les tests HTTP | Idem R5 |

## 14. SHA

Voir l'entrée ADR-803 de `docs/bmad/04-decisions.md` et le commit qui suit.
