# ADR-791 — L1 : registre des exigences, résolution parallèle, traçabilité

> Base `cfd1d669` · DEV uniquement · **aucun comportement visible modifié** : la résolution opérationnelle reste `DocumentResolverService::requirementMap` (ADR-790) et `FieldResolverService`. Aucun écran, demande, formulaire, notification, campagne ni permission ne lit le nouveau moteur (vérifié : seul `requirements:compare` l'appelle).
> Tests : `tests/Feature/Adr791RequirementRegistryTest.php`.

## 1. Architecture implémentée

| Élément | Fichier | Rôle |
|---|---|---|
| Registre | table `requirements` (migration `2026_11_22_000001`, additive) + `App\Core\Requirements\Requirement` | une **obligation** (ni définition, ni valeur, ni permission) |
| Niveaux | `RequirementLevel` | recommandé < par défaut < obligatoire configurable < impératif |
| Résolveur parallèle | `EffectiveRequirementResolver` | exigences effectives d'une personne, avec explication |
| Comparateur | `RequirementParityComparator` | historique ↔ cible, écarts classés |
| Commande | `requirements:compare [--company=] [--samples]` | rapport en lecture seule (transaction toujours annulée) |

Définitions = catalogues existants (`FieldDefinitionCatalog`, `DocumentTypeCatalog`), réutilisés par clé étrangère. Valeurs = `field_values` / DMS. Accès = permissions et `DocumentAccessPolicy`. Le registre ne duplique rien de cela.

## 2. Modèle des données

| Colonne | Sens |
|---|---|
| `company_id` | NULL = Platform / marché / JobDomain / module / **modèle** de preset ; sinon la Company |
| `target_kind` + `field_definition_id` **ou** `document_type_id` | cible explicite ; deux clés étrangères plutôt qu'une colonne polymorphe (intégrité référentielle réelle) |
| `effect` | `require` ou `waive` (dispense Company) |
| `level` | niveau (obligatoire pour `require`, absent pour `waive`) |
| `applicability` (JSON) | `job_role_keys`, `job_role_ids`, `department_ids`, `contract_types`, `markets` ; vide = toute la population |
| `stage` | `hire`, `onboarding`, `ongoing` (préparé pour L2/L6) |
| `source`, `source_ref`, `source_version` | provenance et version |
| `derived_from_id` | copie Company d'un modèle (preset, module) |
| `legal_reference`, `verified_at` | autorité d'une exigence impérative |
| `status`, `effective_from`, `effective_until` | cycle de vie et historique |
| `customized_at`, `reason`, `created_by` | personnalisation et motif |

**Table commune champs + documents : retenue.** Le risque d'ambiguïté polymorphe est évité par deux clés étrangères nullables. Invariants garantis à chaque enregistrement (`Requirement::assertValid`, exception métier) et testés : exactement une cible cohérente avec `target_kind` ; dispense uniquement Company et sans niveau ; `require` toujours avec un niveau valide ; **impératif seulement avec `legal_reference` + `verified_at`, et jamais depuis un JobDomain, un preset ni la Company** ; source Company rattachée à une société. Contraintes CHECK SQL non posées (non portables entre MySQL et la base de test SQLite) : garde applicative unique, documentée.

Réversibilité : `down()` supprime uniquement cette table (testé : aucune autre donnée touchée). Migration exécutée en DEV local (table vide) ; tests sur base SQLite isolée.

## 3. Résolveur parallèle

`EffectiveRequirementResolver::forCompany($company)` charge en une fois les lignes de la société et les lignes partagées ; `subjects()` charge en une fois la population (fiches salarié avec ou sans compte, comptes sans fiche) ; `resolveFor($subject)` résout en mémoire.

Applicabilité = **contexte métier seulement** : poste (`JobRole`, clé de preset ou identifiant), service, type de contrat courant, marché, modules actifs (`ModuleGate`). **Jamais le rôle RBAC.** Une personne sans poste ne reçoit aucune exigence ciblée par poste (aucun poste inventé). Les pièces de l'entreprise (K-bis, assurance) ne sont jamais exigées d'une personne.

## 4. Règles de priorité

| Règle | Énoncé |
|---|---|
| R1 | seules les lignes actives dans leur fenêtre comptent ; un module seulement s'il est actif ; un modèle de preset sans Company n'a **aucun effet** tant qu'il n'est pas copié ; JobDomain et marché seulement ceux de la société |
| R2 | l'applicabilité doit correspondre à la personne |
| R3 | le niveau le plus fort l'emporte ; plusieurs sources = **une seule exigence**, toutes conservées dans `contributions` |
| R4 | à niveau égal : platform > market > company > module > preset > jobdomain, puis identifiant |
| R5 | une dispense Company neutralise jusqu'à « obligatoire configurable », **jamais** « impératif » (dispense signalée « écartée ») |
| R6 | exigé = niveau ≥ « par défaut » et non dispensé ; « recommandé » = suggestion |
| R7 | copie d'un modèle dont le modèle a une version supérieure → `update_available` (confirmation requise, jamais appliquée d'office) ; copie personnalisée → jamais mise à jour |

