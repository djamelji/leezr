# ADR-807 — Rationalisation des permissions et cohérence modulaire

- **Date** : 2026-11-16, DEV uniquement, base `deea2a00`.
- **Garanties du lot** :
  - aucun rôle ni pivot modifié automatiquement ;
  - aucune migration ;
  - aucun nouveau moteur ;
  - aucun changement Billing, paie ou Requirements ;
  - aucune Company réelle touchée.

## 1. Expéditions : périmètres serveur explicites

| Parcours | Avant (ADR-806) | Après |
|---|---|---|
| `PUT /shipments/{id}/status` (**collectif**) | `view` + `manage_status` | inchangé : toute expédition de la société |
| `POST /shipments/{id}/assign` (**collectif**) | `assign` seul : un rôle `assign` sans vue pouvait **s'affecter n'importe quelle expédition** puis la modifier via « mes livraisons » | `view` + `assign` |
| `PUT /my-deliveries/{id}/status` (**personnel**) | `manage_status` seul, puis vérification d'affectation dans le contrôleur, puis relecture **sans** filtre (fenêtre check-then-act) | `view_own` + `manage_status` **et** règle « affectée à moi » vérifiée par `ChangeShipmentStatus` sur la ligne **verrouillée** (`lockForUpdate`, même transaction que l'écriture) |

- **Règle** : la permission ouvre une **portée**, jamais une ressource.
  - Collectif = `shipments.view` + l'opération.
  - Personnel = `shipments.view_own` + l'opération + l'appartenance.
  - Un rôle qui cumule toutes les permissions sauf `view` reste cantonné à ses livraisons : il ne peut ni s'affecter une expédition, ni changer un autre statut.
- Mesure DEV : aucun rôle existant ne perd un droit (contrôle C6 vide). La requête C6 mesure les rôles PROD concernés.

## 2. Permissions inertes : preuves, attributions, classement

Consommateurs recherchés :
- **directs** : routes, `hasCompanyPermission`, `can()` côté client, éléments de navigation ;
- **indirects** :
  - vérifications construites dynamiquement (aucune ne forme ces clés) ;
  - rôles par défaut des métiers (`jobdomains.default_roles`, registre) ;
  - presets RBAC versionnés (`presets.content`) ;
  - sujets de notification ;
  - tests.

