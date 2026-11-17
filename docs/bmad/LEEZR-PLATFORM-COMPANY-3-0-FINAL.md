# Leezr — Refonte Platform & Company 3.0 (ADR-820)

> Branche `dev` locale, **aucun push**, aucun déploiement. Recette écrivante exclusivement sur le clone isolé
> `leezr_audit820` (sociétés fictives créées pour la recette). Preuves : `docs/bmad/company-3-0/`.

## Statut global — livraison PARTIELLE

Le cœur structurel est livré et recetté :
- les presets publiés gouvernent les nouvelles sociétés ;
- les catalogues ne créent plus d'obligation ;
- les modules déclarent leurs besoins de données, mappés sur les champs de la société ;
- les onglets passent dans le chemin.

En revanche, **plusieurs pages demandées n'ont pas été refondues visuellement** (détail en §15). La mission n'est donc **pas** déclarée intégralement terminée.

Légende des statuts :
- **Implémenté et recetté** : exécuté dans le navigateur sur le clone.
- **Implémenté, non recetté** : tests automatisés seulement.
- **Existant et vérifié** : non modifié, contrôlé.
- **Non fait** : hors de ce qui a été livré.

---

## 1. Commits locaux (sur `0f53d91b`)

| Commit | Objet |
|---|---|
| `8909d6db` | Lot 1 : presets versionnés effectifs, bibliothèque de départ sans obligation, autorité « poste », profil d'expérience |
| `f1539ef6` | Lot 1 : fiche JobDomain en cinq sections routées, éditeur de bibliothèque |
| `52fecc7f` | Lot 2 : besoins de données des modules et assistant « Données » |
| `bc33b0fe` | Lot 3 : plus d'obligation implicite, un écran propriétaire par règle, hub Réglages en Vuexy |
| `45622040` | Lot 5 : onglets dans le chemin, anciens `?tab=` redirigés, test garde-fou |
| `9684c100` | Corrections trouvées par la recette navigateur |
| `91629bee` | Specs E2E du dépôt alignées sur les nouvelles routes |
| (ce commit) | ADR-820, rapport, preuves |

## 2. Modifications architecturales

- **Famille de presets `catalog`** (« bibliothèque de départ »), avec `CatalogPresetAdapter` :
  - met à disposition des champs et des types de documents ;
  - « actif à la création » ou seulement « proposé » ;
  - toute clé d'obligation (`required`, `level`) est refusée à la validation.
- **`VersionedCompanyInitializer`** devient le chemin de création :
  - drapeau `jobdomains.versioned_init.enabled` à `true` par défaut, liste `*` ;
  - repli sur `JobdomainGate::assignToCompany` si le domaine n'a pas de bibliothèque publiée ni de rôles publiés comprenant le propriétaire ;
  - retour arrière : `JOBDOMAIN_VERSIONED_INIT=false`.
- **Autorité « poste »** à la création d'une société par ce chemin : le rôle d'accès ne décide plus des exigences.
- **Plus d'obligations implicites pour une société au modèle cible** (`MandatoryContext`, mode explicite) :
  - ni `required_by_jobdomains` ni les étiquettes du rôle ne rendent un champ obligatoire ;
  - `JobdomainGate` ne projette plus le `required` historique sur les nouvelles sociétés.
- **Profil d'expérience du JobDomain** (`jobdomains.experience`, `recommended_modules`) :
  - lu par `NavBuilder`, qui ordonne les groupes et les points d'entrée sans ajouter ni retirer d'entrée ;
  - lu par le catalogue des modules Company (badge « Recommandé »).
- **Contrat `dataNeeds` des modules** : `ModuleDataNeeds` (Core, générique) et la table `company_module_field_mappings` (correspondance par identifiant stable du champ).
- **Routing** : paramètres d'onglet optionnels (`[[tab]].vue`).
  - Les noms de route historiques sont conservés ; les identifiants numériques sont contraints (`:id(\d+)`).
  - Le test garde-fou `Adr820TabRoutingConventionTest` empêche le retour des écritures `?tab=`.