Cas A à H : A (Company exige, rôle masque) → exigé, le rôle n'est pas une source, écart classé « arbitrage produit » par le comparateur · B (recommandation JobDomain dispensée) → dispensée, tracée · C (deux modules) → une exigence, deux contributions · D (impératif contre dispense) → impératif l'emporte · E (personnalisation d'un preset) → conservée, pas de mise à jour · F (module suspendu) → contribution `module_inactive`, rien supprimé · G (réactivé) → restaurée telle quelle · H (nouvelle version) → `update_available` avec confirmation.

## 5. Provenance et justification

Chaque résultat donne : `required`, `level`, `waived`, la règle **retenue** (source, référence, version, niveau), **toutes** les contributions avec leur statut (`applicable`, `not_applicable`, `module_inactive`, `other_jobdomain`, `other_market`, `superseded`, `inactive`, `out_of_window`) et leur décision (`retained`, `merged`, `waived_by_company`, `overridden_by_imperative`), `customizable` et `update_available`. Un document proposé trois fois = une exigence et trois provenances.

## 6. Salariés sans compte

Sujet = la fiche (`employee:ID`) ; même résolution que pour un salarié avec compte (sujet canonique `user:ID`, ADR-761), par poste. Rien n'est rattaché à l'adhésion ni au rôle. Le stockage des valeurs de champs sur la fiche reste en L5.

## 7. Comparateur

Le registre est vide jusqu'à L2 : le comparateur résout une **projection en mémoire** (rien n'est enregistré) de la configuration historique représentable — `required_override` des activations (source company), `required_by_modules` (source module), `required_by_jobdomains` des champs (source jobdomain). Les règles portées par le rôle RBAC (`doc_config`, `field_config`, étiquettes) ne sont pas projetées et sont listées comme non représentables.

