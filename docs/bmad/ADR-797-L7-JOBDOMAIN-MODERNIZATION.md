# ADR-797 — L7 : modernisation des JobDomains

> Date : 2026-11-16 · DEV uniquement · Base `0b582b2f` · Aucun push. Dernier lot structurel JobDomain / Presets / Modules / Requirements.

## 1. Architecture avant / après

**Avant**, le JobDomain était un second moteur de configuration :
- ses colonnes `default_roles`, `default_fields`, `default_documents` et `workforce_presets`, plus leurs surcharges par marché, s'éditaient directement dans une page Platform de 2 534 lignes (onglets rôles, champs, documents) ;
- elles étaient copiées dans chaque société à sa création (`JobdomainGate::assignToCompany`) ;
- en parallèle, ADR-793 versionnait ces mêmes familles dans `presets`.

**Après** :

| Responsabilité | Porteur | État |
|---|---|---|
| Identité, secteur, terminologie, marchés | JobDomain (colonnes `sector`, `terminology` ajoutées) | éditable |
| Modules recommandés | JobDomain (`default_modules`) | éditable ; activés seulement à la création par le moteur historique |
| Presets recommandés (RBAC, Organisation, Exigences, Planning) | `jobdomain_preset_links` (+ `pinned_version`) | éditable ; une recommandation n'est jamais une application |
| Rôles, champs, documents, planning du JobDomain | colonnes historiques | **lecture seule** (API et interface), toujours lues par la création des sociétés |
| Évolution de ces familles | presets versionnés (adaptateurs ADR-793) | brouillon → publication → application explicite, société par société |
| Contributions des modules | manifestes (ADR-794) | inchangé |
| Exigences opérationnelles | `DocumentResolverService` | autorité inchangée ; `EffectiveRequirementResolver` reste parallèle |

## 2. Cartographie des anciens consommateurs

| Consommateur | Lit | Peut lire un preset ? | Utilisé par des sociétés existantes ? | Sans preset | Risque |
|---|---|---|---|---|---|
| Inscription (`RegisterCompanyUseCase`) → `assignToCompany` | modules, champs, documents, rôles (+ surcharges) | oui (initialisation préparée, §8) | à la création seulement | comportement historique | aucun : inchangé |
| Changement d'activité (`CompanyJobdomainController`) → `assignToCompany` | idem | idem | oui, ponctuellement | idem | aucun : inchangé |
| `PresetReconciler` / `preset:reconcile` | `default_roles` | non (RBAC adaptatif) | **commande manuelle uniquement** | n/a | non déclenché par L7 |
| `JobdomainPresetResolver` (`PublicFieldController`, inscription) | champs, documents (affichage public) | non | lecture seule | inchangé | aucun |
| Conformité documentaire (`DocumentResolverService`) | activations de la **société** (photographie prise à la création) | ne lit pas le JobDomain | oui | n/a | aucun |
| Champs dynamiques (`FieldResolverService`) | activations société + `required_by_jobdomains` du catalogue | non | oui | n/a | aucun |
| Import planning (`WorkforcePresetImportService`) | `workforce_presets`, ou preset Planning via son adaptateur | oui (ADR-793) | à la demande | historique | aucun |
| Modules (`EntitlementResolver`, `ModuleDisplayStateResolver`, offre publique) | `default_modules` | — (reste au JobDomain) | oui | n/a | aucun |
| Dossier d'intégration (L6, `OnboardingPlanner`) | règles opérationnelles + registre L1 | — | oui | n/a | aucun (test de non-régression) |
| Mise à jour Platform d'un JobDomain | — | — | **ne se propage à aucune société existante** (vérifié dans le code et par un test) | — | — |

## 3. Responsabilités supprimées ou dépréciées

- **Gel** de l'édition de l'ancienne configuration :
  - `PUT /platform/jobdomains/{id}` refuse `default_roles`, `default_fields` et `default_documents` (`422 legacy_configuration_read_only`) ;
  - `POST /platform/jobdomains` refuse `default_fields` ;
  - les surcharges par marché refusent rôles, champs et documents, en création comme en suppression.
  - Échappatoire technique : `JOBDOMAIN_LEGACY_EDITING=true` (désactivée). Les tests du moteur historique l'activent explicitement.