## 3. Avant / après

| Sujet | Avant | Après |
|---|---|---|
| Création d'une société | Registre PHP en dur ; presets Platform édités mais jamais appliqués (drapeau éteint) | Presets **publiés** appliqués et tracés (`preset_applications`) ; registre en repli seulement |
| Documents du métier | `required: true` posé sur l'activation : pièce d'identité, Kbis et assurance exigés de tous | Bibliothèque : disponible ≠ demandé ; `required_override = 0` |
| Qui décide des exigences | Rôle d'accès (`doc_config`, étiquettes), catalogue, activation, poste | Nouvelles sociétés : règles explicites de la société et du poste uniquement |
| Champs « exigés par le secteur » | Date d'embauche et statut imposés à toute société Logistique | Plus rien au modèle cible ; inchangé pour les sociétés historiques |
| Adresse | `address` (une ligne) et adresse structurée, en doublon | Adresse structurée dans les bibliothèques ; `address` masquée du catalogue sauf si déjà utilisée |
| Onglets | 9 pages pilotées par `?tab=` | Chemins ; anciens liens réécrits |

## 4. Configuration JobDomain (Platform) — implémenté et recetté

`/platform/jobdomains/{id}/{general|modules|experience|presets|publication}` :
- **Général** : identité et conventions par marché.
- **Modules** :
  - inclus au démarrage, recommandés ;
  - compatibilité lue sur le module, qui reste son écran propriétaire.
- **Expérience** : groupes en tête, points d'entrée (6 au plus), vocabulaire, aperçu du menu.
- **Presets** :
  - une carte par famille (bibliothèque, rôles, organisation, exigences suggérées, valeurs initiales du planning) ;
  - un résumé au lieu de listes de 40 lignes.
- **Publication** : actif, ouvert à l'inscription, chemin de création effectif par marché, diagnostic avancé replié.

Recette : modules inclus et recommandés enregistrés, point d'entrée enregistré, bibliothèque modifiée puis publiée, chemin « Presets publiés » affiché.

## 5. Presets réellement appliqués — implémenté et recetté

- Une nouvelle société reçoit `catalog@vN` et `rbac@v2`, tracés.
- Les modules inclus (dont Workforce et Documents RH, ajoutés depuis Platform) sont activés sans achat.
- Le type de document ajouté dans Platform se retrouve dans son catalogue.
- Brouillon jamais appliqué ; nouvelle version publiée après création jamais réappliquée ; société existante intacte (tests et recette).
- Organisation, exigences suggérées et planning restent des décisions explicites, jamais appliquées d'office.

## 6. Contributions des modules — implémenté et recetté

- Expéditions déclare `driver_identifier` :
  - type texte, unique par personne ;
  - population : personnes dont le rôle porte `shipments.view_own` ;
  - opération dépendante : l'affectation.
- Seule l'affectation est bloquée si la valeur manque, et seulement une fois le besoin associé ; le reste du module fonctionne.
- La valeur est lue via `EmployeeFieldStore`, sans copie, et affichée dans la liste et la fiche expédition.

## 7. Assistant de mapping — implémenté et recetté

Étape « Données » de l'assistant d'activation :
- **Proposition** :
  - vos champs de type compatible d'abord ; les champs standard compatibles seulement sur demande ;
  - jamais de champ sensible (IBAN, NIR) ni de champ de la fiche société.
- **Validation** : confirmation explicite (aucune association automatique) ; mapping stocké par identifiant.
- **Couverture** : personnes concernées, personnes sans valeur (avec lien vers la fiche), doublons.
- **Création du champ manquant** depuis l'assistant, jamais obligatoire.

Recette :
- « ID AMZL » proposé, associé, valeur réutilisée par Expéditions, champ renommé toujours lu ;
- seconde société : « Matricule tournée » créé depuis l'assistant ;
- accès avec l'en-tête d'une autre société : 403.

## 8. Simplification Company