Classement des écarts : **différence attendue** (exigé par les étiquettes du rôle) · **configuration manquante** (exigé par la configuration du rôle → à recréer comme exigence de poste en L2) · **donnée historique ambiguë** (module marqué actif sans raison d'activation ; champs d'un salarié sans compte) · **arbitrage produit** (exigé par la Company, masqué par le rôle) · **bug de résolution** (aucune cause identifiée). Le comparateur a détecté un vrai défaut pendant le développement (pièces de l'entreprise appliquées aux personnes), corrigé avant livraison.

## 8. Rapport d'écarts sur données DEV (lecture seule)

| Société | Sujets (sans compte) | Exigences historiques / cibles | Concordances | Écarts | Attendus | Config. manquante | Ambigus | Arbitrage | Bugs |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 58 (36) | 368 / 232 | 156 | 288 | 9 | 131 | 144 | 4 | **0** |
| 2 | 2 (0) | 10 / 6 | 6 | 4 | 0 | 4 | 0 | 0 | 0 |
| 3 | 1 (0) | 5 / 3 | 3 | 2 | 0 | 2 | 0 | 0 | 0 |
| 31 / 32 / 33 / 34 | 6 / 26 / 51 / 101 | 8/18 · 28/78 · 53/153 · 103/303 | 8 · 28 · 53 · 103 | 10 · 50 · 100 · 200 | 0 | 0 | tous | 0 | 0 |
| autres | 0 à 4 | 0 | — | 0 | — | — | — | — | 0 |

Société 1 par population :
- **12 livreurs** : certificat médical + 9 champs exigés par la configuration du rôle « driver » → configuration manquante (exigence de poste « Livreur » à créer) ; champs permis exigés via le module `logistics_fleet` → ambigu (module marqué actif dans `company_modules` **sans raison d'activation**, inactif pour `ModuleGate`) ; permis : concordant (réglage Company).
- **Propriétaire et 3 dispatchers** : permis exigé par la Company mais masqué par leur rôle → **arbitrage produit** (cas A) ; champs exigés par la configuration du rôle → configuration manquante ; champs exigés par l'étiquette « dispatching » → différence attendue.
- **36 salariés sans compte** : documents concordants ; `hire_date` et `employee_status` exigés par le JobDomain (catalogue) mais sans historique comparable (valeurs sur le compte) → ambigu.
- **Rôles de recette** : champs permis via le module ambigu.
- Sociétés 31-34 : champs exigés par le JobDomain pour les salariés sans compte → ambigus.
- 5 rôles avec règles non représentables (dispatcher, driver, manager, ops_manager, owner : 3 règles documentaires, 20 règles de champs, une étiquette chacun).

Aucun réglage modifié, aucune donnée corrigée.

## 9. Performance

| Mesure | Résultat |
|---|---|
| Résolveur, 303 salariés (test) | **≤ 10 requêtes** au total (indépendant du nombre de personnes), < 5 s ; exact : 303 pièces d'identité + 152 permis |
| Comparateur, société 34 (101 sujets) | 61 requêtes, 32 ms |
| Comparateur, société 1 (58 sujets) | 219 requêtes, 108 ms — le surplus vient du **côté historique** (`FieldResolverService` par compte), pas du résolveur |
| Cache | aucun (pas d'invalidation à maintenir) ; mémoïsation limitée à l'instance (activité des modules) |

## 10. Tests

`Adr791RequirementRegistryTest` (13 tests, 53 assertions) couvre les 18 scénarios :
1 Company seule · 2 recommandation JobDomain (et JobDomain étranger ignoré) · 3 contribution module · 4 personnalisation d'une copie de preset · 5 conflit de sources · 6 impératif justifié (l'emporte sur une dispense) · 7 impératif non justifié refusé (et JobDomain refusé comme autorité) · 8 salarié avec compte · 9 sans compte · 10 deux postes (et aucun poste inventé) · 11 module désactivé · 12 réactivé · 13 plusieurs sociétés (lignes et personnes d'une autre société jamais lues, même fournies) · 14 même document par deux modules · 15 historique contradictoire → arbitrage produit, 0 bug · 16 modèle de preset non appliqué sans effet · 17 nouvelle version de contribution (ancienne `superseded`) · 18 300 salariés en requêtes constantes.
Plus : invariants (6 cas refusés), absence d'effet de bord (registre, demandes, notifications, activations inchangés), réversibilité de la migration.

Suite complète : **4396 réussis**, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest` (inchangés). Aucun fichier d'interface modifié : pas de build ni de recette navigateur.

## 11. Limites

1. Le registre est vide : aucune exigence réelle n'y est encore saisie (L2/L3).
2. Les règles portées par le rôle RBAC ne sont pas représentables (par décision d'architecture).
3. La résolution d'un sujet « entreprise » (pièces de l'entreprise) n'est pas encore exposée : seules les personnes sont résolues.
4. Pas de contrainte CHECK en base (garde applicative unique).
5. Le niveau « par défaut » est traité comme exigé ; son sens « activé mais désactivable sans motif » sera matérialisé par l'interface (L2).

## 12. Conditions de bascule future

Pour basculer une vue vers le nouveau moteur : 0 bug de résolution sur toutes les sociétés ; écarts « configuration manquante » résorbés (exigences de poste créées) ; arbitrages produit tranchés (ex. permis du propriétaire et des dispatchers dans la société 1) ; drapeaux de modules ambigus corrigés ou explicitement acceptés ; valeurs de champs des salariés sans compte disponibles (L5) ; rapport de parité validé société par société.

## 13. Préparation de L2

- Saisie des exigences par poste dans `requirements` (`applicability.job_role_keys`), à partir des écarts « configuration manquante » du rapport (aperçu, jamais automatique).
- Page Company « Informations et documents demandés » lisant le résolveur parallèle en **aperçu** avant bascule.
- Conversion assistée des `doc_config` / `field_config` vers des exigences de poste, avec arbitrage pour les cas « Company exige, rôle masque ».
