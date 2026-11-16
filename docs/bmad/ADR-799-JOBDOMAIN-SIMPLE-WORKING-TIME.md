# ADR-799 — JobDomain simple, cadre conventionnel, assistant Company du temps de travail

> Date : 2026-11-16 · DEV uniquement · Base `a3808c42` · Aucun push.
> Aucun travail ADR-799 antérieur n'existait : la rectification s'applique directement, sans reprise ni retour arrière.

## Règle fondamentale

**Le JobDomain configure le métier et ses recommandations. Il ne configure jamais l'organisation du temps de travail d'une société.**

## 1. Responsabilités définitives

| Porteur | Responsabilité | Où dans le code |
|---|---|---|
| Platform / Market | Référentiel réglementaire et conventions par marché (versions, validité, références vérifiées, règles, populations, dérogations) | `market_rule_sets` (règles légales référencées) · `convention_collectives` et `convention_collective_rules` (+ colonnes de traçabilité ADR-799) |
| JobDomain | Identité, modules, presets Rôles / Organisation / Exigences / Modèles de planning, **références** aux conventions pertinentes par marché | `jobdomains` (+ `convention_refs`) · `JobdomainPresetWorkspace` |
| Presets | Modèles initiaux versionnés : aucune autorité juridique, aucune réapplication silencieuse | `PresetService` (inchangé) |
| Company | **Confirme** son cadre conventionnel ; règle son rythme, ses repos, ses horaires et le comportement de ses propres règles | `companies.convention_confirmed_*` · `CompanyPolicy` (domaine `workforce`) · `WorkingTimeSetupService` |
| Employee / Contract | Convention propre, volume horaire, régime : prévalent pour le titulaire | `employment_contracts.convention_collective_id`, `weekly_hours`, `work_model_key` (existants) |
| Workforce | **Seul point de contrôle** : planning et pointage | `ShiftRuleValidator` (planning), `WorkTimeComplianceChecker` (feuilles de temps) |

Conséquence technique : la couche « JobDomain » du `WorkforceRuleResolver` (politiques de source `jobdomain_preset`) est retirée. Elle n'a jamais contenu de données, aucune politique de cette source n'existant en DEV ; le comportement est donc inchangé.

## 2. Phase A — Page JobDomain simplifiée (six onglets)

`/platform/jobdomains/{id}` comporte six onglets :

1. **Général** : nom, secteur, description, vocabulaire, marchés et **Cadre conventionnel recommandé** (conventions existantes par marché).
   - Un rappel explicite indique que le domaine ne fixe pas l'organisation du travail.
   - Une section **avancée repliée** regroupe le diagnostic de compatibilité, le rapport par société, l'ancienne configuration et la comparaison des créations. Ce ne sont plus des écrans principaux.
2. **Modules** : modules recommandés, classés (socle, inclus, payant, incompatible).
3. **Rôles** : preset RBAC du domaine, édité dans l'onglet.
4. **Organisation** : choix du modèle publié et aperçu des départements et postes, avec un lien vers l'éditeur dédié existant (non reconstruit).
5. **Champs & documents** : preset Exigences du domaine, présenté par sujet (entreprise, collaborateurs, représentant légal).
6. **Modèles de planning** : modèles réutilisables. L'onglet rappelle que **ce ne sont pas des règles de travail**.

**Édition sans complexité visible** (`JobdomainPresetWorkspace`) :
- « Modifier » rouvre le brouillon en cours, ou crée une nouvelle version du preset en vigueur, ou part de l'ancienne configuration.
- « Publier » publie **et** associe au domaine. Aucun choix de version, aucune association manuelle, aucune navigation vers d'autres catalogues.
- Versions, provenance, historique, contrôle de concurrence et audit restent conservés en interne ; l'interface affiche seulement « en vigueur depuis », « modifications en cours » et le nombre de versions.
- Un preset **partagé** avec d'autres domaines est signalé, avec la liste de ces domaines.

## 3. Référentiel conventionnel (prolongement minimal)

**Existant réutilisé** :
- `ConventionCollective` : IDCC, nom, marché ;
- `ConventionCollectiveRule` : règles par type, date d'entrée en vigueur et de fin, mode, source, référence ;
- `EmploymentContract.convention_collective_id` : la convention propre d'un contrat ;
- `Company.default_convention_collective_id`, qui n'était renseignée que par une commande de recette.

**Ajouts** (migration additive `2026_12_01_000001`) :
- Conventions : champ d'application documenté, source juridique, version, entrée en vigueur, fin, date de vérification.
- Règles : date de vérification, **population** concernée, **dérogation** admise.
- JobDomain : `convention_refs`, des références par marché. Le contrôle vérifie que la convention appartient bien au marché.
- Company : `convention_confirmed_at` et `convention_confirmed_by`.