- **Suppression** de l'interface d'édition des rôles, champs et documents par JobDomain : l'ancienne page de 2 534 lignes est remplacée. Ces données restent consultables dans une section repliée « Configuration historique (lecture seule) ».
- **Rien n'est supprimé en base** : aucune colonne, aucune donnée, aucun lecteur.

## 4. Interface Platform modernisée (`/platform/jobdomains/{id}`)

Une seule page lisible, sans onglets :
1. **En-tête** : nom, clé, nombre de sociétés, statut, rappel de la doctrine.
2. **Identité métier** : nom, secteur, description, terminologie (collaborateur, poste, équipe, site), marchés, avec mention des surcharges historiques.
3. **Modules recommandés** : sélection, dépendances, plan minimal, module inconnu ou désactivé. Aucun achat ni activation pour une société existante.
4. **Presets recommandés** : une ligne par famille, avec preset, version (dernière publiée ou épinglée), version plus récente signalée et lien « Gérer ».
5. **Diagnostic de compatibilité** :
   - par famille : correspondances certaines, partielles, sans équivalent, nouvelles dans le preset ;
   - bouton « Créer un brouillon depuis l'historique » ;
   - prescriptions par rôle (décision humaine) ;
   - moteurs historiques encore actifs.
6. **Compatibilité d'une société existante** : choix d'une société, puis rapport en lecture seule.
7. **Configuration historique** (lecture seule, repliée).
8. **Aperçu d'une nouvelle société** : comparaison entre création historique et initialisation versionnée, non opérationnelle.

Permissions : `manage_jobdomains` (inchangée), y compris pour un utilisateur Platform non superadmin. Aucun nouveau menu : un onglet « Presets » s'ajoute au catalogue existant, avec la même permission.

## 5. Gestion des presets (catalogue → « Presets »)

- Liste filtrable par famille (RBAC, Exigences, Planning). L'Organisation garde son éditeur dédié existant, avec un lien vers celui-ci.
- Le volet de détail affiche le contenu élément par élément, les versions, les domaines qui le recommandent et la version épinglée. Il permet de :
  - **publier** un brouillon ;
  - créer une **nouvelle version** en brouillon ;
  - **archiver** ;
  - **comparer** deux versions ;
  - **prévisualiser** l'effet sur une société, en lecture seule, avec le statut de chaque élément.
- **Pas d'éditeur JSON** : un brouillon naît d'une version existante ou de la configuration historique d'un JobDomain (`LegacyPresetDraftFactory`, §6). Aucune famille ne dispose encore d'un éditeur élément par élément ; c'est assumé et affiché.

## 6. Associations JobDomain / presets

- Une recommandation désigne une **identité stable** (`kind` + `family_key`) et une **version identifiable** :
  - `pinned_version`, qui doit être une version effectivement publiée, jamais un brouillon ;
  - à défaut, la dernière version publiée.
  - Une version plus récente qu'une version épinglée est signalée (`newer_published`), jamais imposée.
- Le catalogue n'est pas dupliqué dans les colonnes du JobDomain. Une recommandation ne vaut jamais application : 0 `preset_applications` créée, vérifié par les tests et la recette. Une nouvelle version ne réapplique rien.
- **Brouillons depuis l'historique** :
  - RBAC : clés, noms, lots de permissions et caractère administratif identiques à l'historique ;
  - Exigences : documents et champs **de personne** uniquement (« obligatoire » → `configurable_required`, jamais impératif). Les éléments de niveau société (SIRET, Kbis, assurance…) restent sur le moteur historique, faute d'équivalent ;
  - Planning : même forme que `workforce_presets`.
  - Un seul brouillon ouvert par famille.
  - Les prescriptions par rôle **ne sont pas transposées** : un rôle RBAC n'est pas un poste. Elles sont signalées comme décision humaine.

## 7. Compatibilité des Companies

Le **rapport par société** (`CompanyJobdomainCompatibility`, API, commande `jobdomains:compatibility-report`) est en lecture seule et assemble des instruments existants :
- l'aperçu de chaque adaptateur pour les presets recommandés ;
- le comparateur de parité des exigences (ADR-790/L1) ;
- la correspondance prescriptions par rôle → postes (ADR-792) ;
- l'état réel des modules (`ModuleGate`) face au drapeau historique ;
- les fiches sans poste, sans compte, en préparation, et les comptes multi-sociétés.

Classes utilisées : certaine, partielle, ambiguë, conflit, décision humaine.

