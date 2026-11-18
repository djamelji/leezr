# Leezr 3.6 — Workforce multi-modèles, fiabilisation paie, navigation, temps réel, pré-déploiement (ADR-826)

> Branche `dev` locale. **Aucun push, aucun déploiement, aucune société réelle modifiée.**
> - Recettes avec écriture : uniquement sur le clone isolé `leezr_audit821`, société fictive 109.
> - Aucun taux, aucune cotisation, aucun montant de rémunération modifié.

Légende des statuts : **IR** implémenté et recetté · **INR** implémenté, non recetté au navigateur · **EV** existant et vérifié · **AU** audité uniquement · **BL** bloqué · **NR** non réalisé.

## 1. Commits locaux

| Commit | Lot | Objet |
|---|---|---|
| `4444ffd8` | 3 | La préparation de paie suit le modèle de temps (garde d'exclusion explicite) |
| `e8e6f769` | 4 | Cohérence contrat / rémunération ; remédiation contrôlée des modèles inconnus |
| `2a4e051a` | 5 | Le formulaire de contrat suit le modèle de temps |
| `659f7ca7` | 6 | Le bouton Précédent ferme un panneau ouvert depuis une liste (D-7) |
| `836f0392` | 7 | Flux temps réel : durée de vie configurée respectée, désenregistrement garanti |
| `1b9dd450` | 4 | Remédiation bornée par société (garde de contournement du scope tenant) |
| `c51b6a39` | 10 | Spec E2E de génération de contrat réalignée sur la fiche unifiée |
| lot 11 | 11 | Rapport, ADR-826, preuves, script de restauration de l'incident |

## 2. Matrice des modèles de temps

Le modèle vient **du contrat** (`work_model_key`), jamais du type de contrat (CDI, CDD…), du statut cadre ni de l'intitulé du poste.

| | Horaire temps plein | Horaire temps partiel | Forfait heures | Forfait jours |
|---|---|---|---|---|
| 1. Unité | heure | heure | heure (volume du forfait) | jour |
| 2. Données contractuelles | `weekly_hours` | `weekly_hours` inférieur à la référence | `weekly_hours` = volume du forfait | nombre de jours (**absent du modèle de données**) |
| 3. Suivi du temps | pointage, planning | idem | pointage (si nécessaire) | jours travaillés et repos (**non pris en charge** : feuille horaire utilisée) |
| 4. Validation | feuille verrouillée | idem | idem | feuille verrouillée (en attendant un relevé de jours, D-1) |
| 5. Justificatif de paie | feuille verrouillée | idem | idem | feuille verrouillée servant de relevé |
| 6. Absences | lignes d'absence de la feuille | idem | idem | idem (en jours via les lignes) |
| 7. Dépassements | heures supplémentaires au-delà des seuils Market / Company (règles existantes) | **heures complémentaires non prises en charge → exclusion** | **non pris en charge → exclusion** | **aucune heure supplémentaire horaire → exclusion si présentes** |
| 8. Règles Market | `weekly_hours_legal` (référence), maxima, repos | idem ; limites et majorations des heures complémentaires **absentes** | aucune règle propre | conditions d'éligibilité, plafond de jours **absents** |
| 9. Comportement actuel | inchangé | préparé s'il reste dans son contrat, sinon exclu | préparé sans dépassement, sinon exclu | préparé sans heures supplémentaires et non payé à l'heure, sinon exclu ; sans feuille : motif propre |
| 10. Cible | inchangée | heures complémentaires selon les règles Market | dépassement au-delà du volume | relevé de jours, plafond, suivi de la charge |
| 11. Écarts | — | heures complémentaires | seuil du forfait | relevé de jours, nombre de jours au contrat, éligibilité |
| 12. Risques | — | heures au-delà du contrat non payées si le garde est contourné | dépassement mal valorisé | forfait jours appliqué sans éligibilité vérifiée |

- Les règles générales (unité, justificatif, exclusion) sont dans le code.
- Les règles **propres à un pays** (seuils, limites, majorations) restent dans les règles Market : **aucune n'a été inventée**.
- Matrice 3.5 d'origine : `company-3-5/WORKFORCE-WORK-MODEL-MATRIX.md`.

## 3. Fonctionnement actuel et cible

- **Actuel (après ADR-826)** : un seul moteur, de type horaire. Un garde (`WorkModelPayrollGuard`) reconnaît les cas où ce moteur serait manifestement faux pour le modèle du contrat. Il **exclut** alors le salarié avec un motif explicite de niveau erreur, qui exige une justification écrite à la validation (règle ADR-824 conservée).
- **Cible** : un suivi propre à chaque modèle :
  - relevé de jours pour le forfait jours ;
  - heures complémentaires pour le temps partiel ;
  - seuil au volume du forfait pour le forfait heures.

  Elle exige les décisions D-1 à D-6 et des règles Market. Rien de cela n'est implémenté (**NR**).

## 4. Règles réellement implémentées

| Règle | Statut |
|---|---|
| Modèle inconnu sur le contrat → exclusion `work_model_unknown` | IR |
| Forfait jours rémunéré à l'heure → exclusion `work_model_compensation_mismatch` ; création de contrat refusée (422) | IR |
| Forfait jours avec heures supplémentaires sur la feuille → exclusion `work_model_overtime_not_applicable` | IR |
| Forfait jours sans feuille verrouillée → motif `work_model_tracking_unsupported` (et non « feuille manquante ») | IR (navigateur, période 29) |
| Forfait heures avec heures supplémentaires → exclusion `work_model_overtime_not_qualified` | IR |
| Temps partiel au-delà de son contrat (par semaine ISO) → exclusion `part_time_hours_beyond_contract` | IR |
| Horaire temps plein, temps partiel dans son contrat, forfaits sans dépassement → préparés **comme avant** | IR |
| Régime affiché à côté des exclus (ADR-825) | EV |

## 5. Règles non implémentées

- Calcul des heures complémentaires.
- Seuil du forfait heures.
- Relevé de jours et nombre de jours du forfait.
- Contrôle d'éligibilité au forfait jours par Market.
- Suivi de la charge de travail.

Statut **NR** : décisions D-1 à D-6.

## 6. Fiabilisation de la préparation de paie

Chemin audité : contrat → modèle → suivi → validation → préparation → export.

- **Traitements communs** : feuille verrouillée couvrant la période ; plan de rémunération actif ; base selon le type de rémunération (`monthly`, `hourly`, `daily`).
- **Hypothèses implicites trouvées** :
  - le seuil hebdomadaire des heures supplémentaires est la **référence temps plein**. Un temps partiel à 24 h qui en travaille 30 n'avait **aucune heure signalée** : 6 h non payées sur un salaire mensuel ;
  - les heures supplémentaires étaient valorisées quel que soit le modèle.
- **Conversion d'unités** : les jours du type `daily` sont comptés sur les lignes de plus de 0 min. Les heures mensuelles valent hebdo × 52 / 12 (inchangé).
- **Correction** : exclusion explicite (section 4), sans aucun changement de montant pour les lignes préparées.
- **Ce qui n'a pas changé** :
  - la justification reste obligatoire ;
  - le blocage par une anomalie de calcul est intact (ADR-824, tests existants) ;
  - les périodes historiques ne sont pas recalculées.
- **Règle 3.4** : l'absence de feuille n'est plus présentée comme une absence de travail pour un forfait jours. Elle a son propre motif. La traçabilité est conservée : sans justificatif, pas de ligne.

## 7. Compatibilité contrat et rémunération

| Combinaison | Comportement |
|---|---|
| Modèle hors liste | refus à la création (422) ; exclusion en paie |
| `forfait_jours` + `hourly` | refus à la création (422, motif lisible) ; exclusion en paie |
| `forfait_jours` + `monthly` ou `daily` | accepté |
| `horaire` / `forfait_heures` + tout type | accepté (pas de règle validée pour en refuser) |
| Type de contrat (CDI, CDD, stage, alternance, freelance) | sans effet sur les règles de temps : conforme |

Les contrats existants ne sont pas modifiés. La cohérence d'un contrat existant est diagnostiquée en paie.

## 8. Diagnostic `on_site`

- **Champ** : `workforce_employment_contracts.work_model_key`, une chaîne libre en base (migration `2026_05_03_000004`), validée seulement par l'API.
- **Valeur** : `on_site` appartient au champ personnalisé « Mode de travail » (`work_mode` : `on_site` / `hybrid` / `remote`, dans `FieldDefinitionCatalog`). C'est un **lieu de travail**, pas un modèle de temps.
- **Origine probable** :
  - la société 21 « C2 Recette Co » est une fixture de recette créée hors code versionné, le 2026-11-01 (contrat 22) ;
  - les fixtures PHPUnit historiques (`WorkforcePayrollTest`, `WorkforcePlanningTest`) écrivent `on_site` directement en base.
- **Consommateurs** : la préparation de paie (désormais exclusion `work_model_unknown`), le contrôle de conformité (traité comme un régime horaire), les lectures de fiche et de registre.
- **Valeur attendue** : à confirmer par la société, aucune déduction possible.
- **Remédiation préparée** : `php artisan workforce:work-model-remediate`.
  - diagnostic par défaut ;
  - `--contract=<id> --set=<modèle> --apply --confirm-database=<base>` ;
  - audit par lot ;
  - `--restore=<lot>`.
- Diagnostic DEV : 1 contrat, société 21, **non modifié** (`workforce-3-6/work-model-diagnostic-dev.json`). Correction réelle : décision D-9.

## 9. UX Workforce

Formulaire de contrat (`_EmployeeContract.vue`, fiche unifiée) :
- forfait jours : pas de durée hebdomadaire, pas de rémunération horaire ;
- forfait heures : la durée devient « volume hebdomadaire du forfait » ;
- une ligne d'explication par modèle ;
- le motif d'un refus serveur est affiché.

Les règles légales restent dans les règles Market, rien n'est déplacé vers JobDomain. **IR** : capture `workforce-3-6/contract-form-days.jpg`, champ masqué vérifié.

## 10. Navigation des panneaux (D-7)

- Ligne de paie et Parrainage, ouverts depuis la liste :
  - une entrée d'historique marquée est ajoutée (option native `state` de Vue Router) ;
  - Précédent ferme le panneau et Suivant le rouvre ;
  - la fermeture revient à l'entrée de la liste.
- Ouverts par l'adresse : aucune entrée n'est ajoutée, donc pas de boucle.
- Aucun état global. Les onglets des hubs restent régis par ADR-475 (`replace`) : **pas de conflit**, l'ADR-475 ne couvre pas les panneaux.

Recette navigateur de la ligne de paie (**IR**) :

| Étape | Panneau | Adresse |
|---|---|---|
| Ouverture depuis la liste | ouvert | `?line=22` |
| Précédent | fermé | `/27` |
| Suivant | ouvert | `?line=22` |
| Rechargement | ouvert | `?line=22` |
| Fermeture | fermé | `/27` |
| Réouverture | ouvert | `?line=22` |
| Fermeture puis Précédent | — | liste des périodes |
| Adresse directe | ouvert | `?line=22` |
| Fermeture puis Précédent | — | page précédente, sans boucle |
| Ligne inconnue | fermé | adresse nettoyée |

- Permission refusée : chauffeur → 403 (recette 3.5, **EV**).
- Changement de sélection : **non recetté**, une seule ligne par période sur le clone.
- Parrainage : **INR**, module non activable sur la société fictive sans droit commercial.

## 11. Audit temps réel

La pile est **SSE** (`RealtimeStreamController`, `RealtimeClient`, `ConnectionTracker`). **Il n'y a ni Reverb ni Echo** dans le dépôt. Observations reproductibles : `workforce-3-6/REALTIME-OBSERVATIONS.md`.

- **Les « 30 s » du rapport 3.5 ne viennent ni de la détection de fermeture d'onglet, ni d'une fuite côté client.**
  - Sous PHP-FPM, `max_execution_time` vaut 30. Chaque flux était **tué** au bout de 30 s, alors qu'il est conçu pour durer `stream_timeout` (300 s).
  - Journal DEV : **22 993 erreurs fatales**, 29 541 connexions pour 259 déconnexions journalisées. Le navigateur se reconnectait toutes les 33 s, et le pool PHP-FPM local atteignait sa limite de 5 processus.
  - Le désenregistrement ne s'exécutait jamais.
- **Correctif** :
  - limite d'exécution du flux alignée sur `stream_timeout` + 15 s (bornée) ;
  - désenregistrement garanti par une fonction d'arrêt ;
  - `ignore_user_abort` pour terminer proprement.
  - Les délais `heartbeat` et `stream_timeout` sont **inchangés**.
  - Reproduit sur le clone (`-d max_execution_time=30`) : avant, 2 connexions / 0 déconnexion / 1 erreur fatale ; après, 2 / 2 (durées 40 s et 26 s) / 0 erreur.
- **Connexions multiples** : un rechargement ouvre le nouveau flux avant que l'ancien soit détecté. Le premier flux terminé désenregistrait le flux vivant : compté 0, invisible à la supervision. Les flux sont désormais **comptés par utilisateur et société**. Reproduit et testé (identifiants fictifs).
- **Client** : un seul `EventSource` par session (garde singleton), fermé à la déconnexion. Aucune fuite au changement de route.
- **Sessions expirées** : le flux répond 401 et le client applique son repli (non modifié, **AU**).
- **Serveur intégré `php -S`** (recette uniquement) : avec un battement de 30 s, une fermeture d'onglet n'est pas détectée en 200 s. Comportement propre à ce serveur, sans incidence en production.

## 12. Plan RBAC chauffeur

Propriétés vérifiées (tests ADR-825, 2/2 ; simulation DEV du 18/11, 852 lignes de pivot avant et après) : idempotente, traçable (audit par lot), réversible (`--restore`), limitable par société, rôles personnalisés sautés, droits de livraison jamais retirés.

1. **Sauvegarde** de la base, puis vérification de la restauration de la sauvegarde.
2. **Sociétés** : 8 rôles `would_apply` sur DEV.
   - 1 « Leezr Logistics » : 12 membres ;
   - 101 « E2E Recette » : 1 membre ;
   - 2, 3, 31 à 34 : 0 membre.
3. **Pilote proposé** : les sociétés sans membre (2, 3, 31 à 34), puis 101 (recette, 1 membre), puis 1.
4. **Droits avant / après** : simulation archivée, application `--company=<id>`, puis recette de la spec 3.5 (livraisons 200 ; annuaire et documents 403).
5. **Contrôle des accès** : parcours chauffeur (Mes livraisons, Mon espace, Mon activité) et inter-tenant.
6. **Communication** : informer les sociétés que leurs chauffeurs ne voient plus l'annuaire ni les documents de la société.
7. **Extension** progressive, société par société.
8. **Surveillance** : journal `role.remediation_applied` et 403 inattendus.
9. **Restauration** : `--restore=<lot> --apply --confirm-database=<base>`.

**Non appliqué** : aucune autorisation dans cette mission (D-8).

## 13. Audit de pré-déploiement (depuis la production `15656c4`, 199 commits)

| Sujet | Constat | Statut |
|---|---|---|
| Migrations | 45 nouvelles, aucune destructive dans `up()`. 7 modifient des données ou des types : DMS (sujets polymorphes, `->change()`), onboarding (`preparatory_at`), permissions plateforme (ajout), jobdomain logistique (`signup_available`, `allow_custom_fields`). | **Prêt sous condition** : sauvegarde, et migrations en maintenance |
| Migration historique modifiée | `2026_10_30_150000` : noms d'index raccourcis après coup (limite MySQL de 64 caractères) | **Bloquant à vérifier** : état de cette migration et de ses index en production (lecture seule de la table `migrations`) |
| Variables d'environnement | 18 nouvelles : scanner antivirus (`DOCUMENT_SCANNER=none`, refus par défaut), signature externe (désactivée), `JOBDOMAIN_VERSIONED_INIT` (activé par défaut, repli si aucun preset publié), délais d'invitation et de rétention | **Prêt sous condition** : `.env` de production à compléter |
| Presets publiés | L'initialisation versionnée utilise les presets publiés, sinon le chemin historique | **Prêt sous condition** : publication des presets logistique en production |
| Planificateur | 6 nouvelles tâches (expirations, analyse des pièces, rétention, purge des parrainages). Constat antérieur : planificateur absent en production | **Bloquant** : planificateur à installer |
| Workers | Constat antérieur : workers non séparés par environnement | **Bloquant** : séparation des files avant déploiement |
| Temps réel | Flux tués à 30 s sous PHP-FPM, corrigé (ADR-826). `max_execution_time` de production à vérifier ; pool FPM à dimensionner (un processus par onglet connecté) | **Prêt sous condition** |
| RBAC chauffeur | Remédiation prête, non appliquée | **Décision métier** (D-8) |
| Paie multi-modèles | Exclusions explicites. Forfait jours, temps partiel et forfait heures **non calculés** au-delà des cas simples | **Prêt sous condition** : communication de la limite |
| Contrat `on_site` | Fixture DEV uniquement | **Hors périmètre** de la production (à vérifier en lecture seule) |
| Billing V2 / Stripe | Drapeaux désactivés, clés de bac à sable absentes (constat antérieur) | **Hors périmètre** de 3.6 (bloquant commercial connu) |
| Isolation multi-tenant | Recette inter-tenant 403 (paie, livraisons, annuaire) | **Prêt** |
| Navigation, panneaux | Recettés | **Prêt** |
| Retour arrière | Remédiations réversibles par lot ; `JOBDOMAIN_VERSIONED_INIT=false` ; migrations avec `down()` | **Prêt sous condition** : retour arrière des données migrées non testé |

**Conclusion** : Leezr **n'est pas prêt** pour un déploiement en production. Quatre points bloquants restent ouverts :
- le planificateur ;
- la séparation des workers ;
- la vérification de la migration modifiée ;
- la décision sur la remédiation RBAC.

## 14. Tests et E2E

- Nouveaux tests :
  - `Adr826WorkModelPayrollTest` : 11 salariés, moteur réel ;
  - `Adr826ContractCoherenceTest` : 2 tests ;
  - `Adr826RealtimeConnectionTrackerTest`.
- Ciblés : paie 207 ; contrats 144 ; temps réel 90 ; remédiation 2.
- **Suite complète : 4 717 réussis, 12 sautés, 4 échecs.**
  - 3 échecs `ControllerSizeInvariant` historiques : aucun contrôleur modifié par 3.6 n'y figure.
  - **1 nouvel échec, corrigé** : `TenantScopeBypassGuardTest` signalait un accès non borné dans `workforce:work-model-remediate`. Commit `1b9dd450` ; le garde et les tests de cohérence repassent (4/4).
- `pnpm build` : propre.
- E2E sur le clone :
  - specs historiques, paie et contrat : 21 réussis, 2 sautés (laboratoire dev-only ; un cas d'annulation sans donnée) ;
  - 2 échecs analysés :
    - `workforce-contract` : adresse et bouton obsolètes depuis la fiche unifiée, spec réalignée (`c51b6a39`), 3/3 ;
    - `workforce-payroll-workflow` : 422, la période existait sur le clone parce que la réinitialisation avait visé le DEV (section 18). Rejouée sur le clone : 2/2 avec `payroll-c1-prep`, DEV inchangé (empreinte identique avant et après).

## 15. Recettes navigateur (clone, société fictive 109)

Période d'octobre 2026 (n° 29), salariés et contrats créés par l'API. Les feuilles verrouillées sont des **données d'entrée** posées sur le clone ; aucun résultat de paie n'a été fabriqué.

| # | Scénario | Résultat |
|---|---|---|
| 1 | Horaire | préparé (154 h, 2 500 €) |
| 2 | Temps partiel dans le contrat | préparé |
| 2b | Temps partiel au-delà (30 h pour 24 h) | exclu, temps partiel |
| 3 | Forfait heures avec dépassement | exclu |
| 4 | Forfait jours sans heures supplémentaires | préparé |
| 4b | Forfait jours avec heures supplémentaires | exclu |
| 4c | Forfait jours sans feuille | motif propre |
| 5 | Modèle inconnu (`on_site`) | exclu ; création refusée (422) |
| 6 | Données incomplètes (salarié sans contrat) | exclu, feuille manquante (le contrôle de la feuille passe d'abord) |
| 7 | Sans feuille | exclu |
| 8 | Feuille verrouillée | préparé |
| 9 | Période avec exclusions | 28 exclus, 3 lignes |
| 10 | Anomalie de calcul bloquante | non reproduite ici (cotisations 2026 présentes) ; couverte par les tests ADR-824 (**EV**) |
| 11 | Justification obligatoire | note vide → 422 |
| 12 | Validation autorisée | avec justification → 200 |
| 13 | Validation refusée | chauffeur → 403 ; autre société → 403 |
| 14 | Export autorisé | 200 |

Preuves : `workforce-3-6/clone-payroll-cycle.json`, `payroll-run-exclusions.jpg`, `contract-form-days.jpg`.

## 16. Risques résiduels

- Le moteur reste horaire : forfait jours, temps partiel et forfait heures ne sont **préparés que dans les cas simples**. Les autres cas sont exclus ; ils restent visibles, mais la paie de ces salariés doit être faite hors de Leezr.
- Une période déjà calculée avant ADR-826 n'est pas recalculée : ses lignes de forfait peuvent contenir des heures supplémentaires valorisées.
- Les contrats existants `forfait_jours` + `hourly` ne sont pas modifiés ; ils sont exclus en paie.
- Temps réel :
  - chaque onglet connecté occupe un processus PHP-FPM pendant la durée du flux (300 s) ;
  - le dimensionnement du pool en production est à vérifier ;
  - le compteur des clés existantes au déploiement se rétablit par expiration (10 min).
- Parrainage, panneau D-7 : non recetté au navigateur.

## 17. Décisions métier encore nécessaires

- **D-1** : forfait jours, quel justificatif (relevé de jours) ?
- **D-2** : forfait jours, le nombre de jours doit-il figurer au contrat ?
- **D-3** : temps partiel, heures complémentaires (règles Market : limite, majorations).
- **D-4** : forfait heures, seuil de dépassement.
- **D-5** : éligibilité au forfait jours par Market.
- **D-6** : suivi de la charge de travail.
- **D-8** : exécution de la remédiation RBAC (pilote, calendrier, communication).
- **D-9** : valeur réelle du contrat 22 de la société 21 (fixture), ou suppression de la fixture.
- **D-10** : dimensionnement PHP-FPM et `max_execution_time` en production.
- **D-11** : levée des bloquants de pré-déploiement (planificateur, workers, migration modifiée).
- **D-12** : borner les commandes `e2e:*` (refus si la base n'est pas une base de recette déclarée).
- **D-13** : autoriser la restauration de la paie d'avril 2026 de la société 1 sur le DEV (section 18).

## 18. Incident : écriture non autorisée sur le DEV, société 1

- **Fait.** La spec `workforce-payroll-workflow` lance dans son `beforeAll` deux commandes locales : `php artisan e2e:reset-payroll --company=1` et `e2e:prepare-payroll --employee=3 --company=1` (avril 2026). Je l'ai exécutée contre le clone **sans** forcer la base des commandes locales : ces commandes ont visé la **base DEV**, ce qu'interdit la mission.
- **Impact mesuré (DEV, société 1)** :
  - supprimés : la période de paie 11 (avril 2026, exportée), sa ligne 9, son calcul 9 et l'instantané de cumul annuel 9 (cascades) ;
  - remplacée : la feuille verrouillée 17 du salarié 3 (avril, sans ligne), par la feuille 143.
  - Aucune autre donnée n'est touchée. Les autres specs passent par le registre E2E, qui ne supprime qu'avec une preuve de propriété : aucun effet possible sur le DEV.
- **Restauration préparée, NON exécutée** (écriture sur la société 1 non autorisée) : `workforce-3-6/incident/restore-dev-company1-april2026.sql`.
  - transaction unique ;
  - suppression de la feuille 143 ;
  - réinsertion des 5 lignes d'origine, copiées depuis le clone `leezr_audit821` (copie du DEV antérieure à l'incident).
- **Prévention** : les configurations de recette forcent désormais `DB_DATABASE=leezr_audit821` pour les processus lancés par les specs. Les commandes `e2e:*` restent destructives sur la base locale de `.env` (décision **D-12** : les borner explicitement).
- **Décision requise (D-13)** : autoriser l'exécution de la restauration sur le DEV.