**Règles respectées** :
- Aucun seuil inventé. Les conventions DEV (« Métallurgie », « CC Beta ») sont des données de test **non vérifiées**, affichées comme telles. Aucune convention n'a été créée pour la logistique.
- Une convention suggérée par le domaine **n'a aucun effet** tant que la société ne l'a pas confirmée ; le moteur ne lit que la convention confirmée.
- Une société peut confirmer « aucune convention ne s'applique ».
- **Plusieurs conventions** : la convention d'un contrat prévaut pour son titulaire. Les contrats concernés sont signalés dans l'assistant. Les conventions par établissement ne sont pas modélisées (préparé, §7).

## 4. Phase B — Assistant Company « Temps de travail »

C'est l'étape « Temps de travail » de l'assistant Workforce **existant**, qui jusqu'ici renvoyait seulement vers les paramètres. Elle contient désormais `_WorkingTimeSetup.vue` (API `GET` et `PUT /workforce/working-time-setup`, `POST …/convention`).

| Section | Réglages | Moteur |
|---|---|---|
| A. Cadre applicable | marché ; convention suggérée, puis **confirmée** (ou aucune) ; état du contrôle juridique (disponible si des règles légales référencées existent, sinon « contrôle juridique indisponible ») ; contrats relevant d'une autre convention | `WorkingTimeSetupService::framework` / `confirmConvention` |
| B. Rythme | jours travaillés par semaine (4, 5 ou 6 j/7), jours fixes ou variables, heures hebdomadaires de référence, premier jour de la semaine ; contrats d'un autre volume signalés | résolveur (organisation, temps de travail) + `week_start_day` existant |
| C. Repos | repos quotidien et hebdomadaire (renforcement seulement), jours consécutifs maximum, jours de repos par semaine, repos consécutifs | résolveur |
| D. Horaires | durée cible d'une journée, pause cible, début et fin du travail de nuit | résolveur (pause, temps de travail, organisation) |
| E. Comportement | jours consécutifs : informer, avertir, bloquer ou dérogation motivée ; règles légales vérifiées toujours bloquantes | résolveur + validateur unique |

L'enregistrement est **tout ou rien**, et chaque valeur passe par les garde-fous du résolveur :
- minimum ou maximum légal vérifié ;
- bornes de valeur ;
- valeurs autorisées.

Les messages d'erreur sont traduits. Chaque réglage affiche son statut réel : **contrôlé au planning**, **enregistré (non contrôlé)**, **non défini** ou **contrôle juridique indisponible**. Aucun statut « conforme ».

## 5. Hiérarchie des règles

Le résolveur normatif existant est conservé ; ce n'est pas un simple « la valeur la plus haute gagne ».

- **Disposition légale impérative** : par exemple le seuil de déclenchement de la pause, qui n'est pas modifiable.
- **Minimum ou maximum légal** : seule une valeur **plus favorable** l'emporte. La société peut renforcer un repos ; une valeur moins favorable est **refusée à l'enregistrement** si la règle légale est référencée, sinon ignorée et signalée comme conflit.
- **Convention** : celle du contrat, sinon celle confirmée par la société.
- **Politique de la société** : réglages de configuration.
- **Contrat** : convention et volume propres.
- **Modèle de planning, puis créneau réel** : contrôlés par le validateur.
- Hors cadre légal référencé : « contrôle juridique indisponible », jamais une fausse garantie.

## 6. Un seul moteur de contrôle

`ShiftRuleValidator` reste le seul point d'exécution au planning, avec un contrôle ajouté (les jours consécutifs).

| Règle | État | Effet |
|---|---|---|
| Repos quotidien minimum (légal, renforçable) | **contrôlé** | bloquant, jamais dérogeable |
| Durée journalière et hebdomadaire maximales (légales) | **contrôlé** | bloquant |
| Travail de nuit | **contrôlé** | information (marquage du créneau, y compris à cheval sur minuit) |
| Jours travaillés consécutifs (politique de la société) | **contrôlé, ajouté** | selon la société : informer, avertir ou bloquer, ou dérogation motivée (motif obligatoire, tracé sur le créneau et audité) ; le décompte traverse les semaines |
| Repos hebdomadaire (légal) | contrôlé sur les feuilles de temps (`WorkTimeComplianceChecker`) | rapport |
| Pauses | contrôle au pointage existant | selon le réglage « pause » |
| Jours par semaine, jours fixes, durée cible, jours de repos, repos consécutifs | **enregistré, non contrôlé** | recommandation |
| Rotations, règles par établissement ou équipe, rythme par population | **non pris en charge** | préparé (§10) |

