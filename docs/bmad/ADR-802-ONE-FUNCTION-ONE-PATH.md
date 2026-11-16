# ADR-802 : rationalisation P1, une fonction, un parcours

- **Base** : `dev` @ `d7cb183e`. DEV uniquement, rien poussé.
- **SHA livré** : voir §10.
- **Préservés, non commités** : `CLAUDE.md`, `typed-router.d.ts` (régénéré par le build, toujours 98 routes), `DMS-ARCHITECTURE.md`.
- **Aucun backend métier modifié** : aucune API, aucun service, aucune garde serveur, aucune donnée, aucun rôle ni pivot, aucune migration. Seule exception : une entrée de menu Platform retirée d'un manifest.
- **Hors périmètre** : aucune bascule JobDomain ni Requirements, aucun assistant de modules, `@core` intact. L'identité de recette ADR-801.1 (PlatformUser 21) est conservée.

## 1. Équipe : Members, Workforce Employees et People

### Ce qui distingue les trois

| Notion | Modèle | Ce qu'elle porte | Garde |
|---|---|---|---|
| **Compte** | `User` | identité de connexion, 2FA, mot de passe | — |
| **Membre** | `Membership` + `CompanyRole` | appartenance à la société, rôle, accès au logiciel, champs du profil membre | `members.*` |
| **Salarié** | `Employee` (`workforce_employees`) | fiche RH, poste et organisation, contrat et emploi, documents RH. **Peut exister sans User** | `workforce.*` |
| **Personne** (`people`) | lecture fusionnée (`personRef`) | une ligne par personne, avec ses facettes Compte, Membre et Salarié | `members.view` ou `workforce.view` |

Les modèles ne sont pas fusionnés. Seuls les **parcours** l'ont été.

### Avant / après

| | Avant | Après |
|---|---|---|
| Pages de liste des personnes | 3 : `/company/people`, `/company/members` (1 224 lignes), `/company/workforce/employees` (562 lignes) | **1** : `/company/people` (Équipe), avec des vues sous la même navigation |
| Vues d'Équipe | Personnes, Rôles | Personnes, **Gestion RH**, Rôles |
| Menu « Outils » d'Équipe | 2 liens **vers d'autres pages** | 1 action **dans la page** (Champs du profil) |
| Formulaires de création d'un salarié | 2 : tiroir d'Équipe et dialogue de la liste RH | **1** : le tiroir d'Équipe ; la vue RH le déclenche |
| Formulaires d'invitation d'un membre | 2 : tiroir d'Équipe et `AddMemberDrawer` | **1**, la même action `members.addMember` |

### Où se trouve chaque fonction

- **Gestion RH** (`people/_PeopleHrTools.vue`) : la liste RH reprise telle quelle, avec filtres avancés (département, poste, manager, contrat, statut), sélection multiple, actions groupées, import guidé et export.
  - Garde : module `workforce` actif et `workforce.view`, comme l'ancienne page. Import et export restent conditionnés à `workforce.import` et `workforce.export`.
  - Les lignes ouvrent la fiche Équipe canonique.
- **Champs du profil** (`people/_ProfileFieldsSettings.vue`) : extraits **sans réécriture** de l'ancienne page Membres. Même store, mêmes API (`field-activations`, `field-definitions`).
  - Garde d'affichage : `settings.manage`, c'est-à-dire **la garde réelle de l'API**. L'ancien bouton était conditionné à `members.manage` alors que le serveur exige `settings.manage` : un utilisateur sans ce droit voyait l'outil et recevait 403. Prouvé par test. Aucune garde serveur n'a été changée.
