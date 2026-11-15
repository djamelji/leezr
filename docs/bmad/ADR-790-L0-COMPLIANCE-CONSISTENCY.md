# ADR-790 — L0 : cohérence de la conformité documentaire

> Base `d853f83e` · DEV uniquement · **lot L0 d'ADR-789 seulement** : aucune nouvelle table, aucune migration, aucun JobDomain, rôle, permission ni exigence modifiés.
> Tests : `tests/Feature/Adr790ComplianceConsistencyTest.php`.

## 1. Cause exacte de la divergence

| Vue | Service | Calcul des pièces exigées |
|---|---|---|
| Fiche personne, dossier documentaire (membre, fiche unifiée), Mon espace | `DocumentResolverService::resolve` (via `SubjectDocumentReadModel`, `MemberDocumentWorkflowReadModel`, `SelfDocumentReadModel`) | obligatoire du catalogue (modules actifs, étiquettes ∩ étiquettes requises **du rôle de la personne**) OU `required_override` Company, puis surcouche `doc_config` **du rôle** (masquer / rendre obligatoire) |
| Conformité de l'entreprise, widgets du tableau de bord, priorités de l'accueil | `DocumentComplianceReadModel::forCompany` (via `DocumentComplianceController`, `DocumentsHomePriorities`, `compliance.store`) | **son propre calcul** : obligatoire du catalogue **sans rôle** OU `required_override`, **appliqué à toute la population** ; `doc_config` et étiquettes ignorés |

Conséquences : une pièce exigée par la configuration du rôle (permis, certificat médical du livreur) n'apparaissait jamais en conformité ; une pièce masquée pour un rôle (permis pour le dispatcher ou le propriétaire) y était exigée quand la Company l'avait rendue obligatoire.

## 2. Architecture avant / après

| | Avant | Après |
|---|---|---|
| Décision d'exigence | deux calculs | **un seul** : `DocumentResolverService::requirementMap(company, role, market, scope)` |
| Vues personne | `resolve()` (calcul interne) | `resolve()` = présentation de `requirementMap` (sortie inchangée, 404 tests de non-régression) |
| Conformité | calcul Company, même liste pour tous | `slotsFor()` : `requirementMap` **par personne**, avec le rôle de SON adhésion ; mémoïsé par rôle |
| Tableau de bord, accueil | conformité | conformité (alignés automatiquement) |
| États | `DocumentComplianceStateService` (manquant, demandé, en attente, refusé, valide, bientôt expiré, expiré) | inchangés |

Règles conservées à l'identique : modules actifs, étiquettes du rôle, activation Company (`required_override`), `doc_config` du rôle (avec la garde : un document obligatoire du catalogue ne peut être ni masqué ni rendu facultatif), marché. **Aucun poste n'est déduit d'un rôle** : le rôle ne porte que les règles qui s'appliquaient déjà à lui.

## 3. Source de résolution retenue

Le calcul de la fiche (`DocumentResolverService`), le plus complet (rôle, étiquettes, configuration du rôle, garde des documents obligatoires). Il a été extrait sans modification de règle dans `requirementMap`, puis réutilisé par la conformité.

## 4. Consommateurs alignés

Fiche personne · dossier documentaire (membre, fiche unifiée) · Mon espace · page Conformité · widgets de conformité du tableau de bord · priorités documentaires de l'accueil. Le salarié **sans compte** n'a pas de rôle : seules les règles de la Company s'appliquent, dans la fiche comme dans la conformité (limite exposée, aucune obligation inventée).

## 5. Matrice des scénarios

| # | Scénario | Fiche | Conformité | Résultat |
|---|---|---|---|---|
| 1 | Livreur, permis exigé par sa configuration de rôle | permis + identité | permis + identité (avant : identité seule) | aligné ; **test en échec sur l'ancien code** |
| 2 | Administratif, permis masqué par son rôle | identité | identité | aligné, pas de permis |
| 3 | Propriétaire également salarié | identité | identité | aligné |
| 4 | Compte sans fiche salarié | règles de son rôle | idem | aligné |
| 5 | Salarié sans compte | règles Company seules | idem | aligné (limite : aucune règle de rôle possible) |
| 6-9 | Permis manquant, valide, expiré, renouvellement en cours | — | `missing` → `valid` → `expired` → jamais `valid` pendant le renouvellement | états existants conservés |
| 10 | Configuration Company (RIB rendu obligatoire) | exigé | exigé pour tous | aligné |
| 11 | Société sans fiches salarié | — | population = comptes ; mêmes règles | aligné |
| 12 | Manager / livreur sans droit documentaire | — | page de conformité 403 ; son espace 200 | permissions inchangées |
| 13 | Autre société | — | aucune personne d'une autre société | isolation |
| — | Rôle sans configuration | — | aucun permis déduit du rôle | pas de poste inféré |
| — | Calcul de la conformité | — | aucune demande, aucun document, aucune activation créés ; une exigence par (personne, document) | pas de doublon |

## 6. Tests

| Niveau | Résultat |
|---|---|
| `Adr790ComplianceConsistencyTest` (8) | 8/8 ; le test du scénario 1 échouait sur le code précédent (permis absent de la conformité) |
| Non-régression documents, DMS, conformité, tableau de bord, accueil, personnes, membres, budgets de requêtes (774) | 774/774 |
| Confidentialité (sonde DEV, transactions annulées) | propriétaire : conformité 200 ; Bob : conformité et documents d'un collègue 403, son espace 200 |
| Suite complète | 4383 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest` (inchangés) |
| Build / navigateur | non requis : aucun fichier d'interface modifié (mêmes écrans, valeurs recalculées) |

## 7. Mesures avant / après (données DEV, lecture seule)

| Société | Personnes | Exigences avant | Exigences après | Manquants après | Personnes concernées | Écarts fiche ↔ conformité avant → après |
|---|---|---|---|---|---|---|
| 1 | 58 | 118 | **126** | 67 | 58 | **16 → 0** |
| 2, 3, 31-34 | 2 / 1 / 6 / 26 / 51 / 101 | 4 / 3 / 8 / 28 / 53 / 103 | identiques | identiques | identiques | 0 → 0 |

Détail société 1 (+8) : +12 certificats médicaux (les 12 livreurs, exigés par leur configuration de rôle) ; −4 permis (propriétaire et 3 dispatchers, permis masqué par leur rôle). Conformité ↔ tableau de bord : même calcul, 0 écart par construction.

**Divergence historique signalée, non corrigée** : la société 1 a un `required_override` sur le permis (permis exigé par la Company à toute la population). C'est une configuration Company, conservée : le permis reste exigé aux 36 salariés sans compte et aux rôles de recette sans configuration propre. À revoir par la Company (ou en L2 avec les exigences par poste).

## 8. Limites restantes

1. Un salarié sans compte n'a pas de rôle : seules les règles Company lui sont appliquées (L2 : exigences par poste).
2. Les exigences restent portées par le rôle RBAC quand elles l'étaient déjà (L2).
3. Les champs dynamiques n'étaient pas concernés : un seul résolveur existait déjà (`FieldResolverService`).
4. `BatchRequestByRoleUseCase` et les campagnes créent des demandes explicites : inchangés (L7).

## 9. Préparation de L1

`requirementMap` est le point d'entrée unique que le futur `EffectiveRequirementResolver` remplacera : L1 pourra calculer en parallèle et comparer ses résultats à `requirementMap` par personne (même forme : type, obligatoire, visible, ordre), sans toucher aux vues.
