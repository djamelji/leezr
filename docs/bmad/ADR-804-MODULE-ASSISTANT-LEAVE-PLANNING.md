# ADR-804 — Assistant commun : Congés et Planning

> 2026-11-16 · DEV uniquement · base `9df323fa` · aucun push.
> Pilotes : `workforce_leave` et `workforce_planning`. Aucun autre module n'est touché (pas de généralisation automatique).

## 1. Ce qui a été réutilisé (aucun nouveau moteur)

| Besoin | Réutilisé tel quel |
|---|---|
| « Qui peut faire quoi » | `CapabilityAccessService` (ADR-803.1) : capacités = bundles du manifeste |
| Délégation | `MembershipSecurityPolicy` : codes `operational_role`, `beyond_your_rights`, `own_role`, etc. |
| Assistant | `ModuleSetupService`, `_ModuleSetupWizard.vue`, `ModuleCapabilityAccess.vue`, `CapabilityChangePreview.vue` |
| Édition ultérieure | Équipe → Rôles « par capacité » : même composant, même moteur |
| Types de congés | API existante `GET /workforce/leaves/types` et écran Congés → Configuration (aucun formulaire dupliqué) |
| Cadre de temps de travail | lu depuis Workforce (`convention_confirmed_at`) : jamais recopié dans le Planning ni dans le JobDomain |
| Activation, facturation | `ModuleActivationEngine` et devis existants, sans changement |

## 2. Contributions ajoutées aux deux modules (déclaratives)

**Congés** (`WorkforceLeaveModule`) :
- `setup: { steps: [access, leave_types], readiness: LeaveSetupReadiness }` ;
- bundles :
  - `leave_employee` a `scope: own` (ses propres demandes) ;
  - `leave_management` a `is_admin` (le serveur refuse déjà ces droits aux rôles opérationnels) ;
  - `leave_adjustment` a `unwired` : la permission `leave_adjust` n'est lue par aucune route, donc la capacité n'est visible qu'en mode avancé, avec un avertissement.

**Planning** (`WorkforcePlanningModule`) :
- `setup: { steps: [access], readiness: PlanningSetupReadiness }` : aucun réglage obligatoire, donc aucune étape inutile ;
- bundles :
  - `planning_employee` a `scope: team` (`planning_view` donne soi-même et ses subordonnés directs) ;
  - `planning_management` a `is_admin`.

**Vérifications de disponibilité** (`required` ou recommandation) :

| Module | Vérification | Obligatoire ? |
|---|---|---|
| Congés | accès décidés | oui |
| Congés | au moins un type de congé actif | oui |
| Congés | un rôle non propriétaire peut valider | recommandation |
| Planning | accès décidés | oui |
| Planning | cadre de temps de travail confirmé dans Workforce | recommandation, lu, jamais dupliqué |
| Planning | un rôle non propriétaire peut publier | recommandation |

Une recommandation n'est **jamais bloquante** et ne rend jamais le module « À revoir ».

## 3. Parcours avant / après

**Avant** : on activait le module, puis rien. Les droits se réglaient dans la matrice des rôles, permission par permission. Les types de congés étaient créés en silence, sans que personne ne sache qu'il fallait les vérifier. Le planning était actif sans aucune indication sur qui peut publier.

**Après** :
1. Activer le module (devis inchangé ; le module Workforce est entraîné par la dépendance).
2. Ouvrir l'assistant :
   - **Accès** : capacités par rôle, aperçu obligatoire, puis application. Aucun droit n'est attribué à l'activation.
   - **Congés seulement** : récapitulatif des types actifs, avec ou sans validation, et un lien « Gérer les types de congés ».
3. **Confirmation** : obligations, puis recommandations. Les dépendances sont affichées avec leur état (ex. « Workforce (À configurer) »).
4. Le module est **Prêt**.
5. Pour revenir plus tard : même assistant, ou Équipe → Rôles. Toute modification remet le module en « Configuration en cours » jusqu'à une nouvelle confirmation.

## 4. Fonctions conservées

Tout est conservé :
- matrice par permission (mode avancé) ;
- écran Congés → Configuration ;
- types semés à l'activation ;
- `requires_approval` par type ;
- routes historiques ;
- droits existants, jamais revus automatiquement ;
- assistant Workforce (ADR-803).

## 5. Règles réellement appliquées (vérifiées)

**Droits et périmètres**
- Personnel, gestion et validation restent distincts :
  - `leave_request` donne ses propres demandes ;
  - `leave_approve` porte sur toute l'entreprise ;
  - `leave_manage` gère les types.
- Le serveur refuse un droit qu'on ne détient pas : un agent reçoit 403 pour la validation, la gestion des types, le planning et l'assistant.

**Le module Congés désactivé coupe vraiment ses écrans**
- Les 19 routes des congés (gestion et libre-service `me/leaves`, `me/leave-balances`) exigent désormais `use-module,workforce_leave`. Auparavant, elles ne dépendaient que de `workforce`.
- Le bloc « Mes congés » de Mon activité suit la même règle.

**Aucun contrôle présenté comme opérationnel sans être exécuté**
- La capacité `leave_adjustment` est inerte : elle est signalée et cachée hors mode avancé.
- Aucune règle de temps de travail n'est ajoutée au Planning.

**Faux positifs « À revoir » supprimés**
- `ModuleReadiness::settingsFingerprint()` hache la configuration **effective** :
  - Workforce : valeurs résolues des règles, semaine, convention, cadre confirmé ;
  - Congés : `[code, requires_approval, is_paid]` des types actifs.
