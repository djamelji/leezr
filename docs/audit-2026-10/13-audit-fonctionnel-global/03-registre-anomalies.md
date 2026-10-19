# Registre des anomalies — audit fonctionnel global

> Priorités : **P0** sécurité/isolation/facturation erronée/corruption/accès illégitime · **P1** fonctionnalité essentielle cassée / incohérence métier / info trompeuse · **P2** dysfonctionnement secondaire / UX · **P3** cosmétique/dette. Statut : ✅ corrigé · 🔎 à confirmer · ⏳ ouvert.

## Nouveaux findings (cet audit)

### ANO-001 — « Prochaine facture estimée » dans le passé — **P1** ✅
- **Périmètre / page** : Company Billing — `/company/billing/plan` (onglet Plan) **et** `/company/billing` (onglet Overview).
- **Repro** : `owner@leezr.test` → affichait « Prochaine facture estimée : 31 mai 2026 » (≈4,5 mois passés).
- **Attendu** : une date future, ou un état clair « renouvellement en attente » ; ne jamais présenter une échéance passée comme estimation future.
- **Observé** : `current_period_end` (2026-05-31) affiché verbatim.
- **Cause racine (démontrée)** : seeders (`DevSeeder`/`BillingDemoSeeder`) créaient des abos `active` **sans `is_current`** → `billing:renew` (`where is_current=1`) les saute → période figée ; le read service émet la date sans clamp. Détail : `04-anomalie-prochaine-facture.md`.
- **Correction** : `is_current=1` à la source ; `renewal_overdue` exposé par `nextInvoicePreview` (date = source de vérité, non fabriquée) ; UI « Renouvellement en attente » sur **les deux** onglets.
- **Tests** : `BillingNextInvoicePreviewTest` (future/past), `SubscriptionRenewalEligibilityTest` (skip/advance).
- **Finalisation** : commande `billing:regularize-current-subscriptions` (dry-run par défaut, **aucune facturation**, respecte l'unicité `(company_id,is_current)`, idempotente) pour régulariser les abos actifs `is_current=NULL` existants (prod). Tests `RegularizeCurrentSubscriptionsTest` (4).
- **Risque données** : aucun (pas de facture/écriture modifiée). Régularisation PROD = action ops traçable documentée.

### ANO-004 — Formulaire de profil Company non-sauvegardable (bouton gelé) — **P1** ✅
- **Périmètre / page** : Company — `/company/profile/overview`.
- **Repro déterministe (Playwright local)** : charger la page, modifier « Nom de l'entreprise » → le bouton **« Enregistrer les modifications » reste désactivé avant ET après saisie** (`BTN_DISABLED_AFTER_FILL=true`), aucun message de validation → **aucune requête PUT** → **profil non modifiable depuis l'UI**.
- **Cause racine (démontrée)** : `const isFormValid = ref(false)` (v-model du `VForm`) + bouton `:disabled="!isFormValid"`. Vuetify ne bascule `isFormValid` à `true` qu'après une validation déclenchée ; initialisé à `false`, le bouton reste gelé indéfiniment.
- **Correction** : bouton gardé par le tracker déjà présent `:disabled="!isDirty || isLoading"` ; la validation reste assurée au submit (`handleSave` → `formRef.validate()`). (`isFormValid` conservé comme v-model.)
- **Portée** : anti-pattern **isolé** (recherche `:disabled="!isFormValid"` = 1 occurrence ; autres `:disabled="!...valid"` sont des `computed` réactifs, ex. `_EmailCompose`, non gelés).
- **Test de non-régression [NAV]** : `tests/e2e/profile-crud.spec.js` (fill → bouton **enabled** → PUT `/api/company` 2xx → reload → valeur **persistée**). Vérifié vert.
- **Découverte** : trouvée **par la campagne E2E CRUD** (invisible aux tests PHPUnit — validation front).

### ANO-003 — Modules sellable (Workforce/logistics) invendables en PROD — **P1** ✅
- **Périmètre** : Company Modules / entitlements — tous les add-ons `type:addon`.
- **Repro** : PROD `owner@leezr.test` (pro+logistique) → Workforce `is_entitled=false`, `display_state=contact_sales`, 0 module activé ; staging → activé (DevSeeder). « marche en dev, pas en prod ».
- **Attendu** : un add-on correctement configuré (vendable/inclus) activable par un client entitled, **identiquement** prod et staging (config catalogue = donnée produit).
- **Cause racine (démontrée)** : la config commerciale (`is_sellable` + `addon_pricing`) vivait **uniquement dans `DevSeeder`** (dev-only). `SystemSeeder` (prod) ne la posait pas → `EntitlementResolver=not_available` → `contact_sales` → invendable/inactivable en prod. La divergence de seeders **masquait** la panne (staging force-activait). `DependencyResolver::canActivate` (qui ignore l'entitlement) avait initialement masqué le vrai état.
- **Correction** : extraction vers `ModuleProductConfigSeeder` (idempotent, données produit) + appel dans `SystemSeeder` → prod cohérent. Workforce : `contact_sales` → `available`. Valeurs de prix **existantes** (non inventées) ; promotion prod = validation produit (commit local, non déployé).
- **Tests** : `WorkforceModuleActivationJourneyTest` (5).
- **Finalisation** : `ModuleProductConfigSeeder` rendu **non destructif** (initialise seulement si `addon_pricing` absent → n'écrase pas une config commerciale admin légitime) + idempotent.
- **Risque données** : aucun (données produit catalogue, pas tenant ; pas de modif financière).

### ANO-005 — Harnais E2E « faux-vert » : pages 404 validées comme saines — **P1 (harnais)** ✅
- **Périmètre** : `tests/e2e/smoke.spec.js` (validation fonctionnelle).
- **Repro** : les chemins company du smoke étaient **bare** (`/members`, `/billing`, `/workforce`…) alors que les vraies routes sont **`/company/*`** (sauf `/dashboard`). Idem platform (IA groupée en `:tab` : markets→`/platform/international/*`, etc.). → la plupart renvoyaient une **page 404** que `assertScreenClean` validait **sans la détecter** (une 404 n'a ni clé i18n brute ni erreur console).
- **Impact** : la « validation fonctionnelle » antérieure (19–20 pages « vertes ») était **largement illusoire** pour les pages company/platform (exactement « page qui s'affiche ≠ parcours valide »).
- **Correction** : `assertScreenClean` **échoue désormais sur une 404** (`Page introuvable`) ; chemins corrigés vers les vraies routes ; filtrage documenté des 401/429 **transitoires** (course auth post-login / rate-limit sous navigation rapide — pages rendues, non-défaut). Suite E2E : **18 verts honnêtes** (dont 2 CRUD à persistance).

### ANO-007 — Self-service congé inaccessible au salarié (bouton → 403) — **P1** ✅
- **Périmètre** : Employé self-service — `/company/workforce/me`, bouton « Demander un congé ».
- **Repro déterministe** : `bob@leezr.test` (salarié, rôle Driver) → `/me` charge ; clic « Demander un congé » → **navigue vers `/company/403`**. Le bouton routait vers la **page admin** `/company/workforce/leave` (gardée `workforce.leave_request`), que le salarié n'a pas → **le salarié ne pouvait pas demander de congé via l'UI**, alors que l'endpoint self-service `POST /workforce/me/leaves` est **ouvert à tout employé actif**.
- **Cause racine** : `me.vue` `@click="router.push({name:'company-workforce-leave'})"` — délègue à une page à permission au lieu d'un formulaire self-service.
- **Correction** : drawer **self-service** sur `/me` (sélecteur de type + dates + jours + motif) appelant `POST /workforce/me/leaves` via `useWorkforceClockStore.createLeave` ; `data-test` sur les champs Vuetify. Aucune permission contournée (endpoint déjà ouvert aux employés actifs).
- **Fixture de test** : commande `e2e:prepare-leave-fixture` (local/testing) — accrual congés cohérent pour le salarié démo (contrat actif déjà seedé).
- **Test de non-régression [NAV]** : `workforce-leave-workflow.spec.js` (salarié demande→201, manager approuve→persistant, non-autorisé→403, range inversé→422). Vérifié vert.
- **Découverte** : trouvé par la campagne E2E (le parcours métier réel), invisible aux tests backend (l'API marchait ; le bug était le routage front).

### ANO-008 — Pointage self-service cassé : `clock-in` → 422 (contrat `source` divergent) — **P1** ✅
- **Périmètre** : Employé self-service — `/company/workforce/me`, bouton « Commencer la journée » (widget pointage).
- **Repro déterministe** : `bob@leezr.test` (salarié) → `/me` → clic « Commencer la journée » → **`422 POST /workforce/me/clock-in`**, corps `{"message":"The selected source is invalid.","errors":{"source":["The selected source is invalid."]}}`. **Aucun salarié ne pouvait pointer via l'UI** (le pointage était entièrement bloqué à la première action).
- **Cause racine** : divergence de **contrat front/back**. Le store `workforce-clock.js` `clockIn()` envoyait `body:{source:'web'}`, mais `EmployeeSelfController::clockIn` valide `source ∈ {manual, mobile, kiosk}` — `'web'` rejeté. Invisible aux tests backend (l'API acceptait `manual` ; le bug était la valeur envoyée par le front).
- **Correction** : `clockIn()` → `body:{source:'manual'}` (le pointage web self-service EST une saisie manuelle). Aucune permission ni règle métier contournée.
- **Test de non-régression [NAV, persistance]** : `workforce-clock-workflow.spec.js` — parcours complet **clock-in → pause → reprise → clock-out**, chaque étape = appel API réel (`<400`) + transition d'état UI ; persistance vérifiée après reload (journée complétée, plus de bouton « Commencer la journée »). Vérifié vert (1/1).
- **Découverte** : trouvée par la campagne E2E (parcours métier réel multi-étapes), typique d'un décalage de contrat front/back que ni les tests backend ni un smoke de navigation ne révèlent.

### ANO-009 — Planning : création manuelle de shift → 422 (`start_at`/`end_at` envoyés en `HH:MM`) — **P1** ✅
- **Périmètre** : Manager — `/company/workforce/planning`, drawer « Nouveau shift » (action principale de planification).
- **Repro déterministe** : `owner@leezr.test` → planning → « Nouveau shift » → employé + date + 09:00/17:00 → **`422 POST /workforce/planning/shifts`**, corps `{"errors":{"start_at":["The start at field must be a valid date."],"end_at":["..."]}}`. **Aucun manager ne pouvait créer un shift via le drawer manuel.**
- **Cause racine** : divergence de contrat. Les inputs `type="time"` émettent `"09:00"`, mais `ShiftController::store` valide `start_at`/`end_at` avec la règle **`date`** et `CreateShiftUseCase` les stocke en **datetime complet** (`Carbon::parse`, overlap sur datetime). `"09:00"` échoue la règle `date` **et** aurait stocké la mauvaise date (aujourd'hui au lieu de la date du shift).
- **Correction** : `planning/index.vue` combine désormais `date + heure` → datetime ISO (`combineDateTime`) avant POST ; symétriquement, le mode édition ré-extrait `HH:MM` (`toTimeInput`) pour alimenter l'input `type="time"` (sinon un datetime complet ne s'affichait pas). Aucune règle métier contournée (overlap, congé, contrat actif toujours appliqués côté serveur).
- **Test de non-régression [NAV, persistance]** : `workforce-employee-lifecycle.spec.js` (étape 3) — shift créé (`<400`) pour un employé réel, persistance vérifiée.
- **Découverte** : campagne E2E (parcours manager réel). Invisible au backend (l'API accepte un datetime complet) et au smoke (aucune soumission de formulaire).

### ANO-010 — Contrat créé en `draft` sans aucun moyen d'activation dans l'UI — **P1** ✅
- **Périmètre** : Manager — fiche employé `/company/workforce/employees/{id}`, onglet Contrat.
- **Repro déterministe** : créer un contrat via le drawer → il naît en **`draft`** (`CreateContractUseCase` → `STATUS_DRAFT`). **Aucune page Vue n'appelait `store.activateContract`** (la méthode + la route `contracts/{id}/activate` existaient, jamais câblées) → le contrat restait `draft` **à vie**. Conséquence : l'employé ne peut **ni être planifié** (règle shift **I4** : « Employee must have an active contract » → 422) **ni être payé** → le parcours embauche→contrat→travail était **cassé à l'étape activation**.
- **Cause racine** : trou d'intégration UI (endpoint + action store présents, bouton absent). `_EmployeeContract.vue` n'affichait que le statut (StatusChip), sans action, et n'émettait rien vers le parent (pas de refresh après création).
- **Correction** : bouton **« Activer »** dans la timeline des contrats pour les contrats `draft` (gardé `v-can="'workforce.contracts'"`) → `store.activateContract(employeeId, contractId)` ; `EmployeeContract` émet désormais `updated` et `[id].vue` re-fetch l'employé (le drawer de création rafraîchit aussi désormais). Clé i18n `employees.contract.activate` (fr/en).
- **Test de non-régression [NAV, persistance]** : `workforce-employee-lifecycle.spec.js` (étape 2b) — activation réelle (`POST .../contracts/{id}/activate`), suivie du shift qui exige le contrat actif (preuve de bout en bout).
- **Découverte** : campagne E2E — c'est la règle métier I4 (shift refusé) qui a révélé que le contrat ne pouvait jamais quitter `draft` via le produit. Ni un test backend (l'endpoint marchait) ni un smoke ne pouvaient le voir.

### ANO-011 — Platform : création d'utilisateur impossible (drawer orphelin, aucun déclencheur) — **P1** ✅
- **Périmètre** : Platform admin — `/platform/access/users` (`_UsersTab.vue`).
- **Repro déterministe** : `admin@leezr.com` → onglet Utilisateurs → **aucun bouton pour ouvrir le drawer de création**. `openCreateDrawer()` (ligne 123) et le `VNavigationDrawer` complet (titre `platformUsers.addPlatformUser`, formulaire, `POST /api/platform/platform-users`) existaient mais **aucun `@click` ne les appelait** → **impossible de créer un utilisateur plateforme via le produit**.
- **Cause racine** : trou d'intégration UI (formulaire + endpoint présents, déclencheur absent) — symétrique d'ANO-010.
- **Correction** : bouton **« Ajouter un utilisateur plateforme »** (`data-test="platform-user-add"`) câblé dans la barre d'actions → `openCreateDrawer`. `data-test` ajoutés sur les champs du drawer (prénom/nom/email/rôles/mot de passe/confirmation/radio credentials/submit) pour un pilotage robuste.
- **Test de non-régression [NAV, persistance]** : `platform-rbac-workflow.spec.js` — création utilisateur + **attribution de rôle** via l'UI (`POST /platform-users` 201), persistance vérifiée après reload.
- **Découverte** : campagne E2E (cartographie des parcours admin RBAC).

### ANO-012 — Changer le rôle d'un membre impossible si profil incomplet (update non partiel) — **P2** ✅
- **Périmètre** : Company — `/company/members/{id}`, formulaire profil membre (changement de rôle).
- **Repro déterministe** : `owner@leezr.test` édite un membre dont des champs dynamiques requis (phone, n° SS, IBAN, type de contrat…) sont **vides**, change uniquement le **rôle**, enregistre → **`422 PUT /api/company/members/{id}`** : `The dynamic fields.phone field is required. (and 7 more errors)`. **Une simple transition de rôle était bloquée** par la validation de champs RH **non touchés**.
- **Cause racine** : le formulaire envoyait **tous** les `dynamic_fields` (y compris les nuls) à chaque sauvegarde, et `UpdateMemberRequest` appliquait les règles `required` (role-aware) sur **tous** les champs → un `PUT` partiel re-validait des champs absents de l'intention de l'utilisateur.
- **Correction** : sémantique **update partiel**. (1) `MemberProfileForm` n'envoie que les champs dynamiques **réellement modifiés** (snapshot initial comparé). (2) `UpdateMemberRequest` ne valide que les champs dynamiques **présents** dans la requête (les champs non soumis ne sont pas re-validés). La validation à la **création** reste stricte (requête séparée). Les champs effectivement soumis restent validés (valeur invalide → 422).
- **Tests de non-régression** : [NAV, persistance] `company-member-lifecycle.spec.js` (créer Driver → changer en Manager → supprimer, + protection owner) ; [API] `MemberRoleAwareFieldTest::test_partial_update_role_only_not_blocked_by_untouched_required_fields`. Suite membres : 88+ verts.
- **Découverte** : campagne E2E (parcours owner réel de gestion des membres).

### ANO-013 — Timesheet : page détail inactionnable (statut `undefined`, aucun bouton) — **P1** ✅
- **Périmètre** : Company — `/company/workforce/time/{id}` (détail feuille de temps).
- **Repro déterministe** : `owner` génère une feuille de temps (draft) → ouvre le détail → le chip de statut affiche `workforce.statuses.timesheet.undefined` et **aucun bouton Soumettre/Approuver/Verrouiller n'apparaît** (ils sont gardés par `v-if="period.status === '…'"`). **Le cycle de validation d'une feuille de temps était impossible via l'UI.**
- **Cause racine** : incohérence de forme de réponse. `GET /workforce/timesheets/{id}` renvoie `{ period, lines, anomalies }` (wrappé) alors que `submit/approve/reject/reopen/lock` renvoient le period **à plat**. Le store faisait `this._currentPeriod = data` dans les deux cas, et la page lit `period.status`/`period.id`/`period.lines` **à plat** → après `fetchTimesheet`, `status` et `id` étaient `undefined` (et après une transition, `lines` disparaissait).
- **Correction** : normalisation dans `timesheets.store.js` — `fetchTimesheet` aplatit (`{ ...data.period, lines, anomalies }`) ; les transitions fusionnent (`{ ...currentPeriod, ...data }`) pour conserver `lines/anomalies`. `currentPeriod` est désormais toujours un period à plat cohérent.
- **Test de non-régression [NAV, persistance, API]** : `workforce-timesheet-workflow.spec.js` — générer → soumettre → approuver → verrouiller ; après lock : **aucun bouton d'action** (UI) **et** l'API refuse une transition (`POST /submit` sur `locked` → **422**) ; statut `locked` persistant. Reset ciblé (`e2e:reset-timesheets`) → rejouable sans `migrate:fresh`.
- **Découverte** : campagne E2E (le parcours manager réel), invisible au backend (les endpoints marchaient) et au smoke (pas de soumission).

### ANO-014 — Faille d'autorisation : API workforce management gardée par le module seul (pas de permission) — **P1 (sécurité)** ✅
- **Périmètre** : **tous** les groupes de routes workforce de **gestion** — `/api/workforce/*` (employés, contrats, timesheets, pointages, congés), **`/api/workforce/planning/*`**, **`/api/workforce/payroll/*`**, **`/api/workforce/dsn/*`**, **`/api/workforce/documents/*`** — `routes/company.php`.
- **Repro déterministe (prouvée)** : connecté en `bob@leezr.test` (simple salarié, rôle Driver, qui a le module workforce pour `/me`), appels API directs avec la session réelle : `GET /api/workforce/employees` → **200**, `POST /api/workforce/timesheets` → **201**, **`POST /api/workforce/employees` → 201 (création d'un employé !)**. Le front masquait les boutons via `v-can`, mais **l'API n'exigeait que `company.access:use-module,workforce`** — aucune permission granulaire. N'importe quel membre ayant le module pouvait créer/gérer employés, générer/approuver des feuilles de temps, approuver des congés, etc.
- **Cause racine** : le groupe `Route::prefix('workforce')->middleware('company.access:use-module,workforce')` ne portait que le gate **module**. Les permissions granulaires (`workforce.manage`, `workforce.time_manage`, `workforce.timesheet_approve`, `workforce.leave_approve`, `workforce.contracts`, …) n'étaient appliquées **que côté front** (`v-can`) — défense en profondeur absente côté serveur.
- **Correction** : chaque route de management porte désormais **aussi** `company.access:use-permission,{clé}` en miroir exact du `v-can` du front — Employés/contrats/timesheets/pointages/congés (view/manage, timesheet_view/submit/approve, time_manage/approve, leave_request/approve/manage, contracts) **ET** Planning (planning_view/manage/publish), Paie (payroll_prepare/validate/export), DSN (payroll_export), Documents RH (workforce_documents.view/generate). Le **owner bypasse** (inchangé). Le self-service `/workforce/me/*` reste sous module-only (données de l'employé lui-même) — intact.
- **Re-vérification (prouvée)** : après fix, `bob` → **403** sur `employees` (list + create), `timesheets` (generate), `leaves` (list) ; `owner` → **200**. 
- **Tests de non-régression** : [E2E] `workforce-authz.spec.js` (bob 403 / owner 200, même module actif → preuve de la couche permission) ; [API/PHPUnit] 4 invariants dans `SecurityInvariantTest` (driver interdit list/create employé, generate timesheet, list congés). Suite workforce/sécurité : 301+ verts.
- **Découverte** : campagne E2E (sondes API avec session réelle d'un salarié), impossible à voir en se fiant au front (boutons masqués).

### ANO-015 — Solde de congés par année peut devenir négatif (garde globale vs réservation par bucket annuel) — **P2 (observé, documenté)** 🔍
- **Périmètre** : Workforce — congés, `workforce_leave_balances` (cache par `period_year`).
- **Observation (prouvée en base)** : pour l'employé #3, le bucket **2027** affiche `cached_accrued=0, cached_reserved=900, cached_available=-900` (solde **négatif**). Cause : `ApproveLeaveRequestUseCase` contrôle la suffisance sur le solde **global** (`computeBalance` sans année → somme tous buckets) mais la réservation est **inscrite sur le bucket de l'année de `date_from`** (`periodKey = Y-m`). Un congé daté 2027 passe le garde (global 2500 accrual) mais crée une réservation dans un bucket 2027 **sans accrual** → solde annuel négatif.
- **Impact** : l'onglet Soldes peut afficher un `available` annuel négatif pour une année future, sans blocage fonctionnel (le garde global reste correct). Incohérence d'affichage/comptabilité par année, pas de perte de données.
- **Statut** : **documenté, non corrigé ce lot** — le correctif touche la politique d'accrual/carry-over par année (profondeur métier) ; la doctrine (Workforce en stabilisation, largeur avant profondeur) impose de ne pas modifier la sémantique d'accrual sans décision produit. À arbitrer (ADR) : soit le garde contrôle le bucket de l'année ciblée, soit l'accrual/report alimente les années futures.
- **Preuve E2E du modèle correct (année courante)** : `workforce-leave-ledger.spec.js` — sur un congé **2026** (même bucket que l'accrual), l'approbation réserve proprement (available −N, reserved +N) et le refus ne touche rien.

### ANO-006 — Workforce `/time` : 422 au chargement des statistiques — **P2** ✅ (429 = artefact de test)
- **Diagnostic (capture réseau isolée)** : `/company/workforce` → **0 non-2xx en isolation** → le **429 était un artefact** du smoke (rate-limit cumulatif sous navigation rapide multi-pages), **pas un défaut**. `/company/workforce/time` → **`422 GET /api/workforce/timesheets/statistics`** reproductible : la page appelle les stats au mount **sans période** (`tsPeriodStart/End=null`) alors que le back exigeait `period_start`/`period_end` **required**.
- **Correction** : `TimesheetController::statistics` → période **optionnelle**, défaut **mois courant** (endpoint résilient ; validation d'un range inversé conservée). Stats chargent au mount sans 422.
- **Tests** : `WorkforceTimesheetWorkflowTest` (sans période→200, période explicite→200, range inversé→422). Vérifié navigateur : `/company/workforce/time` **0 non-2xx**.

### ANO-002 — `/platform/billing/coupons` : 2× `401` en console — **P3 (non reproductible, clos)** ✅
- **Périmètre / page** : Platform Billing — `/platform/billing/coupons`.
- **Repro** : E2E staging, `admin@leezr.com` → page rend mais 2 ressources `401` (parmi `/billing/coupons`, `/plans`, `/modules`).
- **Attendu** : aucune erreur console ; dropdowns plans/modules peuplés.
- **Observé** : 2× `401` ; **non reproduit en local** (20/20 vert).
- **Diagnostic (capture réseau Playwright, staging)** : rejeu ciblé → **toutes les requêtes 200** (`/api/platform/plans`, `/modules`, `/billing/coupons`, `me`, `nav`). **Zéro 401.** Le 401×2 initial (pendant le run des 20 pages) était une **course transitoire** (cookie/session platform pas encore établi au moment d'un appel parallèle après login), **pas un défaut déterministe**.
- **Décision** : **non reproductible → clos, pas de correction** (consigne : corriger seulement après cause identifiée). Reclassé P3. À rouvrir si un repro déterministe apparaît.

## Items hérités des audits antérieurs (référence, non ré-audités)
Voir `../03-registre-anomalies.md` et `../06-plan-remediation.md`. État au mieux de la connaissance :
- **FUNC-01** bandeau conformité mort → ✅ **corrigé** (retiré, cf. registre refactor LOT 5).
- **Endpoints jobdomains** « fantômes » → ✅ **obsolète** : routes `/field-definitions`, `/documents` existent + testées (cf. refactor LOT 5).
- **Essai expiré sans effet / dunning / ledger / devise** → ✅ traités (ADR-570/571, `../11-remediation-finance-matrice.md`).
- **Scheduler non supervisable** → ✅ traité (ADR-572, `../12-scheduler-cockpit-integration.md`).
- **AR i18n ~96% manquant** (fallback EN) → ⏳ **P3** (effort traduction ; `billing.tax_exemption.*` complété côté AR en lot dédié).
- **Centimes→euros saisie, self-service congé complet, frontière Core (ADR-562)** → ⏳ décisions produit / refactors (hors « harmonisation »).

## Familles de contrôle (grille §5 mission) — statut sur le périmètre audité
| Famille d'incohérence | Constaté ? | Réf |
|---|---|---|
| Prochaine facture dans le passé | **OUI** → corrigé | ANO-001 |
| Essai expiré présenté actif | Non (corrigé antérieurement, gate accès restreint) | ADR-571 |
| Abonnement actif sans période cohérente | **OUI** (`is_current=NULL`) → corrigé | ANO-001 |
| Facture payée affichée impayée | Non constaté sur le périmètre audité | — |
| TTC utilisé comme HT | Non (isolation TVA ledger faite) | ADR-570 |
| Mauvaise devise | Non (MRR par devise) | ADR-570 |
| Compteur différent selon 2 pages | **OUI** (date next-invoice Plan vs Overview) → harmonisé | ANO-001 |
| Company suspendue conserve accès métier | **NON** → bloquée 403 [TEST] | `SecurityInvariantTest`, `CompanySuspensionTest`, `BillingAdr232Test` |
| Module désactivé toujours accessible | **NON** → 403 [TEST] (`EnsureModuleActive`: !isActive→403 ; toute route module gatée) | `GlobalModuleRouteCoverageTest`, `ModuleEntitlementEnforcementTest` |
| Bouton sans endpoint | [NON] balayage non exhaustif ; jobdomains OK | — |
| Fonctionnalité annoncée non implémentée | voir audits antérieurs (modules horizontaux) | ../02 |
| Entitlement incohérent avec catalogue commercial | **OUI** (config dev-only) → corrigé | ANO-003 |
| Abonnement actif non renouvelable | **OUI** (`is_current=NULL`) → corrigé + régularisation | ANO-001 |
| Config DEV/PROD masquant une dysfonction | **OUI** (seeder dev-only) → corrigé | ANO-003 |

> Les lignes [NON]/[CODE] indiquent explicitement les contrôles **non encore prouvés au navigateur** — à traiter dans les lots d'audit suivants.
