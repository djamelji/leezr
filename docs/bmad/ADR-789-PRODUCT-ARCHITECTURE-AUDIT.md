# ADR-789 — Audit d'architecture produit : JobDomains · Presets · Modules · Champs · Documents

> Base `382981ad` · **audit uniquement** : aucun code, aucune migration, aucune écriture en base, aucun rôle ni configuration Company modifiés.
> Méthode : lecture du code réel (modèles, services, read models, contrôleurs, pages Platform et Company) et requêtes de lecture sur la base DEV. Chaque constat cite son fichier.
> Statut : **proposé** — en attente de GO.

---

## 1. Diagnostic

Leezr possède déjà presque toutes les briques nécessaires : un catalogue de champs, un catalogue de types de documents, des activations par Company, des demandes documentaires dédupliquées, des campagnes, une politique de confidentialité documentaire, un preset d'organisation **versionné, prévisualisable et appliqué sélectivement**, un moteur d'activation de modules avec dépendances et raisons, des fiches salarié sans compte.

Le problème n'est pas un manque de moteurs, mais **où les décisions sont prises** :

1. **Le JobDomain est devenu un « preset monolithique »** : il porte à la fois le contexte métier, les modules, les champs, les documents (avec obligation), les archétypes et étiquettes, les **rôles RBAC complets** (permissions, widgets, configuration de champs et de documents par rôle) et les presets Workforce. Il est appliqué **une seule fois**, à l'affectation, sans version ni trace de provenance par élément.
2. **Les exigences d'une personne dépendent de son rôle RBAC** (étiquettes requises du rôle, `field_config`, `doc_config`), alors que le **poste** (`JobRole` : « Livreur », « Gestionnaire administratif ») existe et n'est relié à aucune exigence.
3. **Deux résolveurs documentaires divergent** : la fiche d'une personne applique les règles du rôle ; la conformité de l'entreprise les ignore.
4. **Un salarié sans compte ne peut porter aucun champ dynamique** : les valeurs « collaborateur » sont stockées sur le `User`. Or c'est précisément la situation d'une nouvelle recrue.
5. **Les modules contribuent aux champs (activation automatique) mais pas aux documents**, et ne déclarent leurs contributions qu'à travers des règles enfouies dans les catalogues (`required_by_modules`), pas dans leur manifeste.

