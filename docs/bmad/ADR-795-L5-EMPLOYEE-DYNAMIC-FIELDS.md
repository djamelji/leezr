# ADR-795 — L5 : champs dynamiques d'un salarié, avec ou sans compte

**Date** : 2026-11-15 · **Base** : `6e16336e` · **Environnement** : DEV local uniquement · **Statut** : livré, checkpoint L5 (L6 non commencé)

## 1. Diagnostic des propriétaires de données

| Constat (code réel) | Conséquence |
|---|---|
| Les valeurs des champs `company_user` sont écrites sur le **User** (`field_values.model_type = user`), sans société (`FieldWriteService`, `MembershipController`, `UserProfileController`, `CompanyUserProfileReadModel`). | Un salarié **sans compte n'avait aucun champ dynamique**. Une valeur propre à un employeur (poste, contrat, organisation) est **partagée par toutes les sociétés** de la personne. |
| `Employee` n'était pas dans la table de correspondance polymorphe (`enforceMorphMap`). | Aucun stockage possible sur la fiche salarié. |
| Le verrou contractuel ADR-787 (`ContractualIdentityLock`) lit les valeurs sur le compte. | Il ne protège que le libre-service : les RH gardent leur propre chemin d'édition (inchangé). |
| `DsnEmployeeResolver` lit `model_type = 'App\Core\Models\User'` alors que la correspondance enregistre `user`. | Requête qui ne trouve jamais rien : défaut **antérieur, non corrigé** (périmètre paie interdit), signalé. |
| DEV : 17 valeurs, dont 16 sur la société et **1 seule sur un compte** (téléphone, donnée personnelle). | Aucune valeur d'emploi à reprendre en DEV. |
| Les messages L4 `requirements.module_not_active` et `requirements.unknown_proposal` manquaient dans les fichiers de langue. | Clé brute renvoyée par l'API : **corrigé**. |

## 2. Architecture cible : qui possède quoi

| Catégorie | Emplacement canonique |
|---|---|
| A. User | compte et authentification (e-mail de connexion, mot de passe, préférences), et données **personnelles** de la personne qui a un compte |
| B. Personne | sans entité séparée (non justifiée à ce stade) : les données personnelles sont sur le User quand il existe, sinon sur la fiche salarié de la société qui les a recueillies, **jamais poussées vers une identité globale** |
| C. Membership | relation et autorisations dans une société (inchangé) |
| D. Employee | informations RH et métier **propres à la société** : nouvelles valeurs `field_values.model_type = employee` |
| E. EmploymentContract | contrat et historique (inchangé, jamais modifié) |

`FieldOwnership` attribue un propriétaire à chaque champ :
- **Personnels** : téléphone, adresses, sexe, naissance, nationalité, NIR, IBAN, contacts d'urgence, permis, ADR.
- **Emploi** : intitulé de poste, type de contrat, date d'embauche, statut, véhicule, zone, horaires, mode de travail, toute définition propre à une société ou créée par un module.
- Une valeur `validation_rules.owner` sur la définition prime.

## 3. Modèle de stockage

- **Même table et mêmes définitions** que le reste de l'application (`field_values`, `FieldDefinition`, `FieldActivation`), sans système parallèle. La nouvelle propriétaire est la fiche salarié (`employee`), isolée par société par construction. L'unicité existante (définition, propriétaire) suffit.
- **Lecture** par `EmployeeFieldStore` :

