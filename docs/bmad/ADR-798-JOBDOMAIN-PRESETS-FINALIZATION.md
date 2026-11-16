# ADR-798 — Finalisation JobDomain / Presets : éditeurs Platform, exigences de niveau entreprise, initialisation versionnée

> Date : 2026-11-16 · DEV uniquement · Base `7df6b82d` · Aucun push. Ni migration générale des sociétés, ni bascule.

## 1. Éditeurs spécialisés livrés

Catalogue Platform → onglet **Presets** → « Nouveau preset », ou « Modifier le contenu » sur un **brouillon**. Il n'y a pas d'éditeur JSON.

| Famille | Éditeur | Ce qu'on peut faire |
|---|---|---|
| RBAC | `_PresetEditorDialog` (RBAC) | ajouter ou retirer un rôle ; libellé, identifiant ; profil métier (archétype) ; caractère administratif ; lots de permissions choisis dans le catalogue des modules, les lots sensibles étant marqués ⚠. Un avertissement apparaît dès qu'un rôle est sensible. |
| Exigences | `_PresetEditorDialog` (Exigences) | sujet : collaborateur, **entreprise** ou **représentant légal** ; information ou document du catalogue (filtré selon le sujet) ; niveau : recommandé, facultatif (activé), obligatoire configurable ; postes concernés (vide = toute la population) ; étape ; provenance affichée (configuration historique ou saisie Platform). |
| Planning | `_PresetEditorDialog` (Planning) | modèles d'horaires (nom, début, fin, catégorie de couverture) ; semaines types (besoins par jour : horaire, effectif). Catégories, activités et compositions existantes conservées. Format validé par l'adaptateur du moteur de planning existant ; aperçu d'import sur une société. |
| Organisation | éditeur existant (`/platform/organization-presets`) | inchangé, reçoit seulement la nouvelle règle d'archivage |

**Principes appliqués côté serveur** :
- seul un brouillon est modifiable : une version publiée renvoie 422 ;
- la validation est faite par l'adaptateur de chaque famille (clés, lots connus, doublons, sujets compatibles avec le catalogue, références de planning) ;
- **concurrence optimiste** par `presets.revision` : un éditeur resté sur une ancienne révision reçoit **409** (`stale_revision`) et n'écrase rien ;
- audit `preset.draft_updated` et `preset.archived` ;
- après chaque enregistrement, l'interface compare automatiquement avec la version précédente (« prévisualisation des changements ») ; un aperçu par société reste disponible, en lecture seule ;
- **aucune société n'est modifiée** par l'édition, ce que vérifie une empreinte avant/après.

## 2. Cycle de vie des presets

Invariant : **une recommandation JobDomain n'est jamais laissée sans version utilisable.**

- L'archivage est refusé (`422 recommendation_would_break`, avec les domaines concernés et l'action à mener) quand :
  - la version est **épinglée** par une recommandation ;
  - ou c'est la **dernière version publiée** d'une famille recommandée sans épinglage.
- Il reste possible de **publier une version de remplacement** : la publication archive la précédente, et la recommandation non épinglée suit automatiquement la nouvelle version.
- Recommander une famille sans version publiée est refusé.
- La même règle s'applique aux presets d'organisation (éditeur existant).
- **Concurrence** : l'archivage et l'enregistrement des recommandations verrouillent les lignes de la famille (`lockForUpdate`), ce qui sérialise les deux opérations.
- Les applications historiques (`preset_applications`) sont conservées. Aucune société ne change de version automatiquement.

## 3. Exigences au niveau de l'entreprise

