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

---

## Addendum ADR-795.1 — stabilisation des données RH (2026-11-15, base `66cca0d6`)

### 1. Cartographie des écritures

| Écran / service | Avant | Après |
|---|---|---|
| Fiche salarié / fiche personne, bloc RH (`PUT /workforce/employees/{id}/fields`) | L5 : fiche RH | `PersonFieldWriter` contexte `hr` |
| Gestion des membres (`PUT /company/members/{id}`, `MembershipController`) | **tout sur le compte** (`FieldWriteService` sur User) | `PersonFieldWriter` contexte `members` |
| Profil personnel / Mon espace (`PUT /profile`, `UserProfileController`) | **tout sur le compte** | `PersonFieldWriter` contexte `self` |
| Suggestions IA d'un document (`MemberDocumentAiSuggestionController`) | **tout sur le compte** | `PersonFieldWriter` contexte `suggestion` |

Ne sont pas concernés : la Platform (portée `platform_user`), l'inscription d'une société (portée `company`) et les colonnes natives de `Employee` (nom, e-mail, téléphone, date d'embauche), qui suivent leur propre chemin, inchangé.

### 2. Source canonique par catégorie

| Catégorie | Écriture | Lecture |
|---|---|---|
| Compte (connexion, mot de passe, préférences) | compte (inchangé) | compte |
| Identité personnelle (naissance, nationalité, NIR, IBAN, adresses, contacts d'urgence, permis) | compte s'il existe, avec **divulgation enregistrée** pour la société ; sinon fiche RH de la société | compte si divulgué à la société (ou valeur ancienne d'une personne à un seul employeur), sinon fiche RH |
| Données RH de l'entreprise (poste, contrat, embauche, organisation, horaires, définitions propres à la société ou à un module) | **fiche RH de la société** (`workforce.manage`) ; pour un membre **sans fiche RH**, son **appartenance** à cette société (`model_type = membership`, `members.manage`) ; jamais sur le compte | fiche RH, puis appartenance ; valeur ancienne du compte affichée `legacy_account` / `needs_review` |
| Données contractuelles | contrat (jamais modifié) ; les champs protégés suivent §5 | — |
| Données sensibles | mêmes cibles ; droit sensible requis | droit sensible requis |

Tous les lecteurs passent par la même résolution, `EmployeeFieldStore::cellsFor` / `rawValues` :
- la fiche RH ;
- le profil membre (`CompanyUserProfileReadModel`, y compris la complétude en lot) ;
- le profil personnel ;
- la DSN ;
- les variables de documents.

### 3. Corrections

- **Écrivain unique `PersonFieldWriter`** :
  - même validation de type pour tous les écrans ;
  - mêmes droits : une donnée RH exige `workforce.manage` quel que soit l'écran, et la personne ne modifie jamais les données RH de l'entreprise la concernant ;
  - verrou sur la personne pris avant toute lecture ;
  - historique (`field_value_changes`, avec le contexte comme source) ;
  - aucune écriture quand la valeur renvoyée est celle affichée, valeur ancienne comprise : il n'y a donc pas de reprise implicite.
- **Membre sans fiche RH** : constat de la suite complète (`MemberRoleAwareFieldTest`). Une société sans Workforce ne pouvait plus renseigner l'intitulé de poste de ses membres. La donnée va désormais sur l'**appartenance** (propre à la société), avec le droit `members.manage` : le droit suit l'enregistrement écrit.
- **Gestion des membres** : une donnée d'emploi va sur la fiche RH ; une donnée personnelle va sur le compte avec divulgation ; une donnée sensible illisible pour l'acteur est **ignorée** et n'est jamais réécrite avec la valeur masquée.
- **Fiche RH** : une donnée personnelle d'une personne qui a un compte reste gérée depuis le compte (règle L5 confirmée).
- **Profil membre** : affiche désormais la valeur canonique, alors qu'il ne lisait que le compte.

### 4. Matrice de confidentialité

| Lecteur | Donnée personnelle (compte) | Donnée personnelle sensible (NIR, IBAN) | Donnée RH |
|---|---|---|---|
| La personne elle-même | toutes les siennes | oui | oui (lecture) |
| Société qui l'a collectée ou à qui elle a été divulguée | oui | avec `members.sensitive_read` ou `workforce.sensitive_read`, sinon masquée | sa fiche RH uniquement |
| Autre employeur de la même personne | **non** (`not_disclosed`), et ne peut pas l'écraser (422) | **non** | sa propre fiche RH uniquement |
| Valeur ancienne (avant le registre), personne à un seul employeur | oui | selon droit sensible | — |
| Valeur ancienne, personne à plusieurs employeurs | **masquée partout** (`needs_review`) jusqu'à nouvelle saisie | idem | — |
| Administrateur sans droit sensible | oui | masquée (jamais réécrite) | oui |

Le stockage technique (le compte) ne vaut pas autorisation. Le **registre de divulgation** (`person_field_disclosures`) établit la portée de collecte : société et source (`self`, `members`, `hr`, `suggestion`).
- Aucune réplication entre sociétés.
- Aucune base juridique n'est déduite.
- Aucune API n'expose davantage qu'avant, plutôt moins : valeurs non divulguées masquées.

### 5. Contrat établi : contrôlé par le serveur

Dans tous les cas, le contrat, les documents générés et les documents signés ne sont jamais modifiés.

| Cas | Règle |
|---|---|
| Coordonnées (téléphone, adresses, contact d'urgence) | libres |
| Personne elle-même, champ protégé (y compris première saisie) | refus (ADR-787) |
| Gestionnaire sans droit RH | refus, même avec `confirm_contractual` (le drapeau du navigateur ne suffit jamais) |
| RH (`workforce.manage`) | autorisé uniquement avec confirmation explicite ; sinon 422 avec le signal `confirm_contractual` |

L'interface (formulaire membre de la fiche personne et bloc RH) affiche alors une confirmation et renvoie l'intention ; le serveur rejuge.

Portée de la protection :
- données personnelles sur le compte : contrat établi dans **n'importe laquelle** des sociétés de la personne ;
- données RH : contrat établi dans **cette** société.

### 6. DSN : diagnostic et correction

**Diagnostic**
- `DsnEmployeeResolver` lisait `field_values.model_type = 'App\Core\Models\User'`, alors que l'application enregistre l'alias `user` (`enforceMorphMap`). Il ne trouvait donc **aucune** valeur réelle.
- Les tests DSN existants insèrent leurs valeurs avec ce même nom de classe, d'où des tests verts malgré le défaut.
- Un salarié sans compte levait une exception (`resolve`) ou était **omis silencieusement** de la déclaration (`resolveBatch`), sans aucune erreur.

**Correction**
- La lecture passe par `EmployeeFieldStore::rawValues`, la résolution canonique de la société du salarié : fiche RH, compte divulgué à cette société. L'ancien nom de classe reste toléré en lecture.
- Un salarié sans compte est résolu depuis sa fiche RH.
- Un champ manquant reste manquant : `missingMandatoryFields` ou `DsnValidator` bloquent explicitement.
- Inchangés : règles de calcul, cotisations, format, périodes, génération opérationnelle et Billing. La DSN reste une **simulation non certifiée**.
- Deux tests historiques qui figeaient l'ancien comportement (exception, omission) sont mis à jour.

**Même défaut, même correctif** : les variables `field.*` des documents (`DocumentVariableResolver`) utilisaient `get_class()` et ne trouvaient rien. Elles lisent désormais les valeurs canoniques du salarié concerné **dans cette société**, pour les nouvelles générations uniquement : aucun document existant n'est régénéré.

### 7. Compatibilité historique

- **Aucune migration de données** : seule une table additive est créée (`2026_11_26_000001_create_person_field_disclosures_table`).
- **Valeurs anciennes** : lues et marquées, jamais déplacées, réécrites ou supprimées.
- **DEV** : une seule valeur sur un compte (téléphone de l'utilisateur 1, appartenant à une seule société), qui reste visible.
- **Profil membre** : +6 requêtes **constantes** au plus pour la résolution canonique (plafond documenté de `CompanyMemberProfileTest` relevé de 21 à 27, toujours sans N+1). Les requêtes de divulgation et d'appartenance ne sont faites que lorsqu'elles sont utiles.

### 8. Tests

- **`Adr7951HrDataConsistencyTest`** : 8 tests, en HTTP direct, couvrant les 20 scénarios et le cas du membre sans fiche RH :

| Scénarios | Couverture |
|---|---|
| 1, 2, 3, 5, 15 | fiche RH, gestion des membres et profil lisent la même valeur ; écriture sur la fiche RH, jamais sur le compte ; gestionnaire sans droit RH refusé ; formulaire renvoyé tel quel sans écriture ; historique des deux écrans |
| 4 | profil personnel : données personnelles et divulgation, données RH refusées |
| 6, 7, 8, 9, 14, 19 | sans / avec compte ; deux employeurs aux données RH différentes ; NIR collecté par A invisible et non modifiable par B ; valeur ancienne ambiguë masquée, valeur ancienne d'un seul employeur visible ; cross-tenant 404 |
| 10 | IBAN masqué, jamais réécrit par un formulaire sans droit sensible |
| 11, 12, 13 | première saisie protégée ; drapeau navigateur insuffisant ; RH sans confirmation refusé avec signal ; RH confirmé autorisé ; contrat inchangé |
| 16, 17, 18 | DSN avec compte, sans compte, champ manquant bloquant ; autre employeur sans identité non divulguée |
| 20 + documents | obligations opérationnelles inchangées ; variables `field.*` canoniques et isolées |

- **Tests liés** : 464 réussis (ADR-787/794/795, profil membre, DSN et export DSN, IA, People, garde-fou tenant, layouts).
- **E2E** : `tests/e2e/adr795-employee-fields.spec.js` 1/1 après build.
- **Suite complète finale** (après la correction « membre sans fiche RH ») : 4444 réussis, 12 ignorés, 3 échecs = dette historique `ControllerSizeInvariantTest`. Une première exécution avait révélé `MemberRoleAwareFieldTest`, corrigé ensuite. Build propre.

### 9. Limites résiduelles

- Les valeurs anciennes d'une personne à plusieurs employeurs restent masquées jusqu'à une nouvelle saisie par la personne ou par la société concernée. Il n'existe pas encore d'écran de rapprochement guidé ; le rapport en ligne de commande ADR-795 s'applique.
- Le registre de divulgation trace la portée de collecte mais ne qualifie pas la **base juridique** (consentement, obligation légale) : à définir avec le portail candidat.
- Un membre sans fiche RH garde ses données propres à la société sur son appartenance. Si une fiche RH est créée ensuite, **elle devient la seule source affichée** : la valeur portée par l'appartenance est conservée en base, mais n'est plus affichée ni recopiée automatiquement. Sa reprise explicite (même principe que `fields:ownership-report`) reste à outiller avant L6.
- `FieldWriteService` reste utilisé pour les portées `company` et `platform_user`, qui ne sont pas concernées.

### 10. Préconditions pour L6

- Toute nouvelle écriture de champ `company_user` passe par `PersonFieldWriter` : le garde-fou est à étendre si de nouveaux écrans apparaissent.
- Le portail candidat écrit en contexte `self` sur une fiche RH sans compte, puis la création du compte suit le chemin testé : rapprochement explicite.
- Base juridique et consentement à formaliser dans le registre de divulgation avant toute collecte candidat.

### 11. SHA

Commit de stabilisation : `git log --grep "ADR-795.1"` sur `dev`.
