# ADR-815 — Audit des données et matrice de compatibilité

> Lecture seule de la base DEV au 2026-11-17 (`evidence/` ; script de collecte reproductible : lectures SQL uniquement). **Aucune suppression, réécriture, régénération ou réapplication.**

## 1. État des données

| Élément | Société 1 (« Leezr Logistics E2E… », `logistique`, Pro) | Société 101 (recette, `logistique`, Starter) |
|---|---|---|
| Appartenances (comptes) | 22 | 5 |
| Comptes sans fiche salarié | 6 | 1 |
| Salariés | 52 (36 sans compte, 52 actifs, 0 préparatoire) | 5 (1 sans compte) |
| Salariés avec poste | **7** | **0** |
| Salariés rattachés à un service / avec responsable | 2 / 10 | 0 / 0 |
| Postes (`workforce_job_roles`) | 4 : Responsable d'exploitation, Planification, Livreur, Gestionnaire administratif | 0 |
| Services / lieux | 3 / 2 | 0 / 0 |
| Contrats | 38 actifs, 2 brouillons ; **12 salariés sans contrat** | 1 actif ; 4 sans contrat |
| Rôles RBAC | 5 par défaut (`Dispatcher`, `Driver`, `Manager`, `Operations Manager`, `Propriétaire`) + 6 rôles de recette **sans archétype** | 5 par défaut, mêmes noms anglais, créés avant ADR-813 |
| Rôles avec `doc_config` / `field_config` | 5 / 5 (les rôles par défaut) | 5 / 5 |
| Activations de champs | 45 activés, 1 requis | 36 activés, 0 requis |
| Activations de pièces | 8 actives, 3 requises | 5 actives, 3 requises |
| Types de pièces propres à la société | 0 | 0 |
| Demandes de documents | 25 sur comptes (23 demandées, 2 approuvées) + **35 sur salariés sans compte** (toutes « demandées ») | 3 sur comptes |
| Pièces déposées (`member_documents`) | 6, sujet compte | 1 |
| Documents d'entreprise | 0 | 0 |
| Exigences propres (`requirements.company_id`) | **0** | **0** |
| Dossiers d'intégration | 0 | 0 |
| Assistant RH (`workforce_onboardings`) | terminé | absent (assistants de module ADR-803 : `workforce` en cours, 5 modules « à configurer ») |
| Applications de presets | 2 (organisation) | 0 |
| Valeurs de champs de personne | **1** (compte) | 0 |

**Métier `logistique`** :
- Rôles par défaut en français depuis ADR-813.
- 5 pièces et 36 champs par défaut.
- Liens de presets : organisation `logistique_base_v1`, droits `logistique_rbac` (v2 publiée, v1 archivée), exigences `logistique_requirements` (v1 publiée).
- Preset planning v1 publié.

**Catalogues Platform** :
- 14 types de pièces système (9 pour la personne, 5 pour l'entreprise) et 46 définitions de champs système.
- Exigences : 48 modèles globaux (7 issus des modules, 41 du preset).
- **Hygiène** : 7 presets d'organisation de test `e2e_preset_*` (4 publiés, 3 brouillons) restent dans le catalogue Platform.

## 2. Matrice de compatibilité pour la refonte

| Donnée | Catégorie | Traitement proposé | Décision humaine ? |
|---|---|---|---|
| `workforce_employees` (avec ou sans compte) | **À conserver telle quelle** | Base de la « personne » cible | Non |
| `memberships`, `users` | À conserver | Accès au logiciel uniquement | Non |
| `workforce_employment_contracts` | À conserver | Rattaché au parcours d'embauche cible | Non |
| `workforce_job_roles` (4 dans la société 1, 0 dans la 101) | À conserver, **à compléter** | Devient le porteur des exigences | **Oui** : faut-il créer les postes manquants depuis le preset `logistique_base_v1` (adoption explicite, jamais automatique) ? |
| Affectation d'un poste aux salariés (45 sur 52 sans poste) | **Ambiguë** | Ne pas déduire le poste du rôle RBAC automatiquement. Proposer une **correspondance suggérée** rôle → poste, validée par la société. | **Oui** |
| `company_roles.doc_config`, `required_tags`, `archetype` | **À projeter autrement** | Lus **une fois** pour proposer des exigences de poste équivalentes (`RequirementParityComparator` mesure l'écart), puis figés en lecture seule. Ne pas les supprimer tant que la bascule n'est pas prouvée. | **Oui** (validation de la projection) |
| `company_roles.field_config`, `field_definitions.visibility_roles` | À projeter autrement | Obligation → exigence de poste ; visibilité → permissions. | Oui |
| `field_activations.required_override` | À conserver | Reste la règle **société** (couche « Company » de la hiérarchie) | Non |
| `document_type_activations.required_override` | À conserver | Idem pour les pièces | Non |
| `requirements` globaux (48) | À conserver | Recommandations métier et modules | Non |
| `document_requests` existantes (58 dans la société 1, dont 35 sur salariés sans compte) | **À conserver telles quelles** | Jamais régénérées. Les nouvelles règles ne s'appliquent qu'aux nouvelles affectations ou par action explicite. | Non |
| Demandes créées par lecture (`MemberDocumentWorkflowReadModel`) | Données légitimes, **origine ambiguë** | Conservées. On ne peut pas distinguer, sans l'historique, une demande créée par lecture d'une demande voulue. | Information |
| `company_dashboard_layouts` (4 dans la société 1) | À conserver | Migrées vers le bloc « Entreprise » de `/home` sans modification | Non |
| Doublon `companies.jobdomain_key` / `company_jobdomain` | **Doublon réel** | Garder les deux tant que les lecteurs ne sont pas unifiés (P4) | Non |
| Rôles de la société 101 en anglais | Conservés (ADR-813 : jamais renommés) | — | Non |
| Presets `e2e_preset_*` du catalogue Platform | Données de test à retirer | Par identifiants exacts, après décision | **Oui** |
| Comptes de démonstration (`admin@leezr.test` = Alice) | Ambiguë (cause de la confusion de l'accueil) | Renommer l'e-mail de démonstration, si souhaité | **Oui** |

## 3. Migrations fonctionnelles potentielles (aucune exécutée)

1. **Adoption des postes recommandés** par société : application du preset organisation existant, sur action de la société.
2. **Projection rôle → exigences de poste** : calcul de proposition à partir de `doc_config`, `required_tags` et `field_config`, présenté pour validation. Aucune écriture automatique.
3. **Affectation des postes aux salariés** : suggestion selon le rôle RBAC du compte lié ; les salariés sans compte n'ont pas de suggestion.
4. **Bascule d'autorité** de `DocumentResolverService` (rôle) vers `EffectiveRequirementResolver` (poste) : derrière un drapeau de bascule (à créer s'il n'existe pas ; non vérifié dans cet audit), avec le rapport de parité à 100 % comme critère.

Toutes ces étapes sont **réversibles** et **sans perte**. Les anciennes colonnes restent lisibles tant que la bascule n'est pas validée.