- Cette empreinte est enregistrée à la confirmation (`company_module_setups.settings_fingerprint`, migration additive).
- Réécrire la même valeur, ou reconfirmer le même accord, ne rend plus le module « À revoir ». Seul un changement effectif le fait.
- L'heuristique par horodatage (`staleReasons`) est supprimée.

**Catalogue des modules à jour après activation**
- Le cache `sessionStorage` du catalogue (SWR, 5 min) est vidé à chaque activation ou désactivation.
- Avant cette correction, recharger la page juste après une réactivation renvoyait vers la liste avec « Module non actif ». C'est un défaut préexistant, découvert pendant la recette.

## 6. Doublons éliminés ou évités

- Aucun second formulaire de types de congés : l'étape est un récapitulatif avec un lien vers l'écran existant.
- Aucune copie du cadre de temps de travail dans le Planning : lecture seule de Workforce.
- « Sensible » et « gestion uniquement » ne sont plus confondus :
  - `sensitive` vient seulement d'une déclaration explicite ;
  - `is_admin` s'affiche comme le badge « Rôles de gestion uniquement ».

## 7. Tests

**PHPUnit**
- `Adr804LeavePlanningSetupTest` (10 tests) couvre :
  - étapes adaptées à chaque module ;
  - périmètres et contraintes des capacités ;
  - droits distincts appliqués par HTTP ;
  - même politique de délégation ;
  - capacité partielle et multi-company ;
  - type actif obligatoire et recommandation non bloquante ;
  - absence de faux positif et changement effectif signalé ;
  - Planning prêt après les seuls accès ;
  - réactivation qui conserve types et droits ;
  - routes congés coupées quand le module est désactivé.
- Tests ADR-803 et ADR-803.1 adaptés : `workforce_documents` sert désormais d'exemple de module sans contrat, et l'empreinte effective remplace `framework_changed`.

**E2E sur une société fictive isolée** (`e2e-8031-*`, utilisateurs `@leezr.invalid`)
- Parcours :
  - Congés : activation, accès, types, confirmation, Prêt ;
  - Planning : accès, confirmation, Prêt ;
  - modification depuis l'assistant ;
  - désactivation puis réactivation : types et droits conservés, nouvelle confirmation ;
  - affichage mobile en mode sombre, sans défilement horizontal.
- Contrôles HTTP :

  | Rôle | Liste des congés | Validation | Planning | Types de congés | Assistant |
  |---|---|---|---|---|---|
  | Chef | 200 | 404 (demande inconnue) | 200 | 422 | — |
  | Agent | 200 | 403 | 403 | 403 (gestion) | 403 |

- Société 1 inchangée : instantané avant et après identique.
- Nettoyage par identifiants exacts.

**Données de facturation de la fixture**
- Les deux modules sont des add-ons payants (19 € et 29 €). L'activation réelle a donc créé, pour la société fictive, des abonnements add-on et des factures « open ».
- Ces lignes ont été supprimées avec la fixture, après avoir vérifié qu'aucun paiement ni avoir n'existait.
- Aucune donnée de facturation d'une société réelle n'a été touchée.

**Tests existants adaptés à la garde de module**
- `WorkforceSecurityWDTest` et `WorkforceTimesheetWorkflowTest` utilisaient les routes congés sans le module Congés.
- Leur setup active désormais `workforce_leave` ; aucune assertion n'a changé.

**Suite complète** : 4570 réussis, 12 ignorés, 3 échecs.
- Les 3 échecs sont dans `ControllerSizeInvariantTest`.
- Leur liste de 24 contrôleurs fautifs est identique à celle de la suite ADR-803.1 (comparaison des journaux).
- Aucun nouvel échec. Build `pnpm build` propre.

Captures : `docs/bmad/adr804/`.

## 8. Risques restants

- La recommandation « validateur » ne distingue pas un vrai circuit d'approbation : le seul circuit réel est `requires_approval` par type. Aucun moteur d'approbation n'a été ajouté (hors périmètre).
- `leave_adjust` reste une permission inerte. Il faut décider : la brancher (ajustement de solde) ou la retirer. Pour l'instant, elle n'est que cachée.
- Une entreprise qui utilisait les congés avec Workforce seul, sans le module Congés actif, perd l'accès aux routes congés. C'est cohérent avec le catalogue. Mesure DEV (lecture seule) : 2 sociétés (21, 22) ont Workforce sans Congés, et 0 demande de congé chacune, donc aucune perte observée. À re-mesurer en PROD avant déploiement.
- Les entreprises existantes restent en `legacy` : aucune migration de statut.

## 9. Méthode minimale pour adapter un autre module

1. **Auditer** ses bundles. Pour chaque capacité, déclarer :
   - `scope` (`own` / `team` / collectif) d'après le périmètre réel appliqué par le serveur ;
   - `is_admin` si la politique la refuse aux rôles opérationnels ;
   - `unwired` si aucune route ne lit la permission ;
   - `sensitive` seulement si c'est explicite.
2. **Lister** les réglages réellement obligatoires. S'il n'y en a aucun, `steps: ['access']`.
3. **Écrire une classe `ModuleReadiness`** :
   - `checks()` ne vérifie que ce qui est exécuté ; une recommandation porte `required: false` ;
   - `settingsFingerprint()` couvre la configuration effective, ou `null`.
4. **Étapes de réglage** : enregistrer un composant dans `moduleSetupSteps.js` qui RÉUTILISE l'écran métier (récapitulatif et lien, ou composant existant). Ne jamais dupliquer un formulaire.
5. **Vérifier** que les routes du module sont gardées par `use-module,<module>`.
6. **Tester** : contrat, absence de droit à l'activation, délégation, empreinte sans faux positif, réactivation, HTTP négatif, E2E sur une fixture isolée.