| Règle | Écran propriétaire | Raccourcis |
|---|---|---|
| Types disponibles | Catalogues | Documents › Réglages (lien) |
| Ce qui est demandé | Postes + Informations demandées | Catalogues (« Exiger pour un poste »), Documents › Réglages (lien) |
| Données d'un module | Assistant du module (« Données ») | — |

- Documents › Réglages, pour une société au modèle cible : la grille « Actif / Obligatoire » est remplacée par les deux liens propriétaires. Les sociétés historiques gardent leur grille (compatibilité).

## 9. Pages Platform

| Page | Statut |
|---|---|
| Fiche JobDomain (5 sections) | **Refondue, recettée** (captures claires, sombres et mobiles) |
| Éditeur de presets (bibliothèque) | **Refondu pour la famille bibliothèque**, recetté |
| Fiche Société | Onglets dans le chemin, liens Facturation réparés (`?tab=billing` était ignoré) ; **pas de refonte visuelle** |
| Liste des JobDomains, catalogue des modules, fiche module | **Non refondus** |

## 10. Pages Company

| Page | Statut |
|---|---|
| Hub Réglages | **Refondu en Vuexy** (VCard par rubrique, VList, VExpansionPanels) ; recetté (clair, sombre, mobile) |
| Documents › Réglages | **Refondu** au modèle cible ; recetté |
| Catalogues | Allégé (origines sans bruit, modules traduits) ; recetté |
| Assistant de module (étape Données) | **Nouveau**, recetté |
| Profil société | Carte « exigé par le secteur » retirée au modèle cible ; recetté |
| Équipe, fiche personne, poste, Temps, Paie, Congés, Parrainage | Onglets dans le chemin (recettés) ; **pas de refonte visuelle** |
| Planning, Pointage, Contrats, Expéditions, Livraisons, Espace salarié | **Non refondus** |

## 11. Routes BMAD corrigées — implémenté et recetté (17/17)

- **Équipe** : `/company/people/{hr|roles}`.
- **Fiche personne** : `/company/people/{ref}/{rubrique ou section}`.
- **Workforce** :
  - `/company/workforce/time/{entries|timesheets}`, `/payroll/declarations`, `/leave/{calendar|config}` ;
  - `/referrals/settings`, `/organization/positions/{id}/{onglet}`.
- **Platform** : `/platform/companies/{id}/{onglet}`, `/platform/jobdomains/{id}/{section}`.
- **Émetteurs mis à jour** : liens, déclarations de navigation des modules, priorités de l'accueil, résultats de recherche.
- **Garde-fou** : test empêchant toute nouvelle écriture `?tab=`.
- **Redirections recettées** : `/company/people?tab=hr&q=a` → `/company/people/hr?q=a` (filtres conservés) ; `/company/roles` → `/company/people/roles` ; `?tab=entries`, `?tab=config` → chemins.

## 12. Captures avant / après (`docs/bmad/company-3-0/shots/`)

- **Avant** :
  - onglet « Champs & documents » du JobDomain (41 lignes « Facultatif (activé) » / « Obligatoire configurable ») ;
  - Documents › Réglages (colonne « Obligatoire ») ;
  - hub Réglages ; catalogue ; profil société.
- **Après** :
  - sections JobDomain, éditeur de bibliothèque ;
  - Documents › Réglages au modèle cible, hub Réglages ;
  - étape Données, poste › Champs, candidat mobile ;
  - assistant en sombre, hub Réglages en mobile.
- **Variantes** (`variants.json`) : 48 captures (12 pages × clair, sombre, mobile 390 px, EN) ; **aucun débordement horizontal, aucune clé brute**.
- **Limite** : la variante EN est restée en français. La langue suit la préférence du compte, pas le cookie. **L'anglais n'est donc pas vérifié dans le navigateur** (les traductions sont ajoutées).

## 13. Tests et E2E