| Permission | Consommateurs réels | Attributions DEV | Références indirectes | Classement |
|---|---|---|---|---|
| `workforce.leave_adjust` | aucun (registre `GrantLeave`/`Storno` sans route) | 0 | bundle `workforce.leave_adjustment` (`unwired`) | **Conserver inactive** (consigne) |
| `workforce_documents.manage_templates` | aucun ; les modèles sont gardés par `workforce_contract.manage_templates` | 1 rôle (recette, société 1) | bundle `workforce_documents.management` | **Déprécier**, retrait après migration |
| `workforce_documents.sign` | aucun ; signatures gardées par `workforce_signature.*` | 1 rôle (idem) | idem | **Déprécier**, retrait après migration |
| `workforce.admin` | aucun en code ; 2 tests l'utilisent comme permission décorative | 1 rôle (idem) | bundle `workforce.hr_management` | **Déprécier**, retrait après migration |
| `documentation.view` | aucun : centre d'aide ouvert à tous | 7 rôles (owners, 7 sociétés) | bundle `documentation.access` dans les rôles par défaut `logistique` **et** dans 1 preset RBAC publié | **Déprécier** ; retrait = migration pivots + nouvelle version du preset |
| `jobdomain.manage` | garde `PUT company/jobdomain`, qui répond **toujours 422** | 14 rôles (owner + manager) | bundle `jobdomain.management` dans les rôles par défaut et le preset | **Déprécier** ; retrait avec la route (dépendance effective) |
| `shipments.manage_fields` | aucun | 21 rôles (owner, manager, ops_manager) | bundle `shipments.administration` dans les rôles par défaut et le preset | **Déprécier** |
| `shipments.delete` | aucun (pas de route de suppression) | 21 rôles | idem | **Déprécier** |
| `manage_audience` (Platform) | aucun (aucune route Platform d'audience) | 1 rôle Platform (`admin`) | bundle `audience.management` | **Déprécier** (lot Platform) |

**Conclusion.** Aucune permission ne remplit la condition du GO : « aucune attribution historique ni dépendance effective ».
- `leave_adjust`, sans attribution, doit rester inactive.
- Toutes les autres sont attribuées, et trois sont référencées par les rôles par défaut et un preset publié.
- **Rien n'a été retiré.**

Ce qui a été fait, sans toucher aux droits :
- Chaque permission inerte porte `inert: true` dans son manifeste. `CapabilityAccessService::permissionIndex()` est la source unique.
- Le mode avancé affiche « dont N sans effet aujourd'hui : … » sur chaque capacité concernée. Les capacités entièrement inertes restent `unwired`, donc masquées en vue normale (ADR-806).
- Les descriptions normales ne promettent plus ces fonctions (ADR-806).

**Plan de migration proposé (à valider, non exécuté)** :
1. **Mesure PROD** : contrôle C7, attributions par permission et par société.
2. **Contrats RBAC** :
   - publier une nouvelle version du preset `rbac` et des rôles par défaut `logistique`, sans `documentation.access`, `jobdomain.management` ni `shipments.administration` ;
   - les anciennes versions restent lisibles (ADR-793 : versions immuables) ;
   - conserver les clés de bundles comme alias vides pendant une version, pour que `resolveBundles` des anciens contrats reste valide.
3. **Code** :
   - retirer `PUT company/jobdomain` et sa garde (ADR-800 §2) ;
   - retirer `workforce.admin` des deux tests ;
   - retirer les 3 permissions mortes des bundles `workforce_documents.management` et `workforce.hr_management`.

   Le retrait du bundle ne change aucun droit effectif. La complétude affichée devient même plus juste : un rôle qui n'a que les permissions réelles apparaît « complet ».
4. **Données**, par une migration **additive puis de nettoyage**, en deux déploiements :
   1. journaliser, pour chaque pivot `(rôle, permission)` concerné, une entrée d'audit `role.permission_deprecated` ;
   2. après validation, supprimer ces pivots, puis les lignes `company_permissions` / `platform_permissions`.

   La synchronisation du catalogue ne supprime jamais une permission (`CompanyPermissionCatalog::sync` = `updateOrCreate`) : l'étape 4.2 est donc **toujours** explicite.
5. **Retour arrière** : réinsérer les pivots à partir du journal d'audit.

## 3. Capacités composées : plus d'attribution implicite

- **Avant.**
  - Attribuer « Responsable d'équipe » (`workforce.manager`) ajoutait aussi les permissions Congés et Planning, même si ces modules n'étaient pas activés.
  - Activer Congés plus tard rendait donc effectifs des droits que personne n'avait décidés pour ce module.
- **Après.** `CapabilityAccessService::capabilities()` sépare deux familles de permissions :
  - celles **du module de la capacité ou d'un module actif** : seules à être attribuées, retirées ou comptées dans la complétude ;
  - les **dormantes** (module tiers non activé) : jamais attribuées ni retirées. L'écran l'indique : « Comprend aussi des droits de modules non activés (Planning, Absences et congés) : ils ne sont pas attribués ici et se décident dans l'assistant de chaque module après son activation ».
- **Rôles existants préservés.**
  - Les droits dormants déjà présents ne sont pas retirés silencieusement : un retrait de capacité ne touche que la partie active (test).
  - Contrôle C8 : rôles qui détiennent des droits d'un module non actif (0 sur DEV).
- Même composant pour l'assistant et Équipe → Rôles ; aucun nouvel éditeur. Le mode avancé reste disponible.

## 4. Dépendances payantes : parcours de facturation

| | Avant | Après |
|---|---|---|
| Devis demandé | Seulement si le module **demandé** était payant | Aussi si l'un de ses **prérequis inactifs** est payant (un module gratuit ne peut plus déclencher une facturation sans devis) |
| Contenu | Lignes du seul module demandé ; prérequis listés « Également activés **(sans frais)** » | Le moteur existant (`/modules/quote`) est appelé pour le module demandé **et** ses prérequis inactifs, exactement ce que `ModuleActivationEngine::enable` active et ce que `AddonBillingListener` facture. Sections affichées : **module demandé**, **modules nécessaires activés en même temps** (prix ou « Sans frais »), **déjà actifs (aucun changement)**, total HT/TTC |
| Exemple (offre Pro, Documents RH inactif, Signatures à 0 €) | « Signature 0 € — Total 0 € — Également activés (sans frais) : Documents RH ». Puis 9 € HT/mois réellement facturés | « Signature électronique : 0,00 € — Documents RH : 9,00 € — Total TTC 10,80 €/mois » ; « Déjà actifs : Ressources humaines » |
| Désactivation | Non mentionnée | Règle réelle du moteur : désactiver le module retire aussi les modules ajoutés pour lui s'ils ne servent à aucun autre ; les données sont conservées. La facturation suit la politique : fin de période, ou arrêt immédiat avec crédit au prorata |
| « Facturé immédiatement » | Affiché même à 0 € | Seulement si le total est supérieur à 0 |

- **Aucun changement** de prix, d'abonnement, de calculateur ni de listener.
- Preuve PHPUnit : le devis élargi (900) correspond aux abonnements réellement créés par l'activation (900, Documents RH seul). La désactivation retire bien Documents RH ajouté pour Signatures, et conserve Workforce, actif directement.
- **Constat Billing (non modifié)** : `GET /modules/quote` crée le portefeuille de la société s'il n'existe pas (`WalletLedger::ensureWallet`). C'est une écriture lors d'une lecture. Elle est vide et sans effet financier, mais à corriger dans un lot Billing.

## 5. Modules inclus dans le métier

- **Inclus ne veut pas dire droits attribués.** La création active le module (raison `direct`) sans aucun droit Expéditions supplémentaire. Les rôles par défaut historiques sont ceux du métier.
- **Assistant accessible** : pastille « Configuration historique », puis l'assistant (ADR-806).
- **Réglages** : le bouton « Configurer » reste présent quand le module a des panneaux.
- **Dépendances** : droit d'accès `jobdomain_dependency` respecté (inchangé).
- **Pas de bouton « Désactiver »** (choix conservé). La carte l'explique désormais : « Inclus dans votre activité : ce module fait partie de votre métier et ne se désactive pas ici. Ses accès et réglages restent personnalisables. »

## 6. Décisions à prendre avant déploiement (aucun accès PROD)

Requêtes : `docs/bmad/adr806/prod-readonly-checks.sql` (C1 à C8, lecture seule, validées sur DEV).

| Sujet | Contrôle | Décision à prendre | Recommandation |
|---|---|---|---|
| Restriction des documents RH (ADR-805) | C1 | Accepter la restriction, ou compléter les rôles listés avec « Gestion des collaborateurs » | Rôle par rôle, par l'entreprise ; jamais automatiquement |
| Congés sans module | C2 | Activer Congés (19 €) là où des données existent, ou laisser les données inaccessibles | Décision commerciale avec la société ; activation sans facture rétroactive si accord |
| Signatures sous prestataire de test | C3 | Annuler explicitement les demandes `internal` ouvertes, ou configurer un prestataire réel avant déploiement | Prestataire réel obligatoire avant d'ouvrir Signatures en PROD ; sinon garder le module non vendu |
| Dépendances payantes incohérentes | C4 | Société avec un module sans son prérequis (DEV : société 21, Paie sans Congés) : activer le prérequis (facturé) ou désactiver le module dépendant | Contacter la société ; aucun changement silencieux |
| Sessions Platform | C5 | Jouer la migration additive `2026_12_02_000001` si absente | Obligatoire avant le code ADR-801.1 |
| Expéditions | C6 | Rôles `assign` sans vue, ou `manage_status` sans portée | Ajouter « Opérations d'expédition » (collectif) ou « Livraisons » (personnel) selon le métier du rôle |
| Permissions inertes | C7 | Valider le plan du §2 | Lot dédié en deux déploiements |
| Droits dormants | C8 | Les retirer, ou les confirmer à l'activation du module | Revue dans l'assistant du module à son activation |

## 7. Tests

- `Adr807PermissionRationalizationTest`, 8 tests :
  - **Expéditions** :
    - chauffeur aux permissions cumulées (403 sur la route collective et sur l'affectation, 404 hors affectation, état inchangé ; 200 et état sur sa livraison ; 201 en création) ;
    - `manage_status` seul ou `view_own` seul (403) ;
    - réaffectation : l'ancien chauffeur reçoit 404 et le nouveau 200 ; le responsable garde la main collective ; workflow terminal en 422 ;
    - isolation multi-société : même personne membre de deux sociétés.
  - **Permissions inertes** : conservées, marquées ; contrats RBAC historiques (bundles des rôles par défaut) toujours résolus.
  - **Capacité composée** : aucun droit dormant attribué, rien à l'activation ultérieure, droits historiques dormants préservés au retrait.
  - **Devis** : devis élargi = facturation réelle ; règle de désactivation.
  - **Contrat UI et traductions** FR/EN/AR.
- `Adr8031UnifiedRbacTest` adapté. Son ancienne assertion disait que `workforce.manager` attribuait la publication du Planning sans le module Planning actif : c'est désormais refusé, volontairement.
- Régression RBAC, assistants et expéditions : 364 verts.
- **E2E** (société fictive `logistique`, Workforce activé par le vrai moteur à 0 €) : **3/3**, captures dans `docs/bmad/adr807/`.
  - **Devis Signatures** : Documents RH à 9 €, total 10,80 € TTC, « déjà actifs », règle de désactivation. Annulé : raisons, abonnements et factures identiques.
  - **Module inclus** : explication visible, aucun bouton « Désactiver », assistant accessible.
  - **Équipe → Rôles** : mention des droits dormants ; en mode avancé, « dont 1 sans effet : workforce.admin ».
  - **Chauffeur aux permissions cumulées** : 403 / 403 / 404 sur l'expédition d'autrui, 200 sur la sienne ; après réaffectation, 404.
  - Société 1 inchangée. Fixture supprimée par identifiants exacts : 0 facture, 0 abonnement ; le portefeuille créé par le devis a aussi été supprimé.
- Suite complète et build : voir `04-decisions.md`.

## 8. Risques résiduels

- **Rôles PROD qui perdent un droit Expéditions** (C6) : jusqu'ici, ils pouvaient agir hors de leur périmètre. À mesurer avant de déployer.
- **Droits dormants historiques** (C8) : ils deviennent effectifs si le module est activé. L'assistant du module montre leurs détenteurs, mais rien ne les retire.
- **Devis côté client.** La fermeture des prérequis est calculée à partir des `requires` du catalogue, qui sont aussi la source du moteur. Si un prérequis n'est pas commercialisable, le devis répond 422 et l'activation est refusée : comportement cohérent.
- **Écriture lors du devis** (`ensureWallet`) : lot Billing.
- **ControllerSizeInvariantTest** : 3 échecs historiques, conservés comme dette.

- **SHA DEV** : `c90ddc7d`.