Garanties prouvées par empreinte avant/après (rôles et permissions, modules et raisons, activations de champs et documents, exigences documentaires, `requirements`, `preset_applications`, postes, fiches) : aucune perte de rôle, aucun nouveau droit, aucun document, exigence, poste, module ou planning modifié. Aucune conversion de masse ni réconciliation globale.

## 8. Préparation du nouvel onboarding Company

`VersionedCompanyInitializer` suit le parcours : activité → recommandations → aperçu → application des choix autorisés → personnalisation.
- `plan()` et `parity()` sont en **lecture pure**.
- `initialize()` refuse de s'exécuter tant que `JOBDOMAIN_VERSIONED_INIT` n'est pas activé (désactivé par défaut). Une fois activé (testé uniquement), il :
  - n'applique que les familles explicitement choisies ;
  - **n'active aucun module** ;
  - ne crée jamais de rôle sensible ou administratif sans sélection explicite (règle de l'adaptateur RBAC).
- La **parité n'est jamais déclarée automatiquement**. Même si rôles et exigences sont équivalents, les modules diffèrent par conception : activés par la création historique, seulement recommandés par l'initialisation versionnée. Une décision produit est requise. `assignToCompany` reste le seul chemin opérationnel.
- Règle préservée : une société possède exactement un JobDomain.

## 9. Rapport des écarts historiques (société 1, DEV, lecture seule)

| Constat | Valeur | Traitement |
|---|---|---|
| Fiches sans poste | **45 / 52** | proposées à régularisation, aucune modifiée |
| Poste incohérent avec le rôle | **6** « Responsable d'exploitation × Driver » (+ 1 « × Propriétaire ») | couples présentés pour validation humaine, aucun poste déduit |
| Fiches sans compte | 36 | ambiguïtés signalées (champs) |
| Comptes multi-sociétés | 1 | divulgation par société (ADR-795.1) |
| Exigences : historique / parallèle / identiques / écarts | 368 / 232 / 156 / 288 | par catégorie : 144 données historiques ambiguës, 131 configurations manquantes, 9 différences attendues, 4 arbitrages, 0 anomalie de résolution |
| Prescriptions par rôle → postes | 4 conflits, 55 décisions, 10 ambiguës, 2 proposées | inchangé (décision humaine) |
| Module au statut ambigu | `logistics_fleet` (drapeau sans activation réelle) | signalé, jamais réactivé |
| JobDomain « logistique » | 5 rôles, 23 exigences de personne, 15 éléments de planning sans équivalent versionné ; 18 éléments de niveau société restent sur le moteur historique | brouillons disponibles, non créés sur DEV |

Le nouveau modèle explique ses décisions. Les incohérences historiques ne sont pas recopiées pour obtenir une parité artificielle.

## 10. Sécurité et RBAC

- Toutes les nouvelles routes sont sous `module.active:platform.jobdomains` et `platform.permission:manage_jobdomains`.
  - Un utilisateur Platform avec `manage_jobdomains` seul (non superadmin) y accède.
  - Avec `view_companies` seul, il reçoit 403.
  - Une session Company seule reçoit 401.
- Aucune élévation de droits : rien n'applique un preset RBAC, et l'initialisation préparée exclut les rôles sensibles par défaut.
- Audit Platform : `jobdomain.preset_recommendations_updated` et `preset.drafted_from_legacy`.
- Isolation : le rapport de compatibilité ne lit que la société demandée ; une société inexistante renvoie 404.
- Constat de test (pas un défaut applicatif) : enchaîner `actingAs` Platform puis Company sans préciser le garde place l'utilisateur Company dans le garde Platform du test. Le middleware lit bien `user('platform')`. Le test précise désormais le garde.

## 11. Tests et captures

- `Adr797JobdomainModernizationTest` : 7 tests couvrant les points 1 à 24 du lot.
  - JobDomain vierge, avec rôles, documents et planning historiques.
  - Association, versions, épinglage, nouvelle version sans application, pas d'obligation dupliquée.
  - Société existante et création historique inchangées, sans réconciliation ni changement de conformité.
  - Initialisation préparée : aperçu sans écriture, refus tant que non opérationnelle, comportement additif une fois activée.
  - Permissions Platform réelles, utilisateur non superadmin, aucun accès Company.
  - Rapport en lecture seule et isolé : modules actifs, inactifs et ambigus ; exigence par poste ; fiche sans compte ; compte multi-sociétés ; aucun poste déduit d'un rôle.
  - Dossier candidat inchangé ; commande de nettoyage protégée.
- Tests du moteur historique (`PlatformJobdomainTest`, `JobdomainOverlayApiTest`) passés par l'échappatoire explicite. ADR-793 7/7.
- **Recette navigateur 1/1** :
  1. Domaine « logistique » en lecture : diagnostic, historique, aperçu d'une nouvelle société, rapport d'une société.
  2. Domaine et famille de presets fictifs créés par l'API : identité, association de version, administration des presets (nouvelle version, comparaison, publication, aperçu sur une société), épinglage.
  3. Affichage desktop clair et sombre, mobile 390 px sans débordement horizontal.
  4. Fixtures supprimées par identifiant exact : 0 résidu.
- **Captures** : `docs/bmad/adr797/`.

## 12. Rapport de suppression des six fixtures

Autorisation explicite du propriétaire. Branche `dev`, base locale `leezr`.
- **Vérification** : les fiches 1771 à 1776 relèvent de la société 1, sont toutes nommées « E2E Recrue … », inactives, préparatoires et sans compte. Chacune est liée à un seul dossier de test (#1 à #6).
- **Simulation** : identique au rapport ADR-796.1 (dossiers 1/1/1/1/1/1 ; documents, demandes, valeurs RH et valeurs en attente 0/0/1/1/1/1). Aucune trace opérationnelle refusée.
- **Application** (`--apply`) :

| Élément | Écart |
|---|---|
| Fiches | −6 (242 → 236) |
| Documents | −4 (10 → 6) |
| Demandes | −4 (64 → 60) |
| Valeurs RH | −4 (21 → 17) |
| Dossiers d'intégration | −6 (6 → 0) |
| Audits `onboarding.fixture_removed` | +6, un par fiche |

Rien d'autre n'a été supprimé.

## 13. Conditions de désactivation des anciens moteurs

1. **Presets RBAC, Exigences et Planning** publiés et recommandés pour chaque JobDomain actif. Leur diagnostic ne doit plus montrer d'élément « sans équivalent » non arbitré.
2. **Décision produit** sur l'activation des modules à la création (historique : activés ; versionné : recommandés).
3. **Éléments de niveau société** (SIRET, Kbis, assurance) : un porteur versionné est défini, ou ils restent explicitement sur un réglage de création.
4. **Prescriptions par rôle** : transformées en exigences de poste par chaque société (ADR-792), après décision humaine.
5. **Parité** de création mesurée sur des sociétés fictives, puis activation contrôlée de `JOBDOMAIN_VERSIONED_INIT` sur DEV.
6. Seulement ensuite, retrait progressif des lecteurs : `assignToCompany` (rôles, champs, documents), surcharges par marché, `PresetReconciler`. Les colonnes restent conservées.

## 14. Conditions de bascule du resolver

`EffectiveRequirementResolver` reste parallèle ; `DocumentResolverService` reste l'autorité. La bascule suppose :
1. 0 anomalie de résolution (atteint) ;
2. configurations manquantes recréées comme exigences de poste par les sociétés ;
3. données ambiguës traitées : postes renseignés et modules au drapeau ambigu régularisés ;
4. arbitrages produit tranchés ;
5. une bascule par société, réversible, mesurée avant et après.

## 15. Risques résiduels

- **Interface supprimée** : l'édition des surcharges de modules par marché n'a plus d'écran (API toujours disponible). Le besoin est à confirmer.
- **Éditeurs élément par élément** : absents pour RBAC, Exigences et Planning. Un brouillon ne s'écrit que par version copiée ou depuis l'historique, et une modification fine d'un brouillon reste API uniquement.
- **Rapport par société** : environ 140 ms et 300 requêtes pour la société 1. Acceptable pour un usage administratif, pas pour un tableau de bord.
- **Archivage** de la seule version publiée d'une famille recommandée sans épinglage : la recommandation devient inutilisable. C'est signalé dans l'interface, sans être bloqué.
- **DEV** : le flux temps réel peut occuper un worker PHP jusqu'à 30 s. La recette prévoit des attentes longues, sans effet sur le produit.

## 16. SHA DEV

Commit de ce lot : `e918e4d8` (base `0b582b2f`). Suite complète : 4469 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest` seulement. Build propre.