- **Nouveaux tests** :
  - `Adr820PresetEngineTest` (bibliothèque sans obligation, application à l'inscription, repli si non publié, navigation) ;
  - `Adr820ModuleDataMappingTest` (champ existant, champ renommé, création, blocage ciblé, inter-société, champ sensible) ;
  - `Adr820TabRoutingConventionTest` ;
  - test de persistance du profil d'expérience.
- **Tests adaptés** à la nouvelle doctrine : 797, 798, 8081, 808, 809, 802, 806, 812, PageModuleAlignment.
- **Suite complète** : **4 686 réussis, 12 ignorés, 3 échecs**. Ce sont les trois échecs historiques de `ControllerSizeInvariantTest` (taille, `DB::`, `->save()`). Aucun contrôleur modifié par ADR-820 n'y figure ; pas de nouvel échec.
- **Build** : propre.
- **Recette navigateur (clone)** : `acceptance.json`, **38/38** ; scénario principal en 19 étapes et compléments.
  - L'inscription passe par **l'endpoint réel** `/api/register`, pas par l'assistant d'inscription.
  - Validation et finalisation RH, et confirmation des accès, sont appelées par l'API depuis la session authentifiée.
- **E2E du dépôt rejoués sur le clone** :
  - `adr799` (×2), `m7-lot2` (×4, dont les captures avec un délai allongé, `php -S` étant lent pour la société 1) : verts ;
  - 3 échecs **dont la cause est antérieure à ADR-820** :
    - `m7-lot4` attend `/company/documents/hr`, alors qu'ADR-802 redirige vers `/documents/people` ;
    - `adr797` dépend d'un état où le RBAC Logistique n'a pas de preset ;
    - `adr798` : bouton « Nouvelle version » masqué pour une famille possédée par un domaine (ADR-802).
  - `adr796-onboarding` (Mailpit) non rejoué.

## 14. Compatibilité des sociétés historiques

- Aucune société existante n'est réinitialisée ni migrée.
- Elles gardent l'autorité « rôle », leurs `required_override`, leurs obligations de catalogue et leur grille Documents.
- Vérifié sur la société 101 du clone : autorité « rôle », obligations historiques conservées.
- **DEV réel** : 22 sociétés inchangées (aucune donnée de société écrite).
- Écritures DEV limitées à la configuration Platform par migrations :
  - bibliothèque Logistique v1 → v2 (adresse structurée) publiée ;
  - lien au domaine ;
  - colonnes `experience` et `recommended_modules` ;
  - table de mapping ;
  - journal d'audit Platform.

## 15. Non terminé

- **Non refondus visuellement** :
  - Platform : liste des JobDomains, catalogue des modules, fiche module, fiche Société (hors routes) ;
  - Company : catalogue des modules (2 170 lignes ; seul le badge « Recommandé » est ajouté) ;
  - Company : Équipe, Organisation et postes, Planning, Pointage, Congés, Contrats, Parrainage, Expéditions, Livraisons, Espace salarié.
- **Vuexy** : `AppSurface`, `AppSettingsGroup` et `AppFormSection` (ADR-782) restent utilisés par 6 pages (Mon espace, profil société, exigences, signature…).
- **Réglages de modules** : les valeurs initiales du planning restent fournies par `JobdomainRegistry::workforcePresetsFor` (chemin historique) et par la famille `planning` ; elles ne sont pas déplacées dans le code du module Workforce.
- **Inscription par l'assistant UI** et **anglais** : non recettés dans le navigateur.
- **Lint** : erreurs de style ESLint préexistantes non traitées.

## 16. Risques résiduels

- **Bascule du chemin de création**, activé par défaut : au prochain déploiement, toute nouvelle société Logistique sera créée par les presets publiés. Retour arrière : `JOBDOMAIN_VERSIONED_INIT=false`.
- **Migrations de publication** (000001, 000004) : elles publient des bibliothèques au déploiement. Le domaine `beta-services` (lien de test daté du 17/11 sur DEV) recevra aussi une bibliothèque s'il est actif.
- **Rôles des nouvelles sociétés** : les droits opérationnels restent en attente jusqu'à confirmation (ADR-808). Il faut confirmer pour que les chauffeurs accèdent à leurs livraisons ; c'est volontaire, mais à expliquer aux clients.
- **Mapping** : la population d'un besoin repose sur les rôles portant la permission. Une personne sans compte n'est pas comptée.