- **Quota de membres et profils incomplets** (`people/_MemberHealth.vue`) : ce que seule l'ancienne liste Membres montrait. Même source (`GET /company/members`). Chaque profil incomplet ouvre la fiche.
- **Actions sur un membre** (retirer, suspendre, restaurer, renvoyer l'invitation, réinitialiser le mot de passe) : déjà dans la fiche personne (ADR-768). L'API de retrait est la même (`DELETE /company/members/{id}`).
- **Identité et compte, appartenance, fiche RH, poste et organisation, accès, documents, contrat** : onglets de la fiche personne, inchangés.

## 2. Documents

| | Avant | Après |
|---|---|---|
| Pages autonomes rendant du contenu | Signatures (`/company/workforce/signature`) et Modèles de contrat (`/company/workforce/contract/templates`). Le registre RH redirigeait déjà | **0** : tout vit dans l'espace Documents (`/company/documents/{people,signatures,templates}`) |
| Fichiers « page + composant » (double vie) | 3 : `workforce/documents/index.vue`, `signature/index.vue`, `contract/templates.vue` étaient à la fois des routes et des composants importés | 0 : ce sont des vues `_HrDocumentsView`, `_SignaturesView`, `_ContractTemplatesView`, renommées avec `git mv` et sans `definePage` |
| Entrées vers les modèles de contrat | 3 | 2 : l'onglet Documents → Modèles, et le lien des Paramètres RH, qui pointe désormais **directement** vers lui |

Le DMS, les politiques de confidentialité, les demandes, la signature, les modèles et la vérification sont **réutilisés tels quels**. Aucun nouveau coffre, aucune fonction spécialisée retirée.

## 3. JobDomain et presets

| | Avant | Après |
|---|---|---|
| Endroits où modifier et publier un preset recommandé par un domaine | 2 : catalogue Platform → Presets, et fiche JobDomain → onglet de la famille | **1 : la fiche JobDomain**. Le catalogue affiche « Modifier depuis le domaine X » |
| Associer un preset **partagé** à un autre domaine | API seulement (sauf pour l'organisation) | Dans chaque onglet (Rôles, Champs & documents, Planning) : « Utiliser un modèle partagé », via l'endpoint existant `preset-recommendations` |
| Menu Platform « Presets d'organisation » | entrée séparée | **retirée**. L'éditeur dédié reste ouvert depuis JobDomain → Organisation et depuis le catalogue, rangé sous Catalogue |

Le catalogue reste la **bibliothèque** :
- les familles non rattachées s'y créent, s'y modifient et s'y publient (sinon, les brouillons orphelins deviendraient impossibles à publier) ;
- il garde l'historique, les écarts, l'aperçu sur une société, l'archivage (refusé s'il casserait une recommandation) et la liste « utilisé par ».

Sont conservés :
- versions publiées immuables ;
- historique, audit, prévisualisation ;
- presets partagés, avec l'avertissement « partagé avec » ;
- permissions `presets.*` et `manage_jobdomains`.

**Aucune publication ni application automatique, aucune bascule de la création de sociétés.**

## 4. Workforce : temps de travail

| | Avant | Après |
|---|---|---|
| Formulaires modifiant les mêmes règles de temps de travail | 2 : l'étape « Temps de travail » de l'assistant (ADR-799), et Paramètres → onglet `worktime` (liste brute des règles) | **1 formulaire** (`_WorkingTimeSetup`, même composant et même API), dans l'assistant et dans Paramètres |
| Réglages détaillés | mélangés au reste | panneau replié « Réglages détaillés », qui ne présente **que** les règles que le formulaire ne gère pas : maxima journalier et hebdomadaire, seuil d'heures supplémentaires, détail des pauses. La liste des clés exclues vient du serveur (`WorkingTimeSetupService::FIELDS`, via l'API existante), pas d'une seconde liste codée en dur |
| Règles modifiables à deux endroits | 8 (référence hebdomadaire, repos quotidien et hebdomadaire, horaires de nuit, durée de pause cible, application et reprise anticipée des pauses) | **0** |

Les règles de société ne sont pas dans le JobDomain, et il n'y a aucun nouveau moteur : `WorkforceRuleResolver` et `ShiftRuleValidator` restent seuls.

## 5. Routes

| Route | Statut | Destination | Garde avant redirection |
|---|---|---|---|
| `/company/members` | **redirigée** | `/company/people` | module `core.members` + `members.view` (inchangée) |
| `/company/workforce/employees` | **redirigée** | `/company/people?tab=hr`, avec `q`, `status`, `dept`, `role`, `manager`, `contract` conservés | module `workforce` + `workforce.view` |
| `/company/workforce/signature` | **redirigée** | `/company/documents/signatures` (`status` conservé) | `workforce_signature` + `workforce_signature.view` |
| `/company/workforce/contract/templates` | **redirigée** | `/company/documents/templates` | `workforce_contract` + `workforce_contract.view` |
| `/company/workforce/documents` | **redirigée** (déjà au runtime, désormais une vraie page de redirection) | `/company/documents/people`, ou `templates` / `signatures` selon `?tab=` | `workforce_documents` + `workforce_documents.view` |
| `/company/people` | conservée | — | inchangée |
| `/platform/organization-presets`, `/:id` | conservées (éditeur spécialisé), sans menu propre | — | `manage_jobdomains` |
| Routes retirées | **aucune** : le nombre de routes générées reste 98 | | |