- **Extension minimale du registre** : la colonne `requirements.subject_scope` (additive, valeur par défaut `person` pour toutes les lignes existantes) distingue trois sujets :
  - `person` : collaborateur ;
  - `company` : l'organisation elle-même, avec ses informations de profil (SIRET, TVA, adresse…) et ses documents (Kbis, assurance, licence de transport…) ;
  - `legal_representative` : une pièce personnelle demandée au représentant légal (par exemple sa pièce d'identité), distincte des documents propres à l'entreprise.
- Les catalogues existants sont réutilisés : `FieldDefinition` (portée `company` ou `company_user`) et `DocumentType` (portée `company` ou `company_user`). Aucune nouvelle table.
- **Garde-fous** :
  - un Kbis ne peut pas être exigé d'un salarié, ni une pièce de personne de l'entreprise ;
  - une exigence d'entreprise ne s'applique jamais à un poste ;
  - aucune exigence n'est impérative.
  - Le résolveur des collaborateurs (`EffectiveRequirementResolver`) et la configuration par poste (ADR-792) ne lisent que le sujet `person`.
- **« Pour cette entreprise, quels documents et informations sont demandés, et pourquoi ? »** : `CompanyRequirementResolver`, en lecture seule (API `GET /platform/companies/{id}/company-requirements`, section « Exigences de l'entreprise » de la page JobDomain). Pour chaque élément :
  - le sujet ;
  - le caractère obligatoire ;
  - les **sources** : activation historique de la société, ligne de registre (preset, société, module, avec version) et référence légale, uniquement si elle est vérifiée ;
  - l'état constaté : fourni, manquant, ou demandé au représentant légal.
  - Le représentant légal est désigné à partir de l'appartenance « propriétaire ».
- Aucune demande documentaire créée, aucune obligation inventée. La conformité opérationnelle reste celle des activations historiques.

## 4. Décisions sur les modules à l'inscription

Classification calculée par `EntitlementResolver`, sans aucun changement de Billing :

| Classe | Exemple DEV | Création versionnée |
|---|---|---|
| inclus par la plateforme (socle) | `core.*` | activé |
| inclus par le domaine (sans achat) | `logistics_shipments` | activé |
| dépendance requise | dépendances transitives | activée (raison `required`) |
| optionnel payant (plan ou option) | `logistics_fleet` (plan pro) | **jamais activé**, affiché comme option |
| incompatible | module réservé à un autre domaine | refusé |
| indisponible | inconnu ou désactivé sur la plateforme | ignoré |

La création historique active toute recommandation activée sur la plateforme, même non couverte par l'offre, ce qui produisait les modules au drapeau ambigu relevés en L4. La création versionnée corrige ce point. C'est une différence volontaire.

## 5. Nouvelle initialisation d'une société

`VersionedCompanyInitializer::initializeNew`, alternative complète à `assignToCompany`, derrière le drapeau `JOBDOMAIN_VERSIONED_INIT` (**désactivé**) :

1. domaine d'activité (clé et pivot) ;
2. modules classés (§4) ;
3. RBAC :
   - rôles non sensibles créés automatiquement ;
   - rôles **sensibles uniquement sur confirmation explicite**. À l'inscription, seul le rôle `owner` est confirmé par le créateur ; les autres restent une décision ultérieure ;
   - les rôles issus du preset sont des rôles système, comme dans l'historique ;
   - archétype et étiquettes reprises ; tableaux de bord par rôle ; rôle `owner` attribué au propriétaire ;
4. exigences :
   - copies dans le registre ;
   - **projection opérationnelle** : activations de champs et de documents pour toute la population, car la conformité lit encore ces activations ;
   - les exigences par poste et celles du représentant légal restent dans le registre seulement ;
5. planning et organisation : **décisions explicites** uniquement ;
6. tableau de bord par défaut du domaine.

Il refuse toute société existante (`initializeNew` sur une société déjà initialisée renvoie 422).

**Retour arrière** : désactiver le drapeau rétablit le chemin historique pour les créations futures. Les sociétés créées entre-temps restent des sociétés ordinaires.

## 6. Matrice ancienne / nouvelle création

Mesure : `CreationParity`, via la commande `jobdomains:creation-parity`, l'API et la page JobDomain. Elle crée deux sociétés **fictives** dans une **transaction toujours annulée**. L'option « depuis l'historique » construit les presets à partir de l'ancienne configuration dans cette même transaction.

**Résultat DEV, domaine « logistique », FR, plan starter : 0 écart technique, 11 différences volontaires.**

| Dimension | Ancienne | Nouvelle | Verdict |
|---|---|---|---|
| Paramètres (domaine, pivot) | ✓ | ✓ | identiques |
| Rôles : clés, libellés, permissions, administratif, système, archétype, étiquettes | ✓ | ✓ | identiques |
| Rôles : prescriptions de champs et documents par rôle | présentes | absentes | **volontaire** : un rôle n'est pas un poste (10 lignes) |
| Rôle du propriétaire | owner | owner | identique |
| Modules et raisons | défauts actifs | inclus actifs | identiques sur DEV ; un module payant n'est jamais activé (volontaire) |
| Activations de champs (société + personne) | ✓ | ✓ (projection) | identiques (enabled, required_override, order) |
| Activations de documents | ✓ | ✓ (projection) | identiques |
| Tableaux de bord (société, par rôle) | ✓ | ✓ | identiques |
| Registre des exigences | 0 | 41 lignes | **volontaire** : parallèle, non opérationnel |
| Planning | non créé | non créé (décision explicite) | identique |

Quand les presets publiés diffèrent de l'ancienne configuration, les écarts sont classés « différence de configuration », et non « bug ». Aucune fausse équivalence : `parity_proven` reste `false` tant que les différences volontaires ne sont pas acceptées par décision produit.

## 7. Permissions

- **Nouveaux droits Platform**, déclarés dans le manifeste du module, avec trois lots (lecteur, éditeur, publieur) :
  - `presets.view` : consulter, métadonnées, comparer ;
  - `presets.edit` : créer et modifier un brouillon, nouvelle version, brouillon depuis l'historique ;
  - `presets.publish` : publier, archiver ;
  - `presets.preview_company` : aperçu sur une société, exigences de l'entreprise (lecture).
- `manage_jobdomains` reste le droit complet. Il est le **seul** à permettre de recommander un preset à un JobDomain et d'**appliquer** un preset à une société.
- Le middleware accepte `a|b` (l'une des permissions suffit). Chaque API est protégée, pas seulement les boutons. L'interface masque les actions non autorisées.
- **Migration explicite des droits** : les quatre droits fins ont été accordés **uniquement** aux rôles qui détenaient déjà `manage_jobdomains` (2 rôles sur DEV). Il s'agit d'un même périmètre découpé plus finement, sans élévation.

## 8. Tests et captures

- `Adr798PresetEditorsCompanyRequirementsTest` : 8 tests couvrant les scénarios 1 à 27 et 29.
  - Édition RBAC, publication immuable, édition concurrente (409), audit, nouvelle version, comparaison.
  - Sujets entreprise, collaborateur et représentant légal, avec refus sur Kbis demandé à un salarié, pièce de personne demandée à l'entreprise, niveau impératif, poste sur une exigence d'entreprise, sujet inconnu, doublon. Provenance ; aperçu sans écriture ; résolveur personne sans Kbis ; « quoi et pourquoi » ; aucune demande DMS.
  - Format du planning et aperçu d'import.
  - Cycle de vie : recommandation sans version publiée refusée, archivage refusé (dernière publiée, épinglée), remplacement, historique conservé ; règle étendue à l'Organisation.
  - Modules : classification, aucun achat, aucun rôle sensible non confirmé, rôle owner.
  - Parité sur sociétés fictives (0 écart technique, aucun résidu), inscription avec drapeau désactivé puis activé, société existante inchangée.
  - Permissions fines (lecteur, éditeur, publieur, aperçu, aucun droit, session Company refusée).
  - Absence de N+1.
- Mises à jour ADR-797 et ADR-793 :
  - API d'initialisation et parité mesurée ;
  - recommandation d'un brouillon désormais refusée, conformément au §2.
- Régression du dossier candidat (scénario 28) : suites ADR-796 et 796.1 incluses dans la suite complète.
- **Recettes navigateur** :
  - ADR-798 1/1 : création et édition RBAC avec rôle sensible, création Exigences entreprise + collaborateur, publication, archivage refusé, exigences de l'entreprise de la société 1, parité depuis l'historique, sombre, mobile 390 px sans débordement ;
  - ADR-797 1/1, mise à jour ;
  - fixtures supprimées par identifiant exact, 0 résidu.
- Captures : `docs/bmad/adr798/`.
- **Suite complète** (une fois, en fin de lot) : 4476 réussis, 12 ignorés, 4 échecs.
  - 3 sont les échecs historiques `ControllerSizeInvariantTest`.
  - 1 est une **régression de ce lot**, détectée par la suite : l'archivage d'un preset d'organisation renvoyait l'objet non rechargé. Corrigée, puis revérifiée par un test ciblé : `OrganizationPresetAdminTest` et ADR-798, 15/15.
- Build propre.

## 9. Compatibilité historique

- Colonnes JobDomain conservées, toujours en lecture seule. `JOBDOMAIN_LEGACY_EDITING` reste désactivé.
- `assignToCompany` est inchangé et reste le chemin de création tant que le drapeau est désactivé. Seule la fonction de construction des tableaux de bord est devenue publique, pour être réutilisée.
- **Correspondance vers les nouveaux presets** :
  - les éléments de niveau société sont désormais transposés avec le sujet `company` ;
  - les rôles conservent archétype et widgets ;
  - les prescriptions par rôle ne sont **jamais** converties en exigences de poste.
- Exigences de personne : clés de preset inchangées depuis ADR-793 ; les lignes existantes passent toutes en `person` par défaut.
- Sociétés existantes : aucun rôle, module, exigence, donnée RH ou document modifié (empreintes).

## 10. Risques résiduels

- L'éditeur de planning ne modifie pas la **composition** fine (activités et pauses d'un horaire) ni les catégories et activités : elles sont conservées et validées, et leur édition fine reste à faire.
- Le **représentant légal** est déduit de l'appartenance « propriétaire » ; il n'existe pas de désignation dédiée.
- L'**état « fourni »** d'un document d'entreprise ne vérifie ni sa validité ni son expiration.
- À l'inscription versionnée, les rôles sensibles autres que `owner` ne sont pas créés : c'est une différence produit avec l'historique, qui en crée cinq.
- La **parité** est mesurée sur « logistique » seulement ; `beta-services` n'a pas de configuration historique de rôles.
- La **révision** reste facultative pour les appels directs à l'API ; l'interface l'envoie toujours.

## 11. Conditions de bascule

1. **Décision produit** sur les différences volontaires : prescriptions par rôle, enregistrement dans le registre, modules non couverts par l'offre, rôles sensibles à l'inscription.
2. Presets publiés et recommandés pour chaque domaine actif. La parité « depuis l'historique » doit rester à 0 écart technique, et les écarts de configuration doivent être revus.
3. Activation de `JOBDOMAIN_VERSIONED_INIT` sur DEV, puis inscription de sociétés fictives de bout en bout, puis observation. Le retour arrière se fait par le drapeau.
4. Aucune migration des sociétés existantes avant une décision distincte. La conformité opérationnelle (`DocumentResolverService`) n'est pas basculée.

## 12. SHA DEV

Commit du lot : `264935a3` (base `7df6b82d`).
