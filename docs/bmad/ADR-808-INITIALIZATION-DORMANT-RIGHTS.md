# ADR-808 — Initialisation Company par JobDomain et presets ; sécurisation des droits dormants

- **Date** : 2026-11-16, DEV uniquement, base `251c144a`.
- **Garanties du lot** :
  - aucun troisième mécanisme ;
  - aucune Company réelle modifiée ;
  - aucun pivot RBAC changé ni nettoyé ;
  - aucune bascule globale ;
  - aucun changement Billing, paie ou Requirements.

## 1. Cartographie ciblée (comportements réels)

| Élément | Chemin historique (`JobdomainGate::assignToCompany`) | Chemin versionné (`VersionedCompanyInitializer::initializeNew`) |
|---|---|---|
| Déclenchement | Inscription (`RegisterCompanyUseCase`) si le drapeau est coupé ; aussi `CompanyJobdomainController` (route toujours en 422) et la commande de charge | Inscription si `JOBDOMAIN_VERSIONED_INIT` (avant ADR-808 : drapeau seul) |
| Modules | `default_modules` du métier (lus en base) + overlays de marché, dépendances transitives ; **raisons écrites directement**, sans `ModuleEnabled` (donc sans facturation ni ligne d'assistant) | Modules du métier **classés** : seuls ceux « cœur » ou « inclus » sont activés, plus leurs dépendances. Les modules payants ne sont jamais activés. Raisons écrites directement |
| Rôles | `jobdomains.default_roles` : **tous** les rôles créés, permissions = bundles + permissions directes, **quels que soient les modules actifs** | Preset `rbac` publié : rôles non sensibles automatiques ; administratifs ou sensibles **seulement si confirmés** (à l'inscription : `owner` seul) ; jamais sur une société existante |
| Propriétaire | Le membre owner est rattaché au rôle `owner` | Idem (`finishRoles`) |
| Champs et documents | Activations à partir des presets du métier, prescriptions par rôle (`field_config`, `doc_config`) | Preset `requirements` : registre + **même projection opérationnelle** (activations) ; pas de prescription par rôle (ADR-797) |
| Organisation, planning | Aucun | Décision explicite (sautée à l'inscription) |
| Traçabilité | Aucune application de preset tracée | `preset_applications` par famille + audit `company.versioned_initialized` |

**Autres points du parcours**
- `ModuleActivationEngine::enable` : raisons, cascade des prérequis, puis `ModuleEnabled` pour chaque module activé (facturation, assistant, champs).
- `ModuleSetupService`, `CapabilityAccessService` et `MembershipSecurityPolicy` : inchangés dans leur rôle. Contrôle d'accès effectif : `User::hasCompanyPermission` → `CompanyRole::hasPermission`.

**Écarts mesurés** par `CreationParity` (existant, transaction toujours annulée), sur `logistique`, FR, Pro, avec les presets préparés :

| | Inscription (owner confirmé seul) | Tous les rôles confirmés |
|---|---|---|
| Défauts (`bug`) | **0** | **0** |
| Modules, champs, documents activés | identiques | identiques |
| Rôles | `manager`, `dispatcher`, `ops_manager` non créés (sensibles, non confirmés) ; leurs tableaux de bord aussi | identiques |
| Permissions | `documentation.view`, `jobdomain.manage`, `shipments.delete`, `shipments.manage_fields` absentes (capacités sans effet retirées du preset, ADR-807) | idem |
| Volontaires | prescriptions champs/documents par rôle non reprises ; registre des exigences alimenté | idem |

## 2. Presets Logistique préparés (DEV, plateforme)

| Famille | Version | Contenu | Décision à la création |
|---|---|---|---|
| `logistique_rbac` | v1 publiée puis archivée ; **v2 publiée** | Les 5 rôles historiques, uniquement des bundles existants, **sans les 4 capacités sans effet**. Noms français : Propriétaire, Chauffeur-livreur, Responsable, Répartiteur, Responsable d'exploitation | `owner` confirmé à l'inscription ; Chauffeur-livreur automatique **mais sans accès module avant confirmation** ; les rôles de gestion restent une décision ultérieure |
| `logistique_requirements` | v1 publiée | 41 éléments, **copie exacte** de l'historique (niveaux d'origine, aucune obligation ajoutée) | Éléments sans poste : automatiques (même projection que l'historique) |
| `logistique_base_v1` (organisation) | v1, déjà publiée | Direction, Exploitation, Responsable d'exploitation | Décision explicite (non appliquée à l'inscription) |
| `logistique_planning` | v1 publiée | Modèles hérités (semaine, activités) | Décision explicite, utile seulement avec le module Planning |
| Modules | — | `default_modules` du métier, classés (cœur, inclus, payant optionnel) | Activation des modules sûrs uniquement |

- Aucune permission inventée : la validation de l'adaptateur refuse un bundle inconnu.
- **Aucun lien de recommandation n'est créé pour `logistique`.** C'est le premier geste de la bascule (§5) : sans lien, aucune société réelle ne voit de suggestion dans ses assistants.

## 3. Droits dormants : protection

**Constat.**
- Un rôle pouvait détenir des permissions d'un module non actif : capacité composée (corrigée par ADR-807 pour l'avenir), ancien éditeur de rôles, rôles par défaut, preset.
- À l'activation du module, ces droits devenaient effectifs sans décision.

**Correction minimale (aucun moteur ajouté).**
- **Où c'est mémorisé** : `company_module_setups.pending_access` (colonne JSON **additive**, NULL pour toutes les lignes existantes), sous la forme `{rôle: [permissions du module]}`.
- **Quand un droit est mis en attente** :
  - **première activation** d'un module doté d'un assistant, y compris comme **dépendance**. `ModuleActivationEngine` repère les modules jamais actifs pour la société (aucune ligne `company_modules`) et transmet `firstActivation` à `ModuleEnabled`. `CreateSetupOnModuleEnabled` mémorise alors ce que les rôles **non propriétaires** détiennent déjà sur ce module ;
  - **création versionnée** : rôles opérationnels préconfigurés, pour chaque module activé qui a un assistant.
- **Effet** : `CompanyRole::hasPermission` n'honore pas un droit en attente. Toutes les routes passent par là, ainsi que les notifications.
  - `effectivePermissionKeys()` retire ces droits de la liste envoyée au client (`/my-companies`, accueil, tableaux de bord, fiche personne) : menus, écrans et API restent cohérents.
  - Le pivot reste intact.
- **Levée de l'attente** :
  - confirmation de l'étape Accès dans l'assistant (« Conserver les accès » ou modification), audit `module.access_configured` avec les rôles confirmés ;
  - ou décision explicite pour ce rôle dans Équipe → Rôles (vue par capacité) ;
  - l'éditeur avancé ne lève **pas** l'attente, car il renvoie toujours la liste complète des permissions, même pour un simple renommage.
- **Ce qui ne déclenche jamais d'attente** :
  - une réactivation (la ligne `company_modules` existe, les droits avaient été effectifs) ;
  - un module actif historiquement ;
  - les modules cœur ;
  - un module sans assistant (`workforce_payroll`, voir les risques) ;
  - le rôle `owner` et le membre propriétaire (contournement existant).
- **Interface** : bandeau « Ces rôles ont déjà des droits de ce module, préparés à la création de l'entreprise ou attribués avant son activation : … », et mention « (en attente) » sur la pastille du rôle. L'état « Prêt » reste impossible tant que l'accès n'est pas confirmé.

**Compatibilité.**
- Le comportement historique ne change que pour les **premières** activations faites après le déploiement.
- Le seul effet visible : un rôle qui avait des droits dormants n'y accède plus à l'activation, jusqu'à la confirmation (contrôle C8 : 0 sur DEV).

## 4. Parcours réel de création

1. La société choisit son métier.
2. Leezr initialise ce qui est sûr : modules cœur et inclus, rôle propriétaire, champs et documents du métier, rôles opérationnels **sans accès module**.
3. Les accès et les réglages se décident dans l'assistant de chaque module (pastille « À configurer »).

La société ne voit ni identifiant de preset, ni adaptateur, ni version, ni permission interne, ni diagnostic : vérifié en E2E sur le texte de l'assistant. Aucune nouvelle page n'a été ajoutée.

## 5. Bascule contrôlée et retour arrière

- **Conditions** (`VersionedCompanyInitializer::shouldUse`, seul point lu par l'inscription). Le chemin versionné s'applique seulement si **toutes** sont réunies :
  1. `JOBDOMAIN_VERSIONED_INIT=true` ;
  2. le métier figure dans `JOBDOMAIN_VERSIONED_INIT_JOBDOMAINS` (liste vide = aucun, même drapeau actif) ;
  3. le métier est lié à un preset `rbac` **publié** contenant `owner`.

  Sinon, c'est le chemin historique : un preset absent, en brouillon ou incomplet ne produit jamais une société sans administration.
- **Procédure pour `logistique`** (nouvelles sociétés uniquement) :
  1. lier les familles : `PUT /api/platform/jobdomains/logistique/preset-recommendations` (rbac, requirements ; organisation et planning optionnels) ;
  2. contrôle C10 ;
  3. `JOBDOMAIN_VERSIONED_INIT_JOBDOMAINS=logistique` et `JOBDOMAIN_VERSIONED_INIT=true` ;
  4. créer une société de recette et vérifier l'accès en attente, puis la confirmation.
- **Retour arrière** : retirer le métier de la liste (ou couper le drapeau). Les créations suivantes reprennent le chemin historique. Les sociétés créées entre-temps restent des sociétés ordinaires, avec leurs `preset_applications` pour la traçabilité.
- **Sociétés existantes** : aucune réapplication. `initializeNew` refuse toute société qui a déjà des rôles ou des modules.

## 6. Tests

- `Adr808InitializationDormantRightsTest`, 6 tests. Ils couvrent :
  - **bascule** : drapeau seul, preset absent, brouillon impossible à recommander, preset sans `owner`, preset partagé entre deux métiers ;
  - **création versionnée** :
    - propriétaire immédiatement administrateur ;
    - rôle sensible non créé ;
    - chauffeur 403 avant confirmation, 200 après, pivots identiques ;
    - jamais deux fois ;
  - **création historique** inchangée, sans doublon de rôle ;
  - **droits dormants** :
    - première activation directe (Congés), puis indirecte (Documents RH via Signatures) ;
    - état non « Prêt » et assistant cohérents ;
    - confirmation par module ;
    - décision explicite dans Équipe → Rôles ;
  - **réactivation et module historiquement actif** : aucune attente, droits conservés ;
  - **multi-sociétés** : même personne, en attente dans A, effective dans B ; société existante non réinitialisable.
- `Adr798…` adapté : le drapeau seul conduit désormais au chemin historique, la liste de métiers est requise.
- Régression RBAC, assistants, modules, presets, métiers, Expéditions, Congés et sécurité Workforce : **1043 verts**.
- **E2E** (sociétés fictives V et L, supprimées ensuite) : **2/2**, captures dans `docs/bmad/adr808/`.
  - **V, créée par le chemin versionné** :
    - le chauffeur reçoit 403 et l'écran lui est refusé ;
    - l'assistant Expéditions est « À configurer », avec le bandeau et la mention « en attente » ;
    - aucun terme technique n'apparaît ;
    - après « Conserver les accès », le chauffeur obtient 200, sans aucune modification des pivots.
  - **L, créée par le chemin historique** :
    - activation de Workforce (0 €, aucun abonnement) ;
    - le Répartiteur, qui avait des droits Workforce dormants, reçoit 403, puis 200 après confirmation ;
    - pivots identiques.
  - Société 1 inchangée. Lien de recommandation `logistique` remis à 0 et vérifié.
- Suite complète et build : voir `04-decisions.md`.

## 7. Impacts PROD à vérifier (sans accès PROD)

- **C9** : migration additive `pending_access` jouée ; lignes en attente.
- **C10** : liens et presets publiés avec `owner` avant de lister un métier.
- **C8 (ADR-807)** : droits dormants existants. Après déploiement, ils seront mis en attente à la première activation de leur module.
- Inscription : sans les variables d'environnement, le comportement est strictement historique.

## 8. Risques résiduels et dettes

- **Modules sans assistant** (`workforce_payroll`) : leurs droits dormants restent effectifs à l'activation (pas de point de confirmation). À traiter avec l'assistant Paie, hors périmètre (paie gelée).
- **Éditeur de rôles avancé** : il montre les permissions en attente comme cochées, sans mention. La confirmation se fait dans l'assistant ou la vue par capacité.
- **Société créée par le chemin historique** : ses rôles opérationnels gardent des accès immédiats, comme avant. Seule la bascule les met en attente.
- **Dette Billing distincte (non traitée ici)** : `GET /modules/quote` crée le portefeuille de la société s'il n'existe pas (`WalletLedger::ensureWallet`). C'est une écriture lors d'une lecture, vide et sans effet financier. À corriger dans un lot Billing dédié.
- **ControllerSizeInvariantTest** : 3 échecs historiques, conservés comme dette.