Une ancienne URL **ne contourne aucune permission** : le routeur applique la garde d'origine avant de rediriger, et la vue de destination a la sienne.

Liens internes mis à jour vers la destination directe : fiche membre (retour), fiche salarié (retour), assistant Workforce, Paramètres RH (modèles), section Contrats.

## 6. Éléments supprimés (preuve d'absence de consommation, tests compris)

| Élément | Lignes | Preuve | Remplacé par |
|---|---|---|---|
| `company/views/AddMemberDrawer.vue` | 167 | 0 référence dans `resources/js` et `tests` après la fusion | `_PersonAddDrawer` (même action `members.addMember`) |
| Dialogue de création de la liste RH | ≈ 70 | — | tiroir « Ajouter » d'Équipe (`POST /workforce/employees`) |
| Table, aperçu rapide et en-tête de la liste Membres | ≈ 420 | — | liste Équipe, fiche personne, `_MemberHealth` |
| En-tête de page de la liste RH | ≈ 40 | — | onglet d'Équipe |

- **Aucune logique métier supprimée.** Les stores (`members`, `employees`) et toutes les API sont réutilisés.
- **Bilan** (diff `resources/js`, `app`, `tests`) : 1 695 lignes ajoutées et 1 833 supprimées (−138), puis −167 avec `AddMemberDrawer`, soit **−305 lignes nettes**. L'essentiel des ajouts est du code **déplacé** sans réécriture : liste RH et champs du profil.

## 7. Mesures de simplification

| Mesure | Avant | Après |
|---|---|---|
| Pages Company rendant une liste de personnes | 3 | 1 |
| Pages Company autonomes doublonnant l'espace Documents | 2 (+1 déjà redirigée) | 0 |
| Formulaires concurrents : création de salarié | 2 | 1 |
| Formulaires concurrents : invitation | 2 | 1 |
| Formulaires concurrents : temps de travail (réglages dupliqués) | 2 (8 réglages en double) | 1 (0 en double) |
| Chemins d'édition et de publication d'un preset de domaine | 2 | 1 |
| Entrées de menu Platform pour les presets d'organisation | 1 | 0 |
| Liens « outils » vers d'autres pages depuis Équipe | 2 | 0 |
| Composants retirés | — | 1 (`AddMemberDrawer`) |
| Endpoints, services ou moteurs ajoutés | — | **0** |

**Services backend réutilisés** :
- `members.store` et `GET/POST /company/members`, `field-activations`, `field-definitions` ;
- `employees.store` et `/workforce/employees` (liste, bulk, import, export) ;
- `WorkingTimeSetupService` et `/workforce/working-time-setup`, `/workforce/config/*` ;
- `/platform/presets`, `preset-recommendations`, `JobdomainPresetWorkspace` ;
- espace Documents et DMS.

## 8. Tests

| Série | Résultat |
|---|---|
| `Adr802OneFunctionOnePathTest` (nouveau) | **14/14** : 5 anciennes pages = redirections avec **la même garde** ; filtres RH conservés ; vues sans `definePage` ; Équipe héberge les vues avec leurs gardes d'origine, sans lien vers une seconde page, avec un seul formulaire de création ; Documents embarque les vues ; un seul formulaire de temps de travail (exclusion des clés) ; preset de domaine modifiable seulement depuis le domaine, partage par endpoint existant, menu retiré. **HTTP négatifs** : un profil « chauffeur » reçoit 403 sur liste RH, export, modèle d'import, membre, bulk et champs ; `members.manage` sans `settings.manage` reçoit 403 sur l'écriture des champs (justifie la garde UI) ; `workforce.view` seul lit la vue RH, mais export et bulk répondent 403 |
| Régressions ciblées : ADR-797/798/799/801/801.1, navigation Company, M7, presets d'organisation, `PageLayoutMetaTest`, `PageModuleAlignmentTest`, `PeoplePhase2Test`, `PlatformNavModularTest` (attente du menu mise à jour) | **115/115** |
| Suite complète finale (une fois, après tous les correctifs) | **4517 réussis, 12 ignorés, 3 échecs**. Ce sont les 3 tests de `ControllerSizeInvariantTest`. La liste des contrôleurs cités est **identique** à celle d'ADR-801.1, et aucun contrôleur n'a été touché par ce lot. Aucun nouvel échec |
| Build | propre, 98 routes |