| Situation | Valeur affichée | Statut |
|---|---|---|
| Champ d'emploi | valeur de la fiche | normal |
| Champ d'emploi, valeur seulement sur le compte (avant L5) | valeur du compte, source `legacy_account` | `needs_review` |
| Champ personnel, personne avec compte | valeur du compte (non modifiable ici) | normal |
| Champ personnel saisi sur la fiche avant la création du compte, ou différent du compte | valeur du compte | `needs_review` (rien n'est écrasé) |

- **Statuts** : `missing` (absent ou vidé), `filled`, `invalid` (contrôle de type), `needs_review`. Une valeur présente n'est pas pour autant valide, et rien n'en fait une obligation.
- **Historique** : table `field_value_changes`, avec ancienne et nouvelle valeur, auteur et source (`hr_edit` ou `legacy_adoption`). Pour un champ sensible, seule l'empreinte `sha256` est conservée.
- **Migration** : additive (`2026_11_25_000001`), appliquée en DEV.

## 4. API

| Route | Accès |
|---|---|
| `GET /workforce/employees/{id}/fields` | `workforce.view` + périmètre salarié (`EnsureEmployeeInScope`) |
| `PUT /workforce/employees/{id}/fields` `{fields: {code: valeur}, confirm_contractual?}` | `workforce.manage` |

- La fiche salarié est toujours résolue **dans la société courante** : 404 sinon.
- L'écriture est partielle et idempotente ; une valeur `null` vide le champ.
- Les types sont validés avec les règles existantes (`FieldValidationService`) ; la normalisation du téléphone est réutilisée.
- Rejets : champ inconnu ou non activé (422), champ personnel d'une personne qui a un compte (422, géré depuis son compte).
- Le verrou de la fiche salarié est pris avant toute lecture (leçon ADR-794).
- La commande `fields:ownership-report` est décrite au §9.

## 5. Permissions et confidentialité

- **Valeur sensible** (NIR, IBAN) : lecture et écriture réservées à `workforce.sensitive_read` ou `members.sensitive_read`. Sinon la valeur est `null` avec `masked` : **aucun fragment**, contrairement au masquage des 4 derniers caractères de l'ancien chemin. L'écriture est refusée (403).
- **Manager ou lecteur sans `workforce.manage`** : limité à son périmètre (403 hors périmètre).
- **Autre société** : 404 sur un salarié d'une autre société, 403 sans appartenance.
- **Catalogue ≠ valeur ≠ permission** : une contribution de module (L4) n'expose aucune valeur ; la désactivation d'un champ le masque sans rien effacer.

## 6. Contrat établi (ADR-787)

Si le salarié a un contrat `active`, `suspended` ou `terminated` dans **cette** société :
- tout changement d'un champ protégé (hors coordonnées de contact), **première saisie comprise**, exige `confirm_contractual = true` ; sans confirmation, 422 et rien n'est écrit (toute la transaction est annulée) ;
- l'interface affiche une confirmation explicite ;
- la modification est historisée ;
- le contrat, les documents générés et les signatures ne sont jamais modifiés (testé).

Le verrou libre-service ADR-787 sur le compte est inchangé.

## 7. Multi-company

- **Une personne, deux employeurs** : deux fiches, deux jeux de valeurs d'emploi, sans fuite de l'un à l'autre (testé). Les données personnelles du compte restent partagées, comme avant (ADR-752→755).
- **Compte créé plus tard** : la même fiche est conservée et les valeurs aussi. L'identité du compte fait foi, et les écarts sont signalés pour rapprochement. Il n'y a ni seconde identité RH, ni écrasement.

## 8. Requirements et modules

- `EmployeeFieldStore::presence($employees)` donne l'état par salarié et par définition en **requêtes constantes**. Il s'articule avec `EffectiveRequirementResolver` : un champ exigé apparaît `missing`, puis `filled`, ou `invalid` si l'option a été retirée.
- **Rien n'est basculé** : les obligations opérationnelles restent celles d'ADR-790, `required` vaut toujours `false` dans cette API, et la page ADR-792 reste en préparation.
- Un champ désactivé, par exemple par un module, n'est plus affiché ni modifiable, mais sa valeur est conservée et revient à la réactivation (testé).

## 9. Migration et données historiques

- **Aucune conversion de masse.** `fields:ownership-report` est en lecture seule et classe chaque valeur posée sur un compte :

| Catégorie | Sens |
|---|---|
| `stays_on_account` | donnée personnelle, reste sur le compte |
| `no_employee_record` | aucune fiche salarié correspondante |
| `ambiguous_multi_company` | plusieurs employeurs possibles |
| `duplicate_same_value` | même valeur déjà sur la fiche |
| `conflict` | valeur différente déjà sur la fiche |
| `proposed_adoption` | reprise proposée |
| `unknown_field` | définition inconnue |

- **Reprise explicite** avec `--adopt=<code> --employee=<id> --company=<id> [--apply]` :
  - une seule société, obligatoire ;
  - le salarié doit appartenir à cette société ;
  - simulation sans `--apply` ;
  - la valeur du compte n'est jamais supprimée, et une valeur déjà présente sur la fiche n'est jamais écrasée.
- **DEV** : une seule ligne, `stays_on_account` (téléphone). Rien à reprendre.

## 10. Interface

- La section **Emploi** de la fiche personne (ADR-768) gagne un bloc « Informations complémentaires » (`_EmployeeFields.vue`) qui réutilise `DynamicFormRenderer`. Ce n'est pas une seconde fiche salarié.
- Le bloc est éditable avec `edit_hr`. Les valeurs gérées par le compte, masquées ou à vérifier apparaissent en lecture seule, avec un libellé explicite.
- Un champ protégé par contrat demande une confirmation.
- **E2E en lecture seule** (`tests/e2e/adr795-employee-fields.spec.js`) : 1/1 sur un salarié sans compte de DEV, aucune écriture. Captures dans `docs/bmad/adr795/`.

## 11. Frontière DMS

Une déclaration de confidentialité `highly_confidential` dans un manifeste **n'était pas appliquée** : `DocumentAccessPolicy` décide par code, et un nouveau code tombe en catégorie standard. Le document aurait donc été consultable par un manager direct ou avec `documents.view`, malgré la déclaration.

**Correctif** : la synchronisation L4 refuse désormais ce cas (`collision: confidentiality_not_enforced`), et le type n'est pas créé.
- Les niveaux que le DMS applique effectivement restent acceptés, et le niveau affiché est toujours celui de la politique DMS (`classificationFor`).
- `DocumentAccessPolicy` n'est ni contournée ni modifiée.

## 12. Tests et performance

- **`Adr795EmployeeFieldsTest`** : 9 tests, 104 assertions, couvrant les 20 scénarios :

| Scénarios | Couverture |
|---|---|
| 1, 9, 13, 14, 15 | salarié sans compte ; saisie RH ; historique ; idempotence ; vidé ≠ absent ; champ inconnu ; type invalide (date, option) |
| 5, 6, 7 | IBAN masqué sans fragment ; écriture sensible refusée ; historique en empreinte ; hors périmètre 403 ; sans droit 403 |
| 3, 16 | multi-company isolé ; cross-tenant 404/403 |
| 2, 4, 10 | compte créé plus tard : valeurs conservées, identité rapprochée sans écrasement, une seule fiche ; owner également salarié |
| 8 | contrat établi : confirmation exigée, rien écrit sans elle, contrat inchangé |
| 11, 12, 13 | changement de poste, départ, champ désactivé puis réactivé : valeur conservée |
| Transition | rapport ; société obligatoire ; reprise impossible sur un salarié d'une autre société ; simulation ; reprise sans suppression ni second écrasement |
| 17, 18, 19 | présence ↔ requirements ; présence ≠ validité ; requêtes constantes (+41 salariés) ; obligations opérationnelles et chemin compte inchangés |
| 20 | confidentialité déclarée mais non appliquée : refusée ; niveau effectif = DMS |

- **Tests liés** : ADR-787/791/792/794, profil membre, champs, People, garde-fou tenant — 120 réussis.
- **Défauts trouvés pendant le lot** :
  - `Eloquent\Collection::only()` filtre par clé primaire : la valeur courante n'était pas relue, d'où une fausse modification et un historique en double. Corrigé.
  - La commande chargeait un salarié sans filtre de société (garde-fou tenant) : la société est désormais obligatoire.
- **Mesures DEV** (société 1) :

| Opération | Temps | Requêtes |
|---|---|---|
| Lecture d'une fiche (28 champs) | 6,1 ms | 4 |
| Présence de 52 salariés | 27,8 ms | 8 (constant) |

- **Suite complète** : 4436 réussis, 12 ignorés, 3 échecs historiques. **Build** : propre.

## 13. Préparation du portail candidat

Un candidat pourra être une fiche salarié sans compte, au statut adapté, en réutilisant :
- `EmployeeFieldStore` (valeurs, statuts, confidentialité, historique) ;
- `presence()` face aux exigences L1/L2 ;
- le dossier documentaire par `personRef` (`employee-{id}`).

La création du compte au moment de l'embauche suit le chemin testé : aucune perte, rapprochement explicite.

## 14. Risques résiduels

- Les écrans membre et profil continuent d'écrire **tous** les champs sur le compte : un champ d'emploi saisi par ce chemin reste partagé entre employeurs. La fiche salarié l'affiche alors `legacy_account` / `needs_review`. Rediriger ces écritures est l'étape suivante.
- `DsnEmployeeResolver` (paie) lit un `model_type` erroné : défaut antérieur, non corrigé (paie hors périmètre).
- Pas d'entité « Personne » distincte : les données personnelles d'un salarié sans compte sont propres à la société qui les a recueillies, comme attendu, mais ne sont pas mutualisées à la création du compte (rapprochement manuel).
- Une confidentialité plus stricte pour un nouveau type documentaire nécessitera une évolution de `DocumentConfidentiality` (catégorie en données) : pour l'instant, un tel type est refusé plutôt que mal protégé.

## 15. Compatibilité

- **Aucune modification** de rôle, contrat, signature, document, Billing, paie, JobDomain, preset, ni des exigences opérationnelles.
- **DEV** : migration additive appliquée, 0 valeur `employee` écrite par ce lot, valeurs existantes intactes.

## 16. Résultat et SHA

- **Suite complète** (une exécution) : 4436 réussis, 12 ignorés, 3 échecs = dette historique `ControllerSizeInvariantTest` (le nouveau contrôleur fait 45 lignes, sans `->save()` ni façade DB) ; aucun autre échec.
- **Build** : propre.
- **Commit** : `git log --grep "ADR-795"` sur `dev`.