- **Sociétés existantes** : aucune nouvelle contrainte. Le contrôle des jours consécutifs ne s'active que si la société définit une limite ; 9 jours consécutifs ne déclenchent rien sans réglage (test).
- **Dérogation** : seule une règle de **politique de la société** réglée sur « dérogation motivée » l'accepte, via `derogation_reason` sur la création d'un créneau. Une règle légale refuse toute dérogation, même motivée. Les opérations groupées (modèles, copies) n'ont pas encore de motif : la dérogation y vaut blocage.
- **Correction annexe** : `CompanyPolicyResolver::set` recherche désormais le réglage du jour **par date**. Auparavant, sous SQLite, un nouveau réglage le même jour provoquait un doublon sur la clé unique ; MySQL n'était pas concerné.

## 7. Tests et captures

- `Adr799WorkingTimeFrameworkTest` : 6 tests couvrant les scénarios 1 à 19.
  - Même domaine organisé en 4 j/7 et 5 j/7 ; contrat différent ; isolation.
  - Deux marchés ; deux conventions pertinentes ; convention d'un autre marché refusée ; suggestion sans effet ; confirmation appliquée par le moteur.
  - Repos renforcé accepté ; repos moins favorable refusé ; dérogation motivée acceptée ; dérogation non motivée et règle légale refusées ; nuit ; créneau à cheval sur minuit ; jours consécutifs à cheval sur deux semaines ; bloquant.
  - Société existante sans nouvelle contrainte.
  - Preset partagé entre domaines ; publication depuis l'onglet sans réapplication ; aucun droit élargi.
  - Permissions Platform (droit complet pour publier depuis un onglet), Company (lecture et gestion), séparation des surfaces.
- Tests existants liés (résolveur, politiques, planning, pauses, presets, JobDomain, intégration) : **254/254**.
- **Recettes navigateur** : 2/2, sans résidu.
  - **A** : domaine fictif, six onglets, preset Rôles créé puis publié depuis son onglet, modèles de planning, sombre, mobile.
  - **B** : société 1 en lecture, cadre, rythme, repos refusé par le serveur (politiques de la société inchangées, vérifié), comportement, mobile sombre.
- Captures : `docs/bmad/adr799/`.
- **Suite complète** (une fois, en fin de lot) : 4482 réussis, 12 ignorés, 4 échecs.
  - 3 sont les échecs historiques `ControllerSizeInvariantTest`.
  - 1 vient d'un **changement voulu** : `WorkforceNormativeResolutionTest` attendait un repos quotidien « obligatoire, non modifiable » ; il devient « renforçable seulement ». Le test a été mis à jour, avec en plus la vérification du refus d'une valeur moins favorable (9 h). Revérifié par un test ciblé : 11/11.
- Build propre.

## 8. Ce qui est disponible / contrôlé / à vérifier / préparé

- **Disponible** :
  - page JobDomain en six onglets ;
  - cadre conventionnel recommandé ;
  - confirmation par la société ;
  - assistant A à E ;
  - dérogation motivée sur la politique des jours consécutifs.
- **Réellement contrôlé** : repos quotidien, durées maximales, nuit, jours consécutifs (planning) ; repos hebdomadaire (feuilles de temps) ; pauses (pointage).
- **À vérifier juridiquement** :
  - les conventions DEV de test et leurs règles ;
  - le contenu réel des conventions transport et logistique, à saisir et vérifier par marché ;
  - les dérogations conventionnelles (réduction du repos, etc.), non modélisées comme exécutables.
- **Préparé pour le lot suivant** :
  - rotations ;
  - règles par établissement ou équipe ;
  - rythme par population ;
  - contrôle effectif des jours par semaine et du repos hebdomadaire au planning ;
  - motif de dérogation dans les opérations groupées ;
  - écran Platform d'administration du référentiel conventionnel (aujourd'hui : données en base, traçabilité ajoutée).

## 9. Compatibilité

- Aucune société réelle modifiée. Seule la société 1 a été lue par la recette, et la tentative d'enregistrement a été refusée sans effet.
- Aucune réapplication de preset, aucune règle nouvelle appliquée silencieusement.
- Pas de bascule du résolveur des exigences, pas de changement Billing ou paie.
- Les conventions déjà renseignées sans confirmation restent lues comme avant et sont signalées comme « jamais confirmée ».

## 10. Risques résiduels

- Les dérogations conventionnelles ne sont pas exécutables : seule la politique de la société se dérogue.
- Le contrôle « 4 j/7 » n'est pas actif au planning : réglage enregistré seulement.
- Les messages d'erreur suivent la langue de la requête API.
- La section avancée du JobDomain dépend toujours des outils ADR-797 et 798 (lecture seule).

## 11. SHA DEV

Voir le checkpoint (commit sur la base `a3808c42`).