### Recette navigateur

Lecture seule : toute écriture API est bloquée, et l'instantané de 11 tables (policies, presets, liens, rôles, pivots, champs, activations, salariés, adhésions, presets d'organisation, tickets) est **identique avant et après**. Captures dans `docs/bmad/adr802/`, en desktop, mobile et sombre.

| Profil | Vérifications |
|---|---|
| Administrateur (`owner`) | Équipe : Personnes, Gestion RH, Rôles. Champs du profil ouverts depuis Outils. `/company/workforce/employees?dept=1&q=du` → `/company/people?tab=hr&q=du&dept=1`. `/company/members`, `signature?status=pending`, `contract/templates`, `documents?tab=templates`, `documents` et `configuration` aboutissent tous à la bonne vue. Paramètres → Temps de travail : formulaire unique et panneau détaillé **sans** le repos quotidien. Fiche d'un salarié sans compte (`employee-4`). Planning. Mobile en sombre sans défilement horizontal |
| Manager (`workforce.view`) | Vue Gestion RH sans import ni export, pas d'outil Champs |
| RH (`workforce.view` + export) | Vue Gestion RH avec export |
| Salarié (aucun droit) et chauffeur | `/company/workforce/employees`, `/signature`, `/contract/templates` → 403. `?tab=hr` n'affiche pas la vue RH |
| Platform `admin` | Pas de menu « Presets d'organisation ». JobDomain → Organisation. Éditeur dédié accessible. Catalogue Presets en bibliothèque |
| Platform limitée (`usage-only`) | JobDomain, presets d'organisation et catalogue refusés, renvoi vers Opérations |

**Dossier candidat** : le panneau Intégrations d'Équipe est inchangé (`OnboardingPanel`, affiché sur la vue Personnes pour `workforce.manage`), et la régression ADR-796 fait partie de la suite complète.

## 9. Risques restants

| # | Risque | Proposition |
|---|---|---|
| R1 | Une vingtaine d'**E2E historiques** ouvrent directement `/company/workforce/employees` (sélecteurs `emp-*`) ou `/company/members`. Ils arrivent maintenant sur Équipe → Gestion RH (les `emp-count`, `emp-more`, `emp-export`… sont conservés), mais `emp-add` et le dialogue de création n'existent plus. Ils n'ont **pas** été rejoués | Les mettre à jour vers le tiroir « Ajouter » d'Équipe lors d'un lot de maintenance E2E |
| R2 | L'e-mail « copie signée » pointe toujours vers `/company/workforce/signature` : il passe par la redirection, avec la même garde qu'avant (un salarié sans `workforce_signature.view` reçoit 403, comme avant) | Pointer vers « Mon espace » → Signatures (lot dédié, côté serveur) |
| R3 | Le choix d'un modèle partagé remplace la recommandation générale de la famille pour ce domaine. Le preset partagé est ensuite modifiable depuis chaque domaine qui l'utilise (avertissement « partagé avec ») | Conforme au modèle ADR-797/799 |
| R4 | Le catalogue modifie encore les familles de **bibliothèque** (non rattachées) : c'est un second éditeur, sur un ensemble disjoint | Assumé, et la règle est écrite dans l'interface |
| R5 | Le formulaire de temps de travail est présent à deux **entrées** (assistant, Paramètres), mais c'est un seul composant et une seule API | Conforme : un formulaire, deux points d'accès naturels |

## 10. SHA

Voir l'entrée ADR-802 de `docs/bmad/04-decisions.md` et le commit qui suit.