Conséquence : une même obligation (« le livreur doit fournir son permis ») peut être exprimée à **cinq endroits** (catalogue, JobDomain, activation Company, configuration du rôle, étiquettes de l'archétype), sans résolution commune ni justification affichable.

---

## 2. Inventaire des moteurs existants

| Moteur | Code | Rôle actuel | Persistance | Versionné | Trace de provenance |
|---|---|---|---|---|---|
| Registre JobDomain | `Core/Jobdomains/JobdomainRegistry` (1 seul JobDomain : `logistique`) | définitions en code, recopiées en base par `sync()` | table `jobdomains` (JSON : `default_modules`, `default_fields`, `default_documents`, `default_roles`, `workforce_presets`) | non | non |
| Overlay marché | `JobdomainMarketOverlay`, `JobdomainPresetResolver` (ADR-190) | ajoute / remplace / retire modules, champs, documents, rôles par marché | table overlay | non | non |
| Affectation | `JobdomainGate::assignToCompany` | active modules (+ dépendances), activations de champs et de documents (`required_override`), crée les rôles (permissions via bundles, `field_config` et `doc_config` **seulement si nuls**), tableaux de bord | lignes Company | — | `CompanyPresetSnapshot` (rôles + permissions seulement) |
| Réconciliation RBAC | `PresetReconciler`, `permissions:reconcile` (ADR-375) | écart rôle ↔ préréglage ; `--apply` **réaligne tout le rôle** | — | non | rapport |
| Catalogue de champs | `Core/Fields/FieldDefinitionCatalog` | définitions (portée `company`, `company_user`, `platform_user`), règles (`required_by_jobdomains`, `required_by_modules`, `tags`, `sensitive`, `applicable_markets`, `category`) | `field_definitions` | non | — |
| Activation de champs | `FieldActivation` | par Company : activé, `required_override`, ordre | `field_activations` | — | non |
| Valeurs de champs | `FieldValue` | valeurs par modèle ; en DEV seulement `company` (16) et `user` (1) | `field_values` | — | non |
| Résolution des champs | `FieldResolverService` + `MandatoryContext` | obligatoire = catalogue (JobDomain, module, étiquettes ∩ étiquettes du rôle) OU override Company ; puis surcouche `field_config` du **rôle RBAC** | — | — | partielle (`mandatory`) |
| Catalogue documentaire | `Core/Documents/DocumentTypeCatalog` | types (portée `company` / `company_user`), expiration, `required_by_modules`, `tags`, confidentialité | `document_types` | non | — |
| Activation documentaire | `DocumentTypeActivation` | par Company : activé, `required_override`, ordre | `document_type_activations` | — | non |
| Résolution documentaire (fiche) | `DocumentResolverService` + `DocumentMandatoryContext` | module, étiquettes × **rôle RBAC**, override Company, surcouche `doc_config` du rôle | — | — | partielle |
| Conformité | `DocumentComplianceReadModel` (ADR-761/763) | population dédupliquée par sujet (Employee avec ou sans compte, User sans Employee) ; exigences = activations requises **sans rôle** | — | — | non |
| Demandes | `DocumentRequest`, `DocumentRequestService::findExisting` | une demande active par (sujet, type) | `document_requests` + événements | — | événements |
| Campagnes | `DocumentCampaign`, `CampaignTargetingResolver` | ciblage tous / service / personnes / statut | `document_campaigns`, destinataires | — | oui |
| Demandes par rôle | `BatchRequestByRoleUseCase` | ciblage par **rôle RBAC** | — | — | — |
| Accès documentaire | `DocumentAccessPolicy` (ADR-757/758) | soi, manager direct, supervision, paie, confidentiel | — | — | — |
| Modules | `ModuleManifest`, `ModuleActivationEngine` | permissions, bundles, navigation, actions, dépendances, raisons d'activation ; **active les champs `required_by_modules`** (ADR-168b) ; ne déclare **ni champs ni documents** dans son manifeste | `company_modules`, raisons | non | raisons |
| Preset d'organisation | `Workforce/Organization/*` (ADR-636) | services + **postes** ; famille, version, statut publié, **aperçu nouveau / existant / personnalisé**, application sélective, enregistrement d'application, provenance par élément (`source`, `preset_item_key`) | `organization_presets`, `organization_preset_applications`, `workforce_job_roles` | **oui** | **oui** |
| Presets Workforce (planning) | `JobdomainRegistry::workforcePresetsFor`, `WorkforcePresetImportService` | catégories, activités, modèles de créneaux, semaines types | JSON JobDomain → tables planning | `schema_version` | partielle |
| Poste | `JobRole` (`workforce_job_roles`) | intitulé, service, niveau, taux horaire, provenance | table | — | oui |
| Employé | `Employee` | identité RH (colonnes), compte facultatif | `workforce_employees` | — | — |
| Contrats, documents générés, signatures | `EmploymentContract`, `GeneratedDocument`, signature (ADR-765) | cycle de vie propre, documents immuables | tables dédiées | — | oui |

**Ce qui fonctionne et doit être conservé** : catalogues ; activations par Company ; dédoublonnage des demandes par (sujet, type) ; conformité par sujet incluant les salariés sans compte ; politique d'accès documentaire ; raisons d'activation des modules ; preset d'organisation (le meilleur modèle de preset du code) ; overlays marché ; immutabilité des documents générés.

---

## 3. Cartographie des responsabilités et réponses aux questions

| Question | Réponse (code actuel) |
|---|---|
| Pourquoi le JobDomain possède-t-il des rôles par défaut ? | Historique : avant les bundles et la réconciliation, le JobDomain était le seul endroit pour décrire une société type. `default_roles` contient en plus des **exigences** (champs, documents) et des widgets : c'est un preset RBAC + exigences, stocké dans le JobDomain et éditable dans la page Platform. |
| Différence entre ces rôles et les « presets RBAC » ? | **Il n'existe pas d'objet « preset RBAC » distinct** : `PresetReconciler` relit justement `default_roles` du JobDomain. Ce sont la même chose, sans version ni historique (seul un instantané des rôles à l'inscription). |
| Qui décide quels documents demander ? | Cinq sources : `default_documents` du JobDomain (copié en `required_override` à l'affectation), `required_by_modules` du catalogue, étiquettes × étiquettes requises du **rôle**, `doc_config` du **rôle**, et la Company (activation / override, demandes, campagnes). |
| Différence preset documentaire / obligation DMS ? | Le « preset documentaire » (`default_documents`, `doc_config`) n'existe qu'à l'initialisation ; ensuite seule l'activation Company compte. La conformité ne lit que l'activation : `doc_config` et étiquettes y sont **ignorés**. |
| Qui décide quels champs sont visibles dans une fiche ? | Activation Company (activé / ordre) puis surcouche `field_config` du **rôle RBAC** du membre. Les fiches salarié sans compte n'ont pas de champs dynamiques. |
| Qui décide quels champs doivent être renseignés ? | Catalogue (`required_by_jobdomains`, `required_by_modules`, étiquettes × rôle), override Company, `field_config` du rôle (qui ne peut pas rendre facultatif un champ obligatoire du catalogue). |
| Les modules peuvent-ils déclarer des champs ? | Indirectement : le catalogue marque `required_by_modules` et l'activation du module active ces champs. Rien dans `ModuleManifest`. |
| Des obligations documentaires ? | Indirectement (`required_by_modules` du catalogue documentaire) et **sans activation automatique** au moment de l'achat. |
| Que se passe-t-il si une Company change une valeur proposée ? | Elle modifie la ligne (activation, override, `field_config`). Aucune trace de l'origine de la valeur, donc impossible de savoir ensuite si c'est un choix ou une valeur par défaut. |
| Qu'arrive-t-il aux sociétés si un preset évolue ? | Rien pour champs, documents, `field_config`, `doc_config` (appliqués une fois, « seulement si nul »). Pour les permissions : `permissions:reconcile` détecte l'écart, et `--apply` réaligne **tout le rôle** — dangereux (cf. ADR-788). Seul le preset d'organisation sait comparer et appliquer sélectivement. |

---

## 4. Doublons démontrés

| # | Doublon | Sources de vérité actuelles | Consommateurs | Risque | À conserver | À déprécier / adapter | Migration |
|---|---|---|---|---|---|---|---|
| D1 | **Rôles du JobDomain = preset RBAC** | `jobdomains.default_roles` (+ registre en code) | affectation, réconciliation, page Platform « Rôles » | modification Platform sans version ; `--apply` destructif | les bundles (manifestes) | `default_roles` comme lieu d'édition → **preset RBAC versionné** | extraire tel quel en preset `logistique_rbac_v1` |
| D2 | **Exigences portées par le rôle RBAC** (`field_config`, `doc_config`, `required_tags`) | rôles Company + JobDomain | `FieldResolverService`, `DocumentResolverService`, `roles.vue` | confusion rôle (droits) / poste (activité) ; un livreur promu dispatcher change d'exigences | — | **exigences par poste** ; la configuration par rôle devient lecture seule, puis convertie | conversion assistée rôle → poste homonyme (aperçu) |
| D3 | **Obligation documentaire en 5 endroits** | catalogue, `default_documents`, activation, `doc_config`, étiquettes | fiche, conformité, demandes, campagnes | divergence fiche / conformité (démontrée) ; permis jamais en conformité pour les livreurs, pièce d'identité exigée à tous | catalogue, activation, demandes | sources concurrentes → **une table d'exigences avec provenance** | lecture des 5 sources → exigences équivalentes |
| D4 | **Obligation de champ en 5 endroits** | catalogue (`required_by_*`, étiquettes), `default_fields`, override, `field_config` | fiches, profil, formulaires | même risque, sans divergence de résolveur (un seul) | catalogue, activation, valeurs | idem D3 | idem |
| D5 | **Deux résolveurs documentaires** | `DocumentResolverService` vs `DocumentComplianceReadModel` | fiche / conformité | statuts contradictoires | conformité par sujet | résolution unique | remplacement interne, tests de non-régression |
| D6 | **Étiquettes d'archétype** (`archetypes.default_tags` → `required_tags` du rôle) | registre | résolveurs | troisième axe implicite d'obligation | concept de famille métier | → **attributs du poste** | correspondance archétype → postes |
| D7 | **Ciblage documentaire par rôle et par service/personnes** | `BatchRequestByRoleUseCase`, campagnes | Members, Documents | deux chemins de demande en masse | campagnes | demande par rôle → campagne ciblée par poste | redirection fonctionnelle |
| D8 | **Valeurs personnelles sur `User`** et identité RH sur `Employee` | `field_values` (user), colonnes Employee | profil, fiche, ADR-787 (verrou partagé entre sociétés) | salarié sans compte sans champs ; valeurs partagées entre sociétés | identité de compte sur `User` | valeurs du **dossier RH** sur `Employee` | copie initiale user → employee par société, sans suppression |
| D9 | **Presets Workforce dans le JobDomain** | `jobdomains.workforce_presets` | import planning | non versionné, mélangé au JobDomain | contenu | → **preset Workforce versionné** (même modèle que l'organisation) | extraction |

---

## 5. Responsabilités cibles

Confrontées au code : chaque niveau garde ce qu'il fait déjà bien.

| Niveau | Responsabilité cible | Ne fait plus |
|---|---|---|
| **JobDomain** | contexte métier : libellé, description, terminologie, navigation et page d'accueil, **modules recommandés**, **presets proposés** (références versionnées), overlays marché | posséder rôles, champs, documents, presets Workforce |
| **Preset** (familles distinctes, même cycle de vie) | configuration initiale réutilisable, versionnée, publiée, prévisualisable, appliquée sélectivement, avec provenance par élément : **RBAC** (rôles, bundles, widgets) · **Organisation** (services, postes — existant) · **Exigences** (champs et documents par poste) · **Workforce** (planning, règles) | décider à la place de la Company après application |
| **Module** | capacités (pages, actions, permissions, bundles — existant) et **contributions déclarées** : définitions de champs et types de documents apportés, **exigences proposées**, paramètres | activer des obligations en silence |
| **Catalogues** (Platform) | définitions de champs et types de documents, sensibilité, confidentialité, expiration, marché | porter des obligations (`required_by_*`) |
| **Règles impératives** (Platform / marché) | obligations non désactivables, avec source vérifiée et référence | être déduites d'un JobDomain |
| **Company** | **configuration effective** : champs activés, exigences par poste, personnalisations, exceptions autorisées, demandes et campagnes | — |

Choix assumé : **pas d'objet « Preset universel »**. Les familles restent distinctes (le preset d'organisation fonctionne), mais partagent un même cycle : version · aperçu (nouveau / identique / personnalisé) · application sélective · enregistrement · provenance.

---

## 6. Architecture des champs

Cinq niveaux strictement séparés :

| Niveau | Objet | Existant | Évolution |
|---|---|---|---|
| 1. Catalogue | `FieldDefinition` (Platform ; champs Company si autorisés) | oui | retirer progressivement `required_by_*` et `tags` au profit d'exigences (niveau 3) ; garder `sensitive`, `category`, marché |
| 2. Configuration Company | `FieldActivation` (activé, ordre, groupe) | oui | ajouter **étape** (`hire`, `onboarding`, `profile`) et **provenance** (`source` : preset / module / company, `source_ref`, version) |
| 3. Règles d'obligation | **`Requirement`** (même table que les documents, cf. §7) : cible = champ, applicabilité (poste, contrat, site…), niveau d'obligation | non | nouveau, additif |
| 4. Valeurs | `FieldValue` | oui (`company`, `user`) | ajouter `model_type = employee` pour le **dossier RH** ; `user` garde les données de compte |
| 5. Accès | `sensitive`, `members.sensitive_read`, annuaire / dossier (ADR-787) | oui | ajouter une catégorie de visibilité par champ : annuaire / dossier RH / sensible |

- Aucune nouvelle colonne `Employee` par besoin métier : tout passe par le catalogue.
- Une Company peut créer ses propres définitions si la Platform l'autorise (`FieldDefinition.company_id` existe déjà).
- Le verrou contractuel d'ADR-787 devient naturel : il porte sur les valeurs `employee` d'une société, plus sur le `User` partagé.

---

## 7. Architecture des obligations documentaires

| Notion | Objet |
|---|---|
| Type disponible | `DocumentType` (catalogue, inchangé) |
| Type proposé dans la Company | `DocumentTypeActivation` (inchangé, + provenance) |
| **Obligation** de fournir | `Requirement` (cible = type de document) |
| Personne concernée | applicabilité du `Requirement` résolue sur l'Employee (poste, contrat, site…) |
| Validité, renouvellement | `requires_expiration` du type + paramètres du `Requirement` (délai de renouvellement, rappel) |
| Vérification | `requires_validation` (déjà sur demandes et campagnes) porté par le `Requirement` |
| Confidentialité | catégorie du type (ADR-757/758), inchangée |
| Demande effective | `DocumentRequest` (une active par sujet et type — inchangé) |

**`Requirement`** (table additive unique pour champs et documents) — proposition :

| Colonne | Sens |
|---|---|
| `company_id` | NULL = règle Platform / marché ; sinon Company |
| `target_type`, `target_code` | `field` / `document`, code du catalogue |
| `applicability` (JSON) | `job_role_keys`, `department_ids`, `contract_types`, `site_ids`, `modules`, `markets` ; vide = toute la population |
| `level` | `recommended` · `default` · `configurable_required` · `imperative` |
| `stage` | `hire` (avant le premier jour) · `onboarding` · `ongoing` |
| `source`, `source_ref`, `source_version` | `platform`, `market`, `preset`, `module`, `company` ; clé et version de l'origine |
| `legal_reference`, `verified_at` | obligatoire pour `imperative` |
| `effective_from`, `effective_until`, `superseded_by` | historique |
| `disabled_by_company_at`, `reason` | désactivation tracée (interdite si `imperative`) |

Une obligation **n'est jamais dupliquée** : plusieurs sources = plusieurs lignes `Requirement` pour la même cible, mais **une seule exigence effective** par (personne, cible) et **une seule demande** (dédoublonnage existant par sujet + type).

---

## 8. Contributions des modules

Le manifeste déclare ce qu'il **apporte** (sans rien activer seul) :

```
contributions: {
  fields:     [{ code, definition_ref }],                       // définitions ajoutées au catalogue
  documents:  [{ code, definition_ref }],
  requirements: [{ target: 'document:driving_license', applicability: { job_role_keys: ['driver'] },
                   level: 'default', stage: 'onboarding', auto: false }],
  settings:   [...], automations: [...]                          // existants (settingsPanels, workflows)
}
```

| Contribution | À l'activation | Justification |
|---|---|---|
| Définition de champ / type de document | ajoutée au catalogue, **automatique** | ne crée aucune obligation |
| Permissions / bundles | **automatique** dans le catalogue ; **jamais** ajoutées aux rôles existants sans confirmation | règle ADR-788 |
| Exigence `recommended` / `default` | **proposée** dans un aperçu (« Ce module propose : permis pour les livreurs ») ; appliquée sur confirmation | respect des personnalisations |
| Exigence `imperative` (source vérifiée) | appliquée, affichée avec sa justification | contrainte légale configurée |
| Champs déjà activés / documents déjà exigés | marqués « déjà en place » ; une seconde source est **ajoutée** à l'exigence existante, sans doublon | dédoublonnage |
| Automatisations | proposées, désactivées par défaut | pas d'effet surprise |

Cycle de vie :

| Phase | Effet |
|---|---|
| Installation (Platform) | manifeste et catalogue synchronisés |
| Activation (Company) | aperçu des contributions → application sélective ; enregistrement `ContributionApplication` (module, version, éléments acceptés / refusés) |
| Configuration | la Company ajuste ; chaque modification marque l'élément `customized` |
| Mise à jour (nouvelle version du module) | **aperçu différentiel** : nouveaux éléments proposés ; éléments non personnalisés mis à jour sur confirmation ; personnalisés jamais écrasés |
| Suspension / désactivation | les exigences de source `module` passent **inactives** (`effective_until`) ; **aucune** valeur, aucun document, aucune demande close supprimés ; demandes en cours : laissées, marquées « module inactif » |
| Réactivation | les exigences inactives de la même source sont **restaurées telles que la Company les avait laissées** (y compris personnalisations) ; aperçu limité aux nouveautés |

Billing n'est pas touché : la souscription reste l'affaire du moteur commercial existant ; les contributions suivent l'**activation** (raisons `company_modules`), pas la facture.

---

## 9. Configuration effective et priorités

**Résolveur unique** `EffectiveRequirementResolver::for(Employee|Company, target?)` (remplace la logique des deux résolveurs documentaires et la partie obligatoire du résolveur de champs) :

1. Collecter les `Requirement` actifs à la date (Platform, marché, presets appliqués, modules actifs, Company) applicables à la personne.
2. Calculer le **niveau effectif** par cible : `max(level)` selon l'ordre `recommended < default < configurable_required < imperative`.
3. Appliquer les décisions Company **dans les limites de chaque niveau** :

| Niveau | La Company peut | La Company ne peut pas |
|---|---|---|
| `recommended` | activer ou ignorer | — |
| `default` | désactiver (tracé), modifier étape / délai | — |
| `configurable_required` | ajuster l'applicabilité, le délai, la validation ; désactiver avec motif | supprimer sans trace |
| `imperative` | **exception individuelle autorisée** si la règle le prévoit (ex. dispense justifiée), tracée | désactiver, rendre facultatif |

4. Conserver **toutes les sources** dans le résultat : « Permis exigé — imposé par : module Flotte (v2), poste Livreur (preset logistique v1) ; personnalisé par la Company le 12/03 (délai 15 j) ».
5. Conflits : niveau le plus fort gagne ; à niveau égal, paramètres les plus exigeants (délai le plus court, validation requise) ; une **désactivation Company** ne s'applique qu'aux sources de niveau ≤ `configurable_required` ; les règles `imperative` exigent `legal_reference` et `verified_at`, sinon elles sont rétrogradées en `configurable_required` à la publication (jamais d'obligation légale inventée).
6. Historique : modifications par versions (`effective_from/until`, `superseded_by`) ; la conformité d'une date passée est recalculable.

---

## 10. Personnalisations Company

- Toute valeur appliquée par un preset ou un module porte sa provenance ; une modification Company la marque `customized` (même mécanisme que le preset d'organisation : nouveau / identique / personnalisé).
- Les mises à jour ultérieures ne touchent **jamais** un élément personnalisé : elles le signalent.
- Les désactivations sont des lignes (motif, auteur, date), pas des suppressions.

---

## 11. Applicabilité par poste ou fonction

- **Le rôle RBAC décide de ce que la personne peut faire dans Leezr. Le poste (`JobRole`) décide de ce qu'on attend d'elle.** Les deux sont indépendants : un livreur peut être administrateur de Leezr dans une petite entreprise ; une gestionnaire RH n'a pas besoin de permis.
- Le poste existe (`workforce_job_roles`, avec `preset_item_key` : `driver`, `ops_manager`, `admin_officer`…) et porte désormais les exigences via `Requirement.applicability.job_role_keys`.
- Autres dimensions disponibles sans nouveau modèle : service (`department_id`), type de contrat (`EmploymentContract.contract_type`), site (`WorkLocation`), modules actifs, marché.
- Les étiquettes d'archétype (`driving`, `dispatching`) deviennent des **attributs de poste** dans le preset d'exigences, pas une propriété du rôle.

---

## 12. Cycle de vie des modules

Voir §8. Principe : **l'activation enrichit, la désactivation suspend, rien ne se supprime**, toute écriture est prévisualisée sauf ce qui ne crée aucune obligation.

---

## 13. Dossier candidat (préparation, non implémenté)

Repose entièrement sur la configuration effective :

1. La Company crée une fiche Employee **sans compte** (existant, ADR-772) en choisissant un **poste**.
2. `EffectiveRequirementResolver` calcule les champs et documents d'étape `hire` / `onboarding` → aperçu « dossier de la recrue », ajustable selon les niveaux.
3. Un **jeton d'accès** (aléatoire, haché, lié au seul dossier, expiration courte, révocable, renouvelable) ouvre un formulaire **généré** depuis cette résolution (aucun formulaire codé par JobDomain).
4. Consentement RGPD horodaté avant saisie ; brouillon et reprise ; fichiers par les contrôles DMS existants ; valeurs en `FieldValue(employee)` et documents en DMS (sujet `employee`) — aucun moteur parallèle.
5. Dédoublonnage : une demande par (sujet, type) ; une valeur par (employee, champ).
6. Suivi « reçu / manquant » = conformité par sujet existante ; validation RH par les revues existantes.
7. Finalisation : contrat (verrou ADR-787 ensuite), invitation au compte (ADR-768/769).
8. Traçabilité : envoi, ouvertures, soumissions, validations, révocation dans l'audit Company ; purge des dossiers abandonnés.

---

## 14. Nouvelle organisation Platform

| Écran | Contenu cible | Origine |
|---|---|---|
| **JobDomains** | identité, terminologie, navigation, modules recommandés, presets proposés (références + versions), overlays marché | page actuelle allégée : onglets Champs, Rôles, Documents **retirés** |
| **Presets** (nouvelle section regroupant) | RBAC · Organisation (existant) · Exigences (champs et documents par poste) · Workforce ; versions, statut, publication, aperçu d'impact sur les sociétés | `default_roles`, `workforce_presets`, `default_fields`, `default_documents`, `field_config`, `doc_config` |
| **Catalogues** | champs (existant), types de documents (existant) — sans obligations | inchangé |
| **Règles impératives** | règles Platform / marché avec référence légale et vérification | nouveau (vide tant qu'aucune source vérifiée) |
| **Modules** | manifeste, contributions déclarées, journal des applications par société | page modules existante |
| **Audit des contributions** | qui a appliqué quoi, quelle version, éléments personnalisés | `CompanyPresetSnapshot` + applications |

---

## 15. Nouvelle expérience Company

Une seule page « **Informations et documents demandés** » (Réglages › Travail & RH), avec les composants ADR-782 (pas de cartes empilées) :

- Liste par **poste** (« Livreur », « Responsable d'exploitation », « Administratif ») : informations demandées et documents demandés, état « Obligatoire / Demandé / Facultatif », origine en clair (« Proposé par Leezr pour la livraison », « Ajouté par le module Flotte », « Imposé par la réglementation »).
- Actions courantes : ajouter ou retirer un document pour un poste, rendre obligatoire, choisir l'étape (embauche / intégration).
- Bandeau « **Nouveautés proposées** » quand un module ou un preset propose des ajouts : accepter / ignorer, élément par élément.
- **Aperçu du dossier d'une nouvelle recrue** pour un poste donné.
- Réglages avancés repliés : champs propres à l'entreprise, applicabilité par service / contrat / site, délais de renouvellement.
- Jamais d'identifiant technique, de schéma ni de règle de priorité affichés : seulement l'état et sa justification.

---

## 16. RBAC et confidentialité

- Configurer les exigences : `settings.manage` ou une permission dédiée `requirements.manage` (bundle « Travail & RH »).
- Lire le dossier d'une personne : facette RH (ADR-754), manager direct (ADR-757), jamais l'annuaire (ADR-787).
- Champs sensibles : visibilité par catégorie (annuaire / dossier / sensible) ; NIR, IBAN restent sous `sensitive_read`.
- Documents : catégories ADR-757/758 inchangées.
- Portail candidat : accès par jeton limité au dossier, sans session Company ; aucune donnée d'autres personnes, aucune liste.
- Les presets RBAC ne modifient **jamais** les rôles existants sans aperçu et confirmation (ADR-788).

---

## 17. Compatibilité et transition des données

| Élément existant | Risque | Stratégie |
|---|---|---|
| Sociétés existantes | changement de résolution des obligations | **résolution parallèle** : le nouveau résolveur calcule et compare avec l'ancien (rapport d'écarts) avant bascule, société par société |
| JobDomain `logistique` | — | extraction de ses blocs en presets `*_v1` identiques ; le JobDomain les référence |
| Presets déjà appliqués | aucune provenance enregistrée | enregistrement rétroactif d'application « inconnue » ; tous les éléments considérés **personnalisés** (jamais mis à jour automatiquement) |
| Rôles personnalisés, `field_config` / `doc_config` | perte de configuration | conservés ; convertis en exigences par poste **sur aperçu** (rôle → poste homonyme), jamais automatiquement |
| Valeurs de champs (`user`) | valeurs partagées entre sociétés | copie vers `employee` par société au premier besoin ; `user` conservé ; aucune suppression |
| Documents DMS, versions, demandes en cours, campagnes | — | inchangés ; une demande en cours devient la demande effective de l'exigence correspondante |
| Contrats, signatures, documents générés | — | immuables, non concernés |
| Modules souscrits | contributions jamais appliquées | aperçu « contributions disponibles » au premier passage, rien d'automatique hors catalogue |
| Salariés sans compte | aucun champ | gagnent les champs `employee` |
| Audit | — | nouvelles traces additionnelles |

Aucune migration destructive ; aucune réapplication massive ; aucun droit modifié automatiquement.

---

## 18. Scénarios

| # | Données initialisées | Sources effectives | Automatique | Sur confirmation |
|---|---|---|---|---|
| **A. Société de livraison** | modules recommandés ; preset RBAC v1 (rôles) ; preset organisation (services, postes) ; preset exigences (identité, RIB, NIR pour tous ; permis + catégorie + expiration pour « Livreur ») ; preset Workforce si Workforce actif | JobDomain → presets ; règles impératives marché si vérifiées | à la création : application complète des presets proposés (société vide, rien à écraser) | choix des modules optionnels |
| **B. Autre JobDomain** (ex. nettoyage) | ses presets propres (postes « Agent de propreté », « Chef d'équipe » ; exigences d'habilitations) | idem | idem | idem — **aucun code spécifique** au JobDomain |
| **C. Livreur avec permis** | fiche Employee poste « Livreur » | preset exigences (`default`) + module Flotte (`configurable_required`) → niveau `configurable_required`, deux sources affichées | une demande « Permis » (une seule), champs permis à l'étape intégration | — |
| **D. Administratif sans permis** | poste « Gestionnaire administratif » | aucune exigence permis applicable | rien concernant le permis | — |
| **E. Workforce activé sur une société existante** | module + contributions (champs contrat, documents RH) | module Workforce | catalogue enrichi ; preset organisation **proposé** (aperçu nouveau / existant / personnalisé, existant) | postes, exigences, presets Workforce |
| **F. Module apportant un champ et un document** | définitions ajoutées | module | catalogue | exigences proposées par poste ; les postes déjà configurés manuellement gardent leurs réglages ; si l'exigence existait déjà, la source module s'ajoute sans nouvelle demande |
| **G. Personnalisation Company** | ex. ajouter « attestation de formation » pour les livreurs, rendre facultatif le médical | Company + sources existantes | — | modifications directes (niveaux ≤ `configurable_required`), tracées ; impossible sur `imperative` |
| **H. Mise à jour d'un preset appliqué** | preset exigences v2 (ajout « carte conducteur ») | preset v1 appliqué | aucune | aperçu différentiel : ajout proposé ; éléments non personnalisés mis à jour sur accord ; personnalisés jamais touchés |
| **I. Désactivation puis réactivation** | exigences source module | — | désactivation : exigences module inactives, valeurs / documents / demandes conservés ; réactivation : exigences restaurées telles que laissées | nouveautés de version seulement |
| **J. Recrutement avec lien** | Employee sans compte, poste choisi | résolution effective étape `hire` | jeton, formulaire généré, demandes dédoublonnées, suivi | personnalisation du dossier avant envoi ; validation RH ; finalisation |

---

## 19. Services et données proposés

| Élément | Type | Note |
|---|---|---|
| `requirements` | table additive | §7 |
| `preset_applications` | table additive | généralise `organization_preset_applications` (famille, clé, version, éléments, auteur) |
| colonnes de provenance (`source`, `source_ref`, `source_version`, `customized_at`) sur `field_activations`, `document_type_activations` | colonnes additives nullables | NULL = historique (considéré personnalisé) |
| `FieldValue.model_type = employee` | valeur supplémentaire, sans changement de schéma | §6 |
| `ModuleManifest::$contributions` | propriété | §8 |
| `EffectiveRequirementResolver` | service Core | §9 ; seul point de décision |
| `PresetPreviewService` / `PresetApplicationService` | services | généralisent `OrganizationPresetService::preview/apply` |
| `ModuleContributionService` | service | aperçu et application des contributions |
| `RequirementRequestPlanner` | service | transforme les exigences effectives en `DocumentRequest` via `DocumentRequestService::findExisting` (aucun doublon) |

Pas de moteur de règles générique : l'applicabilité reste une liste de dimensions connues, extensible.

---

## 20. Plan de transition par lots

| Lot | Contenu | Dépendances | Risques | Critères de validation |
|---|---|---|---|---|
| **L0 — Correctif de cohérence** | conformité alignée sur la fiche pour les règles **existantes** (D5) — sans nouveau modèle | — | changement des taux de conformité affichés | tests : livreur avec permis exigé par rôle apparaît en conformité ; aucun document exigé à tort au propriétaire ; rapport avant / après |
| **L1 — Exigences et provenance** | table `requirements`, provenance des activations, `EffectiveRequirementResolver` en **lecture parallèle** (rapport d'écarts, aucune bascule) | L0 | écarts inattendus | 0 écart non expliqué sur les sociétés DEV |
| **L2 — Exigences par poste** | `applicability.job_role_keys`, page Company « Informations et documents demandés », aperçu du dossier par poste ; conversion assistée `doc_config` / `field_config` → poste | L1 | confusion transitoire rôle / poste | recette : livreur ≠ administratif ; aucune perte de configuration |
| **L3 — Presets versionnés** | extraction de `default_roles`, `default_fields`, `default_documents`, `workforce_presets` en presets `*_v1` ; JobDomain allégé ; `preset_applications` ; aperçu différentiel | L1 | divergence entre registre et base | sociétés existantes inchangées ; nouvelle société identique à aujourd'hui |
| **L4 — Contributions des modules** | `contributions` dans les manifestes, aperçu à l'activation, cycle désactivation / réactivation | L1, L3 | effets sur les sociétés abonnées | scénarios E, F, I verts ; aucune suppression |
| **L5 — Dossier RH sur Employee** | `FieldValue(employee)`, copie user → employee par société, verrou ADR-787 recentré | L1 | double saisie transitoire | salarié sans compte renseignable ; valeurs historiques intactes |
| **L6 — Dossier candidat** | jeton, formulaire généré, consentement, validation | L2, L5 | sécurité du lien | scénario J ; tests d'accès et d'expiration |
| **L7 — Dépréciation** | onglets JobDomain retirés, `required_by_*` et étiquettes ignorés à la résolution (gardés en données), `BatchRequestByRoleUseCase` redirigé | L2–L4 | régressions d'écrans | aucune référence restante aux anciennes sources dans la résolution |

Ordre recommandé : **L0 → L1 → L2** apportent l'essentiel de la valeur (bonnes exigences par poste, une seule résolution) sans toucher aux presets ni aux modules.

---

## 21. Alternatives et compromis

| Alternative | Pourquoi non retenue |
|---|---|
| Garder les exigences sur le rôle RBAC | mélange droits et activité ; un changement de droits modifie les obligations |
| Un « Preset universel » unique | objet trop large ; le preset d'organisation prouve qu'une famille ciblée avec un cycle commun suffit |
| Moteur de règles générique (expressions) | coût, opacité pour la Company, risque d'erreurs ; les dimensions connues suffisent |
| Recalcul automatique de toutes les sociétés à chaque version | contraire à « jamais d'écrasement silencieux » |
| Formulaire candidat par JobDomain | duplication à chaque nouveau métier |

---

## 22. Benchmark (non vérifié en ligne pendant cet audit)

Aucune recherche externe n'a été effectuée : les éléments ci-dessous relèvent de connaissances générales sur des pratiques publiques connues, à vérifier avant toute décision qui s'y appuierait.

- Séparation **définition / disposition / obligation contextuelle** : modèle courant des CRM configurables (champs, mises en page, règles par type d'enregistrement).
- **Données de modules marquées « non mises à jour »** après installation : pratique connue des ERP modulaires pour ne pas écraser les personnalisations — même intention que le statut « personnalisé » proposé.
- **Onboarding par modèle de poste** avec listes de tâches et documents générées : pratique répandue des SIRH.
- Analyse propre à Leezr : reprendre le cycle déjà éprouvé du preset d'organisation (aperçu, application sélective, provenance) plutôt qu'importer un modèle externe.

---

## 23. Checkpoint

Audit terminé, aucun code ni donnée modifiés. Décisions attendues : validation des responsabilités cibles (§5), du modèle `Requirement` (§7), du principe « poste ≠ rôle » (§11), et GO sur le lot **L0** (correctif de cohérence de la conformité).
