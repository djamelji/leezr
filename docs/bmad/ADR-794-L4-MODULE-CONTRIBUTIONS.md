# ADR-794 — L4 : contributions des modules (champs, documents, exigences proposées)

**Date** : 2026-11-15 · **Base** : `9d1bf431` · **Environnement** : DEV local uniquement · **Statut** : livré, checkpoint L4 (L5 non commencé)

Séparation appliquée :

| Étape | Signification | L4 |
|---|---|---|
| A. Contribution au catalogue | une définition devient disponible | ✅ implémenté |
| B. Proposition d'exigence | le module recommande | ✅ implémenté |
| C. Adoption Company | l'entreprise confirme une obligation configurable | ✅ préparé (registre L1, mode parallèle) |
| D. Application opérationnelle | DMS / Workforce l'utilisent | ❌ non réalisé (ADR-790 reste autoritaire) |

## 1. Mécanismes existants réutilisés

| Besoin | Réutilisé | Ajout L4 |
|---|---|---|
| Déclaration de module | `ModuleManifest` / `ModuleRegistry` | paramètre optionnel `contributions` (défaut `[]`) |
| Champs | `FieldDefinition` global (`FieldDefinitionCatalog`) | création **unique** d'une définition manquante, jamais modifiée |
| Documents | `DocumentType` système (`DocumentTypeCatalog`) | idem |
| Visibilité opérationnelle | `FieldActivation` / `DocumentTypeActivation` (par société) | **aucune activation créée** : un ajout au catalogue reste invisible dans les fiches |
| Exigences | registre L1 `requirements` + `EffectiveRequirementResolver` (R1 : copie `module` suspendue si module inactif ; R3 fusion des sources) | modèles de proposition `is_template` |
| Configuration Company | page et service ADR-792 | section « Proposé par le module … » + personnalisation des copies |
| Versions / mise à jour | logique modèle → copie `derived_from` des presets ADR-793 | même modèle, appliqué aux modules |
| Cycle de vie module | `EntitlementResolver` (droit), `CompanyModuleActivationReason` (activation), abonnement add-on (suspension) | classification lecture seule, Billing non modifié |
| Confidentialité | `DocumentConfidentiality`, règle `sensitive` des champs | signalement « donnée sensible » |

Aucun second catalogue, aucun second moteur d'exigences.

## 2. Format des contributions (`ModuleManifest::$contributions`)

```php
contributions: [
  'version' => 2,                                     // entier, à augmenter dès qu'un élément change
  'fields' => [
    ['ref' => 'hire_date'],                           // définition canonique existante
    ['ref' => 'badge_number', 'definition' => [       // nouvelle définition
      'label' => 'Badge number', 'translations' => ['fr' => '…', 'en' => '…'],
      'type' => 'string', 'category' => 'hr', 'sensitive' => false,
      'validation' => ['max' => 30], 'options' => null, 'applicable_markets' => ['FR']]],
  ],
  'documents' => [
    ['ref' => 'rib'],
    ['ref' => 'safety_training', 'definition' => [
      'label' => '…', 'translations' => [...], 'category' => 'training',
      'requires_expiration' => true, 'default_validity_months' => 24, 'renewable' => true,
      'confidentiality' => 'confidential', 'accepted_types' => ['pdf'], 'max_file_size_mb' => 10]],
  ],
  'requirements' => [                                 // PROPOSITIONS uniquement
    ['target' => 'document:rib', 'level' => 'configurable_required',
     'job_role_keys' => ['driver'], 'contract_types' => ['CDI'], 'stage' => 'hire',
     'reason' => ['fr' => 'Justification fonctionnelle', 'en' => '…']],
  ],
]
```

