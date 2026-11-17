# ADR-817 — L1 : poste métier, exigences et rôles d'accès

- **Date** : 2026-11-17, DEV, base `adf2b6ce`.
- **Périmètre** : lot L1 uniquement ; L2 à L10 ne sont pas commencés. Les décisions D1 à D17 sont prises dans leur option recommandée (a), conformément à l'arbitrage annoncé.
- **Critère final** : « Une Company peut définir les informations et justificatifs attendus pour un poste métier, sans confondre ce poste avec les autorisations d'accès au logiciel. »

## 1. Modèle métier retenu (aucune table, aucun moteur nouveaux)

| Notion | Porte | Structure existante réutilisée |
|---|---|---|
| **Poste** (`workforce_job_roles`) | Intitulé, service, niveau, missions et responsabilités. **Exigences** : informations à recueillir et justificatifs, obligatoires ou facultatifs. | Registre des exigences (ADR-791/792), portée `job_role:{id}` |
| **Rôle d'accès** (`company_roles`) | Permissions du logiciel uniquement. | Inchangé. N'apparaît sur la fiche du poste que comme **origine** d'une règle historique, jamais comme réglage. |
| **Personne / salarié** | Identité, poste, contrat, données recueillies, compte facultatif. | Inchangé |

- **Obligation et recommandation** sont distinguées par l'éditeur existant (`RequirementConfigService`) :
  - « obligatoire » ;
  - « facultatif » ;
  - « recommandé » (proposition du métier ou d'un module, libre) ;
  - « verrouillé » (obligation applicable, impérative, justifiée).
- **Aucune configuration concurrente** : la page « Informations & documents demandés » et l'onglet « Exigences » du poste utilisent **le même composant** (`requirements/_RequirementsEditor.vue`), sur **la même API** et **le même registre**.

## 2. Écrans

- **Organisation → Postes** (`/company/workforce/organization/positions`, nouvelle adresse lisible ; `roles` reste valable) : chaque intitulé ouvre la fiche du poste.
- **Fiche du poste** (`/company/workforce/organization/positions/{id}`), quatre onglets :
  - **Informations** : intitulé, service, niveau, missions. Modifiable avec `workforce.manage`.
  - **Exigences** : l'éditeur partagé, figé sur ce poste. Le sélecteur de portée, la liste historique et les propositions de modules restent sur la page globale. Un bandeau dit clairement que les dossiers et demandes en cours ne changent pas tant que l'activation n'est pas validée. Visible avec `settings.manage` ou `documents.configure`, comme la page existante.
  - **Personnes (n)** : salariés du poste, avec ou sans compte, dans le périmètre du lecteur (filtre `job_role_id` existant).
  - **Reprise de l'existant** : voir §3. Même garde que les exigences.
- **Rien n'est affiché** des clés techniques, versions de preset, identifiants internes ou permissions RBAC.
- **Composants** : uniquement Vuexy/Vuetify existants (`AppPageHeader`, `VTabs`, `VWindow`, `VCard`, `VList`, `VAlert`, `VChip`, `VCheckbox`, wrappers `App*`). Aucune navigation parallèle.

## 3. Reprise de la configuration historique (lecture seule, adoption explicite)

**Audit des sources historiques**, avec ce que chacune devient :

| Source | Nature | Traitement L1 |
|---|---|---|
| `doc_config` (rôle) | Justificatifs rendus obligatoires ou masqués par rôle | Les justificatifs obligatoires sont **proposés** au poste. Les masquages sont **signalés**, jamais repris (ce sont des accès). |
| `required_tags` (rôle) × réglementation du métier | Justificatifs obligatoires selon les étiquettes du rôle (ex. `driving`) | **Proposés**, avec l'origine « par la réglementation du métier ». |
| `field_config` (rôle) | Informations obligatoires ou masquées par rôle | Les informations obligatoires sont **proposées** ; les masquages sont signalés. |
| `visibility_roles` (définitions de champs) | Qui peut voir une valeur | **Comptées et signalées**, jamais reprises : droit d'accès, pas exigence. |
| Exigences Company / JobDomain (registre) | Déjà dans le modèle cible | État courant du poste, affiché à côté de la proposition. |

**`PositionRequirementProposal`** calcule, pour les personnes actives du poste et uniquement ce que leurs rôles demandent **en plus de ce qui est demandé à tous** :
- l'**origine** : rôle, nombre de personnes, règle (configuration du rôle ou réglementation du métier) ;
- les **contradictions** (« Contradiction ») : ce qui est demandé par une partie seulement des rôles du poste, avec les rôles qui ne le demandent pas ;
- l'**écart avec la configuration actuelle** : « À reprendre », « Déjà configuré », « Hors catalogue » ;
- les **conséquences** : nombre de personnes, dont celles sans compte et celles sans rôle.

**Adoption** :
- elle passe par l'aperçu existant (`preview`, transaction annulée) puis par l'enregistrement existant (`commit`), avec historique ;
- **aucune correspondance rôle → poste n'est appliquée silencieusement** ;
- aucune demande n'est régénérée, aucune pièce n'est supprimée.

**Endpoint** : `GET /api/workforce/requirements/proposal?scope=job_role:{id}`, même groupe de middleware que l'éditeur (module `workforce` + `settings.manage|documents.configure`).

## 4. Parité et stratégie d'activation

**Calcul effectif inchangé.** `DocumentResolverService` (rôle) reste l'autorité des fiches, demandes, conformité et rappels. Aucune activation globale n'a lieu.

**Mesure nouvelle** : `requirements:compare --with-registry` (`RequirementParityComparator::compareWithRegistry`), à côté du mode historique. Elle compare la projection historique augmentée du registre de la société, donc les postes. Les écarts dus à une exigence posée par la société sont classés « différence attendue du modèle cible » (ils l'étaient en « cause inconnue »).

Mesures sur le clone isolé :

| Société | Mode | Concordances | Écarts | Bug de résolution | À configurer | Arbitrage |
|---|---|---|---|---|---|---|
| 1 (réelle, copie) | historique | 156 | 288 | 0 | 131 | 4 |
| 1 | avec registre | 156 | 288 | 0 | 131 | 4 |
| 101 (fictive) | historique | 19 | 42 | 0 | 33 | 0 |
| 101, après reprise du permis | avec registre | 20 | 43 | **0** | 32 | 1 |

Sur la 101, le seul arbitrage est le Dispatcher : son rôle rend le permis facultatif, son poste le rend obligatoire. C'est une décision produit, à trancher par la société.

**Stratégie d'activation proposée** (non appliquée, chaque étape sur GO) :
1. **Par société, puis par poste.** La société reprend ses postes jusqu'à « 0 bug de résolution » et « 0 à configurer » sur `--with-registry`. Chaque arbitrage est tranché explicitement.
2. **Bascule par société** d'un indicateur (à créer en L2/L3 sur le modèle des bascules ADR-808, OFF par défaut). Elle fait lire le registre aux fiches et aux nouvelles demandes **uniquement pour les nouvelles demandes**. Les demandes et pièces existantes restent intactes.
3. **Retour arrière** : couper l'indicateur ; aucune donnée n'est migrée.

## 5. Visibilité (D4)

Quatre capacités, séparées :

| Capacité | Permission | Changement |
|---|---|---|
| Son propre dossier | (soi) | inchangé |
| Son périmètre (soi et rapports directs) | `workforce.view` | inchangé |
| **Consulter tous les dossiers** | `workforce.view` + (`workforce.manage` **ou** `workforce.view_all`) | **nouveau** : `workforce.view_all` |
| Modifier | `workforce.manage` (routes d'écriture) | inchangé |

- La règle est unique : `WorkforceAccess::viewAllWith('employee')`, désormais utilisée aussi par `employeeScopeFor`.
- **Aucun élargissement implicite.** `workforce.view_all` n'est ajoutée à **aucun** groupe ni rôle existant. Elle vient avec un nouveau groupe, « Dossiers des collaborateurs (consultation) » (`workforce.hr_consultation`), à attribuer explicitement. Un test vérifie qu'aucun rôle ne la reçoit sans attribution.
- **Les périmètres d'écriture du planning ne bougent pas.** Les modèles de semaine et les activités planifiées recopiaient la règle `planning` à la main ; ils passent désormais par `hasViewAll('planning')`, à comportement identique, et `view_all` n'y entre pas.

## 6. Cas vérifiés (§5 du brief)

| Cas | Constat | Traitement |
|---|---|---|
| Poste obligatoire à l'embauche | La création accepte encore un salarié sans poste. | **L2 « Embaucher »** : le parcours rendra le poste obligatoire. Non imposé en L1, pour ne casser aucun import ni API existante. |
| Salariés historiques sans poste | Restent valides ; ils relèvent des exigences « tous les postes ». | Aucune affectation automatique. |
| Changement de poste | Le résolveur du registre suit le poste réel (ADR-792). Le calcul effectif reste celui du rôle tant qu'il n'y a pas de bascule. | Rien à migrer. |
| Plusieurs fonctions | Un poste par salarié. | **Non résolu, volontairement** : pas de multi-poste. Contournement : un poste composite. À rouvrir si un vertical l'exige. |
| Salarié sans compte | Compté et listé (« Sans compte ») ; justificatifs proposés. | Pour les informations, les valeurs vivent sur la fiche (ADR-795). |
| Propriétaire également salarié | Fiche salarié possible sans poste ; ses accès viennent de la propriété, jamais du poste. | Inchangé |
| Responsable actif (D17) | Un salarié **sorti** ne peut plus être choisi comme responsable (API et liste). Le responsable déjà affecté reste accepté, pour ne pas bloquer l'édition. | Fait |
| Site et organisation | Le poste porte son service ; le service est désormais validé **dans la société** (il ne l'était pas). | Fait. Le site reste hors poste (L-ultérieur). |
| Recommandations JobDomain | Propositions « recommandé », libres, déjà portées par le registre. | Affichées par l'éditeur |
| Personnalisation Company | Exigences du poste ou de tous les postes, avec historique. | Éditeur partagé |

## 7. Fichiers modifiés

**Backend** :
- `PositionRequirementProposal` (nouveau) ;
- `RequirementConfigController::proposal` et route `GET workforce/requirements/proposal` ;
- `RequirementParityComparator::compareWithRegistry` et classification des écarts posés par la société ;
- option `--with-registry` de `requirements:compare` ;
- `WorkforceModule` : permission `workforce.view_all` et groupe `workforce.hr_consultation` ;
- `WorkforceAccess` (règle `employee`) ;
- `WeekTemplateController`, `ScheduledActivityController` (règle `planning`, comportement identique) ;
- `EmployeeController` (responsable non sorti) ;
- `JobRoleController` (service de la même société) ;
- traductions `lang/{fr,en}/requirements.php`.

**Frontend** :
- `requirements/index.vue` devient une page mince, et l'éditeur passe dans `_RequirementsEditor.vue` (prop `positionId`) ;
- `organization/positions/[id].vue` (nouvelle fiche) ;
- `organization/[tab].vue` (alias `positions`, lien vers la fiche) ;
- `_EmployeeProfile.vue` (responsables actifs) ;
- `employees.store.js` : liste des postes normalisée. Sans droit sur la rémunération, la réponse arrivait en objet ; la liste « Postes » de l'Organisation était donc mal servie aux profils RH et managers sans paie.

**i18n** : `positions.*`, groupe `workforce.hr_consultation` (FR/EN).

## 8. Tests

- `tests/Feature/Adr817PositionRequirementsTest.php` : 7 tests.
  - Proposition en lecture seule, avec origine et contradictions.
  - Adoption explicite sans effet sur le calcul effectif ni sur les demandes ; parité sans « cause inconnue ».
  - Accès et isolation : salarié refusé, autre société refusée, portée « tous » refusée.
  - `view_all` lit tout sans modifier.
  - `view_all` jamais attribuée implicitement.
  - Responsable non sorti, l'historique restant conservé.
  - Personnes du poste et service validé dans la société.
- **Non-régression** : Adr791, Adr792, Adr816, PageLayoutMeta, RBAC, Workforce. Les échecs vus en lancement groupé par filtre passent en isolation (47/47) ; c'est une interférence d'ordre.
- **Suite complète** : 4649 passés, 12 ignorés, 4 échecs au premier passage.
  - 3 échecs `ControllerSizeInvariant` **historiques**, identiques à la référence. `EmployeeController` est ramené à sa taille de départ (343 lignes).
  - 1 échec ADR-806 : traduction arabe manquante du nouveau groupe. Corrigé, test vert.
- **Build** : propre.

## 9. Recette navigateur (clone isolé `leezr_audit817`, société fictive 101)

Détail : `docs/bmad/adr817/acceptance.json`, captures dans `docs/bmad/adr817/shots/`.

**Propriétaire** :
- l'alias ouvre Postes ; poste créé depuis l'interface ; trois personnes affectées, dont une sans compte ;
- fiche ouverte depuis la liste, 4 onglets ;
- description modifiée ;
- Personnes = 3, dont « Sans compte » ;
- la Reprise liste les règles des rôles Driver et Dispatcher, avec origine, contradictions et « À reprendre » ;
- aperçu « 1 exigence(s) seront ajoutées au poste (3 personne(s) concernée(s)) », reprise confirmée : permis obligatoire pour le poste ;
- éditeur intégré sans sélecteur de portée ; information facultative enregistrée ;
- la page globale fonctionne toujours ;
- **aucune erreur API**.

**RH en consultation** (`workforce.view` + `workforce.view_all`) :
- voit les 5 salariés et lit les fiches ; modification **403** ; reprise **403** ;
- fiche du poste en lecture seule (Informations, Personnes), aucune erreur API.

**Autres profils** :

| Profil | Fiche du poste | Reprise | Liste des salariés | Modifier le poste |
|---|---|---|---|---|
| Manager | 4 onglets | 200 : possède déjà `settings.manage` et `documents.configure` (préexistant) | 2 (son périmètre) | 403 |
| Salarié (Dispatcher) | 403 (pas `workforce.view`) | 200 : `documents.configure` est donné **par défaut** au Dispatcher (préexistant, voir §10) | 403 | 403 |
| Chauffeur | 403 | 403 | 403 | 403 |
| Operations Manager | 4 onglets | 200 (`documents.configure`) | 5 (`workforce.manage`) | 200 (`workforce.manage`) |

- **Isolation** : le propriétaire de la 101 sur la société 1 reçoit 403 pour la reprise comme pour les salariés.
- **Données historiques du clone, après la recette** :
  - demandes de documents 63 et pièces 7 : identiques à DEV ;
  - règles des rôles des autres sociétés : empreinte identique ;
  - seules les exigences adoptées s'ajoutent au registre.
- Le clone a été supprimé.

**Empreinte DEV** : `0eee8e4…` avant, `9a89db2…` après. L'écart vient d'une **activité humaine sur la société 1 via `leezr.test`** pendant la recette (04:20–04:37) :
- connexion et déconnexion ;
- un pointage avec pause ;
- la création du service « DPD » ;
- deux créneaux publiés.

La recette tournait exclusivement sur `127.0.0.1:8815` et sur le clone ; le script de préparation refuse toute base autre que le clone. Ces données n'ont pas été touchées.

## 10. Risques restants

- **`documents.configure` est donné par défaut au rôle Dispatcher** (préexistant). Il ouvre déjà l'éditeur d'exigences et la vue historique `/legacy` ; il ouvre maintenant aussi la reprise. Cela relève des rôles par défaut (D3), à traiter dans le lot rôles. Aucun élargissement par L1.
- **Signatures** : `SignatureReadModel` lit avec la règle `employee`. Un profil `view_all` voit donc les demandes de signature de tous, en lecture ; les actions restent gardées par leurs propres permissions. C'est cohérent avec « consulter tous les dossiers » et documenté ici.
- Le calcul effectif reste celui du rôle tant que la bascule par société (§4) n'existe pas. Les exigences de poste sont visibles et préparées, sans effet sur les demandes.
- Multi-poste non modélisé (§6).

## 11. Préparation de L2 « Embaucher »

- Le parcours d'embauche choisit **d'abord le poste**. L'aperçu des exigences existe déjà (`GET workforce/requirements?scope=job_role:{id}`) et liste les informations et justificatifs à demander, avec ou sans compte.
- Le poste devient obligatoire **dans le parcours** (pas dans l'API générique, pour préserver l'import).
- La demande initiale de pièces se calculera à partir du poste **pour les sociétés basculées** uniquement (indicateur §4).
