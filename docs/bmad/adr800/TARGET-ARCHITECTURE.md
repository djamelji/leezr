# ADR-800 : architecture fonctionnelle cible (simplifiée, sans nouveau moteur)

> **Principe** : moins de surfaces, pas plus de couches. Chaque élément ci-dessous **réutilise un moteur existant**. Aucun moteur n'est ajouté. Rien de ce document n'est implémenté par ADR-800.

## 1. Qui porte quoi

| Niveau | Porte | Ne porte pas | Moteur existant réutilisé |
|---|---|---|---|
| **Module** | Fonctionnalités, capacités (bundles), permissions, paramètres, contributions (champs, documents, exigences), étapes d'assistant | Le choix de la société | `ModuleManifest` (`bundles`, `contributions`, `settingsPanels`), `ModuleActivationEngine` |
| **JobDomain** | Contexte métier, modules recommandés, presets recommandés, conventions **suggérées** | L'organisation du temps de travail ; les droits effectifs | `jobdomains` + `jobdomain_preset_links` |
| **Preset** | Configuration minimale versionnée, proposée, jamais appliquée silencieusement | La décision | `PresetService` et ses adapters |
| **Société** | Ses accès (qui fait quoi), ses réglages, sa convention confirmée | Les règles légales | `CompanyRole`, `CompanyPolicyResolver`, `WorkforceRuleResolver` |
| **Workforce** | Le contrôle opérationnel | La configuration du JobDomain | `ShiftRuleValidator`, `WorkTimeComplianceChecker` |

## 2. Une interface principale par action

| Action métier | Interface unique cible | Ce qui devient alias, lecture ou retrait |
|---|---|---|
| Lister et ouvrir les personnes | `/company/people` | `members`, `workforce/employees` → redirections |
| Gérer les rôles et droits (vue transverse) | Collaborateurs → Rôles | `/company/roles` (déjà alias) ; la porte du thème est fusionnée dans la matrice (capacité « voir le thème ») |
| Régler les droits d'un module | Assistant du module → étape « Accès » | — |
| Documents (registre, signatures, modèles) | `/company/documents/:tab` | `workforce/documents`, `workforce/signature`, `workforce/contract/templates` → redirections |
| Régler le temps de travail | `_WorkingTimeSetup`, dans l'assistant **et** dans Paramètres → Temps de travail | `_WorkforceRulesSection` passe en mode avancé, en lecture |
| Configurer un JobDomain | `/platform/jobdomains/:id`, tous les presets édités en place, organisation comprise | `/platform/organization-presets` → redirection vers l'onglet ; le catalogue Presets passe en bibliothèque, en lecture |
| Exigences documentaires | `/company/workforce/requirements`, une seule vue | La vue `legacy` passe en mode avancé |

## 3. Assistant commun de configuration des modules (conception, non implémentée)

Il réutilise :

1. **Déclaration.** Le manifest du module déclare `setupSteps`, en extension du champ existant `settingsPanels` : une liste ordonnée d'étapes nommées, résolues côté front par `settingsPanelRegistry.js`, qui existe déjà.
2. **Étape « Accès »**, générique et fournie par le socle.
   - Elle lit les `bundles` du module et affiche, pour chacun : libellé métier (`permissionCatalog.bundles.*`), rôles sélectionnés.
   - Pré-remplissage : preset `rbac` recommandé par le JobDomain, sinon rôles administratifs.
   - Écriture : `CompanyRole::syncPermissionsSafe`, inchangé.
3. **Étapes de réglage** : les composants existants (`_WorkingTimeSetup`, `_OrganizationPresetCard`, l'étape congés de l'assistant Workforce…).
4. **État** : le modèle `WorkforceOnboarding` (`completed_steps` / `deferred_steps`) est généralisé en une table `company_module_setup`, sur le même modèle de cycle de vie. C'est **la seule nouveauté de schéma**, et elle est additive.
5. **Déclencheur** : redirection front après `enableModule()` réussi, plus un badge « à configurer » dans `/company/modules` tant que l'état n'est pas `configured`. Il n'y a **pas** de listener serveur, pour ne rien appliquer en arrière-plan.
6. **Confirmation** : récapitulatif puis enregistrement transactionnel, avec l'audit `module.configured`.

L'assistant Workforce actuel en devient la **première instance**. Son étape `roles` passive devient l'étape « Accès » générique.

## 4. Permissions : « qui peut effectuer cette action ? »

- **Vue normale** : par module, la liste des **capacités** (bundles existants, 78) et, pour chacune, les rôles qui la détiennent.
- **Mode avancé** : la matrice actuelle par permission.
- Les permissions de **portée ou population** restent distinctes et sont affichées comme des options de la capacité, par exemple « Gérer les salariés » + « voir les données sensibles » :
  - `members.sensitive_read`, `workforce.sensitive_read` ;
  - `documents.view_confidential` ;
  - `workforce.leave_view_all` ;
  - `workforce.compensation_manage`.
- Les 9 permissions sans effet sont retirées du catalogue (après migration additive de nettoyage des pivots), ou branchées.

## 5. JobDomain et presets : rôle juste

- **Une source par famille** : le preset versionné.
  - `default_roles` et `workforce_presets` deviennent des **graines de migration**, converties en presets (`legacy-drafts` existe déjà), puis en lecture seule.
- **Création d'une société** : un seul chemin.
  - Il se bascule quand chaque famille a un preset publié pour le domaine `logistique`, avec une parité mesurée (`CreationParity` = 0 écart non voulu).
  - Le flag `JOBDOMAIN_VERSIONED_INIT` est **retiré** après la bascule : c'est sa stratégie de retrait.
- **Overlays de marché** : à trancher. Soit ils sont réintégrés comme variante de preset par marché (les presets portent déjà `market_key`), soit ils sont dépréciés.

## 6. Workforce et planning

- Un seul moteur de contrôle, `ShiftRuleValidator`. Un seul écran de règles.
- Ce qui est **enregistré mais non contrôlé** est affiché comme tel partout : rythme, jours fixes, durée cible, jours de repos.
- Les modèles de planning recommandés viennent du preset `planning`, et sont importés par l'assistant via `WorkforcePresetImportService` (réutilisé avec une autre source).

## 7. Hygiène du routage

`vite.config.js` : `VueRouter({ exclude: ['**/_*.vue'] })`. Cela retire 192 routes non voulues, sans changer aucun import, car les composants `_*.vue` sont importés par chemin relatif.