Validation (`ModuleContributionSchema`) :
- références stables (`[a-z][a-z0-9_]`) ;
- types de champ pris en charge, catégories, sensibilité, confidentialité, durée de validité ;
- une proposition vise un élément déclaré par **le même** module ;
- niveau `recommended | default | configurable_required` — **`imperative` refusé** (un module n'est pas une autorité légale) ;
- justification fonctionnelle obligatoire en fr et en ;
- règles opérationnelles historiques (`required_by_modules`, `required_by_jobdomains`, `required`) interdites dans une définition ;
- doublons refusés.

Un manifeste invalide est rejeté **en entier** pour ce module.

Contributions réelles déclarées :
- **Workforce** : date d'embauche (`default`), type de contrat (`default`), carte d'identité (`configurable_required`), IBAN (`recommended`) ;
- **Paie** : IBAN, NIR et RIB (`configurable_required`).

Toutes reprennent des définitions existantes : aucune définition n'a été créée en DEV.

## 3. Définitions (A)

`ModuleContributionCatalog::sync()` est déclenché par la commande `modules:sync-contributions [--dry-run]` et par `SystemSeeder`. Selon le cas :

| Cas | Résultat |
|---|---|
| Code existant et compatible | **lié** ; jamais modifié |
| Code existant avec type ou portée différents | **collision** |
| Code appartenant à une société | **collision** |
| Référence inconnue sans définition | **collision** |
| Code absent avec définition | **créé une fois** (`is_system`, `contributed_by_module`, sans activation société) |
| Même libellé, autre code | **homonyme signalé** (`homonym_of:…`), jamais fusionné |

## 4. Propositions (B)

Une proposition est un **modèle** du registre :
- `company_id` null, `is_template = true`, `source = module`, `source_ref` = module, `source_version` = version où son contenu a changé ;
- sa référence est conservée dans `reason = proposal:{ref}`.

Le résolveur L1 traite désormais **tout** modèle (preset ou module) comme non applicable par lui-même (R1). Les lignes `module` sans société restent réservées à la projection historique `required_by_modules` du comparateur L1 : elles ne sont jamais persistées par L4.

## 5. Provenance et versions

- **Provenance** : table `module_contributions`, une ligne par (module, type, référence). Elle porte la version, l'empreinte du contenu, la définition liée et le statut (`active`, `withdrawn`, `collision`).
- **Contenu modifié sans nouvelle version** : refusé (`version_not_bumped`), l'ancien état est conservé.
- **Nouvelle version** : l'ancien modèle est remplacé et conservé, et un nouveau modèle est créé. Les copies des sociétés ne sont jamais modifiées.
- **Élément retiré du manifeste** : marqué `withdrawn` et son modèle est remplacé. Définitions et copies sont conservées.
- **Décisions Company** : journal `module_contribution_decisions` (`adopted`, `updated`, `declined`, `kept_customization`, `replaced_customization`) et audit `module_contributions.decided`.

## 6. Antidoublons

- **Une définition canonique** par code stable. Plusieurs modules peuvent pointer vers elle, avec une provenance par module (ex. RIB et IBAN proposés par Workforce et par la Paie).
- **Pas de fusion par libellé** : un homonyme est signalé, jamais fusionné.
- **Une seule exigence effective** par cible et population : le résolveur fusionne les sources (R3) et garde chaque contribution, le niveau le plus fort l'emportant.
- **Aucun type documentaire dupliqué**.
- **Adoption** déjà faite, ou exigence déjà demandée par l'entreprise : `already_configured`, aucune copie supplémentaire.

## 7. Cycle de vie module (lecture seule, Billing intact)

| État | Règle |
|---|---|
| `active` | droit réel et raison d'activation (module cœur : toujours actif) |
| `available` | droit réel, sans activation, sans adoption antérieure |
| `deactivated` | droit réel, sans activation, avec des décisions antérieures |
| `suspended` | abonnement add-on désactivé (`addon_inactive`) |
| `not_authorized` | aucun droit (offre, JobDomain) |
| `platform_disabled` | module coupé globalement |
| `ambiguous` | drapeau historique `is_enabled_for_company` sans raison d'activation, ou activation sans droit |

**Seul `active` permet une adoption.** En DEV, 41 couples société × module sont dans le cas « drapeau sans raison » : ils sont classés `ambiguous` et aucune obligation n'est introduite.

Le chemin d'activation existant (`CompanyModuleController`, `ModuleActivationEngine`) n'est **pas modifié**. Les propositions se découvrent à la demande, dans la page de configuration.

## 8. Aperçu et adoption Company

`GET /workforce/requirements/modules` est calculé côté serveur et n'écrit rien. Pour chaque module, il renvoie :
- l'état du module ;
- les définitions apportées, avec leur statut et la mention « donnée sensible » ;
- les propositions ;
- en cas de module inactif, ce qui est suspendu.

| Statut d'une définition | Sens |
|---|---|
| `new` | nouvelle pour l'entreprise |
| `already_available` | déjà disponible dans l'entreprise |
| `already_configured` | déjà configurée |
| `conflict` | en conflit |

| Statut d'une proposition | Sens |
|---|---|
| `optional` | facultative |
| `proposed_required` | obligatoire configurable proposée |
| `already_configured` | déjà configurée |
| `conflict` | dispense de l'entreprise |
| `not_applicable` | module non actif ou poste absent |
| `requires_confirmation` | nouvelle version à confirmer |

Chaque proposition indique aussi : personnalisation, décision antérieure, différences de version, population concernée, justification. Aucun JSON ni identifiant interne n'est affiché.

- `POST …/modules/{module}/preview` exécute le même code dans une transaction annulée. Il renvoie les changements et, pour chaque proposition, les personnes concernées (avec et sans compte).
- `POST …/modules/{module}/decisions` traite `adopt`, `decline`, `keep` et `replace`.
  - Une adoption crée une copie société (`source module`, `derived_from` = modèle) **par poste** correspondant, ou une seule pour tout le monde. Chaque copie reste ainsi modifiable dans la page L2.
  - Le verrou société est pris **avant toute lecture** (voir §13).

## 9. Intégration à ADR-792

Tout se passe dans la même page « Réglages › Travail & RH › Informations & documents demandés ». Une nouvelle section par module, « Proposé par le module … », est composée avec `AppSettingsGroup`, `AppStatusText` et `AppDialog` (design system ADR-782). Il n'y a pas de seconde page de gestion des exigences.

Provenance affichée dans la liste des exigences :

| Origine | Libellé |
|---|---|
| Créée par l'entreprise | « Votre entreprise · … » |
| Issue d'un preset | « Proposé par la configuration initiale Leezr » |
| Issue d'un module | « Apporté par le module X » |
| Personnalisée | « Personnalisée par votre entreprise (proposée par X) » |
| Impérative vérifiée | « Obligation légale vérifiée » |

## 10. Personnalisations

Une modification faite en L2 sur une copie adoptée (module ou preset) produit une **ligne Company** qui garde son modèle d'origine (`derived_from`, `customized_at`). La copie est remplacée et reste dans l'historique ; un retrait désactive la copie.

À la version suivante du module :
- la personnalisation est **conservée** ;
- la proposition passe à `requires_confirmation`, avec les différences (niveau actuel → niveau proposé) ;
- l'entreprise choisit explicitement :
  - `keep` : la personnalisation reste, et la décision est tracée pour cette version ;
  - `replace` : la personnalisation est remplacée et conservée dans l'historique, et une nouvelle copie est créée.

Rien n'est jamais réinitialisé silencieusement.

## 11. Désactivation et réactivation

Aucune écriture ni aucun crochet dans le cycle de vie : le résolveur L1 suspend lui-même les copies d'un module inactif (`module_inactive`).

| Situation | Résultat |
|---|---|
| Valeurs, documents, demandes, signatures, contrats, définitions, décisions | intacts |
| Exigence partagée avec un autre module actif | maintenue (`kept_other_source`) |
| Exigence personnalisée par l'entreprise | maintenue (`kept_company_decision`) |
| Exigence propre au module | suspendue |
| Réactivation | les mêmes lignes redeviennent applicables, sans doublon ; les nouveautés éventuelles sont proposées |

## 12. Sécurité et RBAC

- **Définitions** : seule la Platform les contrôle (manifestes et commande de synchronisation). Aucune route Company ne crée de définition.
- **Lecture** : `settings.manage` ou `documents.configure`, avec module Workforce actif.
- **Décision** : `settings.manage` pour un champ, `documents.configure` pour un document, contrôlé proposition par proposition (403 sinon).
- **Isolation** : toutes les lectures et écritures sont filtrées par société. Un utilisateur d'une autre société obtient 403. Aucune ligne n'est écrite chez une autre société.
- **Catalogue ≠ valeur** : l'API ne renvoie jamais de valeur (testé avec un IBAN réel), ni `payload`, ni empreinte.
- **Aucun effet de bord** : aucune permission ni rôle touché, aucune activation de module, aucun contournement de souscription (adoption limitée à `active`).

## 13. Tests et mesures

- **`Adr794ModuleContributionsTest`** : 12 tests, 149 assertions, couvrant les 28 scénarios :

| Scénarios | Couverture |
|---|---|
| 1, 2, 6 + schéma | champ et document créés une fois, collision, homonyme, règles opérationnelles et impératif refusés |
| 3, 4, 25, 26, 27 | propositions sans effet avant adoption, aperçu sans écriture, fiches / conformité / demandes / activations identiques, 0 demande DMS |
| 5, 21 | deux modules sur le RIB : une définition, deux provenances, une exigence ; rien chez B |
| 7 | champ déjà activé, exigence déjà demandée |
| 8, 9, 10 | personnalisation conservée, contenu modifié sans nouvelle version refusé, v2 `keep`, v3 `replace`, journal et audit |
| 11, 12, 13 | désactivation (suspension seulement, exigence partagée maintenue, aucune suppression), réactivation sans doublon |
| 13, 14, 15, ambigu | suspendu, souscrit non activé, non autorisé, drapeau ambigu, adoption refusée (422) |
| 16 à 19 | avec / sans compte, avec / sans poste, une copie par poste |
| 20 | autre JobDomain : non autorisé, poste absent : non applicable |
| 22 | impératif vérifié verrouillé, la copie du module fusionne dessous |
| 23 | synchronisation et adoption rejouées sans doublon, refus tracé |
| 24 | concurrence MySQL (voir plus bas) |
| 28 + RBAC | aucune fuite de valeur sensible, permissions par section, isolation |

- **Tests liés** : registre L1, page L2, presets L3, modules, garde-fous — 100 réussis, plus les 3 échecs historiques `ControllerSizeInvariantTest`. Le nouveau contrôleur fait 56 lignes et n'est pas en cause.
- **E2E en lecture seule** (`tests/e2e/adr794-module-proposals.spec.js`) : 1/1, sans aucune décision enregistrée. Captures dans `docs/bmad/adr794/` (ordinateur, aperçu, mobile).
- **Concurrence MySQL** (base jetable `leezr_adr794_conc` clonée du schéma DEV, supprimée ensuite) :
  - 6 synchronisations parallèles : 16 contributions, 7 modèles, 0 doublon.
  - 10 adoptions parallèles, **avant correctif** : 18 copies. Le verrou était pris après une première lecture, et sous REPEATABLE READ l'instantané était déjà figé.
  - 10 adoptions parallèles, **après correctif** (verrou en première instruction) : 1 adoption, 9 « sans changement », 2 copies.
  - 8 enregistrements L2 parallèles : 1 ligne, sans défaut.
- **Mesures** sur la société 1 de DEV (52 salariés, 2 modules contributeurs) :

| Opération | Temps | Requêtes |
|---|---|---|
| Vue d'ensemble | 7,8 ms | 22 |
| Aperçu d'adoption | 6 ms | 20 |
| Synchronisation à blanc | 11,6 ms | 74 |

  Le nombre de requêtes de la vue d'ensemble ne varie pas avec le nombre de salariés (test : +60 salariés, même nombre). Il n'y a ni N+1 ni résolution par salarié pour afficher les propositions.
- **Suite complète** (une exécution) : 4427 réussis, 12 ignorés, 3 échecs = dette historique `ControllerSizeInvariantTest` ; aucun autre échec.

## 14. Compatibilité historique

- **Migration additive** `2026_11_24_000001` :
  - tables `module_contributions` et `module_contribution_decisions` ;
  - colonne `requirements.is_template`, par défaut `false`.
- **DEV après synchronisation** :
  - 16 contributions et 7 modèles ;
  - **0 ligne société**, aucune définition créée ;
  - activations de champs (275) et de documents (38) inchangées.
- **Rien d'autre n'est modifié** : JobDomains, rôles, postes, presets, matricules, demandes, campagnes, contrats, signatures, paie et Billing intacts. Les configurations historiques ambiguës (ADR-791/792) ne sont pas corrigées.
- **L2** : son comportement est étendu uniquement pour les copies adoptées (personnalisation et retrait). Ses tests restent verts.

## 15. Limites restantes

- La présentation contextuelle dans le catalogue Modules n'est pas faite (option du brief) : la découverte passe par la page de configuration.
- La confidentialité *déclarée* d'un nouveau type documentaire est enregistrée (`declared_confidentiality`) mais non appliquée par le DMS : `DocumentConfidentiality` reste fondé sur le code.
- Une copie limitée par type de contrat est vue comme « tous les postes » dans la page L2, qui ne raisonne pas encore par contrat.
- Une proposition limitée à des postes ne couvre pas automatiquement un poste créé après l'adoption : il faut une nouvelle adoption ou une configuration L2.
- La découverte reste dans la section Workforce. Un module contributeur sans Workforce actif n'a pas encore d'écran.
- Aucun nouveau module n'est mis en avant à l'activation (pas de notification), conformément à l'interdiction de toucher le cycle d'activation.

## 16. Conditions de bascule (D)

1. Postes qualifiés : 45 salariés sur 52 n'ont pas de poste dans la société 1 (ADR-793).
2. Configurations historiques ambiguës résolues par un humain (ADR-791/792).
3. États de module `ambiguous` clarifiés : 41 couples en DEV.
4. Parité démontrée entre résolveur L1 et ADR-790 sur les sociétés réelles.
5. Bascule par société, réversible, avec aperçu des demandes DMS qui seraient générées, sans les générer.

## 17. Préparation de L5

- Point d'entrée unique pour la bascule : `EffectiveRequirementResolver` (lignes adoptées et sources fusionnées).
- Modèles, copies, personnalisations et décisions tracés : prêts pour un rapport de parité par société.
- À traiter en L5 : écran pour les modules hors Workforce, présentation dans le catalogue Modules, couverture des nouveaux postes, prise en compte du type de contrat dans L2.

## 18. Résultat et SHA

- **Suite complète** (une exécution) : 4427 réussis, 12 ignorés, 3 échecs historiques.
- **Build** : propre.
- **Commit** : `git log --grep "ADR-794"` sur `dev`.
