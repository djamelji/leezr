# ADR-815.2 — 02. Audit UX page par page

> **Une fiche par page routée de l'espace Company** (registre complet : `01-complete-company-inventory.md` §2). Les 20 questions de la mission sont numérotées **Q1 à Q20** dans chaque fiche.
>
> **Notation**
> - **[P]** : prouvé par le code ou les données.
> - **[R]** : observé au navigateur, sur clone isolé (recette ADR-815, 9 profils ; parcours ADR-815.2).
> - **[C]** : choix de conception.
>
> **Captures** : `adr8152/shots/<profil>__<page>.jpg` (propriétaire, propriétaire mobile, chauffeur, salarié) et `adr8152/shots/journeys/jNN.jpg` (parcours).
>
> **Décisions** : CONSERVER · SIMPLIFIER · FUSIONNER · DÉPLACER · RECONSTRUIRE · SUPPRIMER (ou réduire à un alias).

---

## A. Accueil et tableaux de bord

### A1. `/home` — « Accueil personnel » · capture `owner__home.jpg`, `driver__home.jpg`
- **Q1 Pourquoi** : accueil opérationnel de l'employé (ADR-751). **Q2 Qui** : tout utilisateur ; arrivée du seul archétype `field_worker` [P].
- **Q3 Problème résolu** : « que dois-je faire maintenant ? » (pointer, prochain créneau, documents, livraisons).
- **Q4 Compris immédiatement** : oui pour un chauffeur [R] ; non pour un gestionnaire, qui y trouve sous « Mes widgets » la grille de l'**entreprise** [R].
- **Q5 Bonnes informations** : bloc personnel pertinent ; grille collective hors sujet [R].
- **Q6 / Q7 Actions** : « Pointer l'arrivée » évident (1 clic, parcours 13 [R]). Les actions secondaires « Ajouter un widget » et l'édition de la grille occupent un niveau 1.
- **Q8 Données techniques inutiles** : non. **Q9 / Q10 Redondances** : la grille = `/dashboard` [P] ; les cartes « Documents à régulariser / Congés / Livraisons » = Mon espace et Mon activité.
- **Q11 Filtres et tableaux** : sans objet. **Q12 Navigation** : « Accueil » du menu ne mène pas ici pour un gestionnaire ; « Accueil personnel » est dans le menu personnel [P].
- **Q13 Permissions** : aucune garde de page ; widgets collectifs servis sous droits personnels [R] (constat P0 ADR-815). **Q14 Contenu adapté au profil** : partiellement.
- **Q15 Doublon** : `/dashboard`.
- **Q16 Décision** : **RECONSTRUIRE** en accueil unique (bloc Moi / À traiter / Entreprise).
- **Q17 Cible** : `06-target-page-specifications.md` § Accueil.
- **Q18 Premier niveau** : mes 3 prochaines actions, mon pointage, mon prochain créneau ; pour un gestionnaire, la file « À traiter » de son périmètre.
- **Q19 Mobile** : pile verticale : pointage en tête, puis actions ; pas de grille personnalisable sur mobile [C].
- **Q20 Backend** : contexte utilisateur et salarié dans la résolution des widgets ; portée `self`, `team` ou `company` ; supprimer l'écriture de disposition lors de la lecture [P].

### A2. `/dashboard` — tableau de bord de gestion · capture `owner__dashboard.jpg`, `salarie__dashboard.jpg`
- **Q1** : vue de pilotage (ADR-776). **Q2** : propriétaire et administrateurs, mais aussi tout rôle sans archétype, y compris le **salarié** [R].
- **Q3** : priorités (« À traiter »), actions rapides, indicateurs.
- **Q4** : oui pour un gestionnaire ; non pour un salarié, qui voit « Vue d'ensemble » de l'entreprise [R].
- **Q5** : la checklist « Bienvenue sur Leezr 3/5 » est utile au démarrage (parcours 1 [R]) ; la contradiction « 1 document à régulariser / Aucun document requis configuré » trompe [R].
- **Q6** : « À traiter » est clair (« 2 sujets : anomalies de pointage, intégrations à compléter », parcours 21 [R]). **Q7** : grille personnalisable volumineuse.
- **Q8** : non. **Q9 / Q10** : même grille que `/home` [P].
- **Q12** : arrivée pilotée par l'archétype RBAC [P]. **Q13** : P0 agrégats (salarié) [R]. **Q14** : non adapté au salarié.
- **Q15** : `/home`. **Q16** : **FUSIONNER** dans `/home`, qui devient le seul accueil (alias conservé).
- **Q18** : « À traiter » en tête ; indicateurs seulement si droit collectif. **Q19** : sur mobile, « À traiter » seulement, puis indicateurs repliés.
- **Q20** : même chose que A1, plus l'écriture de disposition à l'ouverture à supprimer (`dashboard.store.js:133`) [P].

### A3. `/company/workforce` — alias historique → `/dashboard`
- Q1–Q20 : redirection pure (16 lignes) [P]. **Décision** : **ALIAS** vers `/home`. Aucun backend.

---

## B. Équipe et personnes

### B1. `/company/people` — Équipe · capture `owner__people.jpg`
- **Q1** : point d'entrée unique des personnes (ADR-766/767). **Q2** : propriétaire, RH, managers (périmètre) ; annuaire pour `members.view` (le chauffeur y a accès [R]).
- **Q3** : trouver une personne, en ajouter une, suivre les intégrations, gérer les accès.
- **Q4** : partiellement. **3 onglets d'usages différents** : « Personnes », « Gestion RH » (outils en lot, import), « Rôles & autorisations » (administration RBAC) [R].
- **Q5** : le bandeau « 22 membre(s) avec un profil incomplet » est utile mais générique [R]. La colonne « Poste » est vide pour 45 salariés sur 52 [P].
- **Q6** : **deux** boutons d'entrée concurrents : « Ajouter une personne » et « Inviter une recrue à compléter son dossier » [R]. **Q7** : « Outils » en haut à droite, plus l'onglet Gestion RH : deux accès aux mêmes outils.
- **Q8** : l'e-mail de connexion apparaît sous le nom (« admin@leezr.test ») [R].
- **Q9** : non. **Q10** : un dossier d'intégration ouvert après un ajout ressaisit prénom, nom et e-mail [P].
- **Q11** : filtres « Compte », « Emploi » ; manquent poste, service, statut d'embauche [R].
- **Q13** : le chauffeur reçoit l'annuaire complet (R1) ; RH et Responsable exploitation ne voient pas les fiches (R10) [R].
- **Q14** : non. **Q15** : `/company/members`, `/company/workforce/employees` (alias).
- **Q16** : **RECONSTRUIRE**. Une seule entrée « Embaucher » ; « Rôles & autorisations » est **déplacé** vers Réglages › Accès ; « Gestion RH » est réduit à un menu d'actions en lot.
- **Q18** : recherche, filtres (poste, service, statut, accès), bouton « Embaucher », liste. Intégrations en cours = filtre « En cours d'embauche ».
- **Q19** : liste en cartes (nom, poste, statut), recherche en haut, « Embaucher » en bouton flottant.
- **Q20** : filtre par poste et service côté `PersonController@index` (lecture) ; aucune autre modification.

### B2. `/company/people/:personRef` — fiche personne · captures `owner__people_employee-2.jpg`, `journeys/j22.jpg`
- **Q1** : fiche unifiée (ADR-768). **Q2** : RH, managers (périmètre), la personne elle-même via Mon espace.
- **Q3** : tout savoir et tout faire sur une personne.
- **Q4** : l'en-tête est clair (nom, statut d'accès, rôle, Modifier, Actions) [R].
- **Q5** : la checklist « Intégration du collaborateur » (Informations, Contrat, Signature, Accès, Premier créneau) **ignore les pièces requises** [R].
- **Q6** : « Modifier » et « Actions » sont présents ; « Terminer la collaboration » est dans Actions (parcours 23, 4 clics [R]).
- **Q8** : la fiche appelle l'API Signatures même module désactivé (403) [R parcours 6].
- **Q9 Champs redondants (confirmé)** : l'onglet « Informations & emploi » empile **trois formulaires**, chacun avec son bouton d'enregistrement [R parcours 22, `journeys/j22.jpg`] :
  - (1) champs du profil et « Spécifique au poste », avec « Enregistrer les modifications » ;
  - (2) Prénom, Nom, E-mail, Téléphone, Matricule, Département, **Poste**, **Responsable**, avec « Annuler / Enregistrer » ;
  - (3) « Informations complémentaires » : **Intitulé de poste** (texte libre), **Date d'embauche**, **Type de contrat**, **Statut**, véhicule, zone, avec « Enregistrer ».

  Sont donc saisis deux fois :
  - prénom et nom ;
  - poste (liste **et** texte libre) ;
  - date d'entrée ;
  - type de contrat (champ **et** module Contrats) ;
  - statut (champ **et** statut du salarié).
- **Q10** : identité ressaisie si un dossier d'intégration est ouvert ensuite.
- **Q12** : 5 onglets (Vue d'ensemble, Informations & emploi, Documents & contrats, Temps & absences, Accès & autorisations) ; cohérent.
- **Q13** : manager et RH sans `workforce.manage` : 404 hors périmètre [R].
- **Q15** : `/company/members/:id`, `/company/workforce/employees/:id` (alias, avec code résiduel) [P].
- **Q16** : **RECONSTRUIRE** l'onglet « Informations & emploi » (un seul formulaire), et **ajouter** l'onglet « Embauche » (checklist + pièces + dossier).
- **Q18** : en-tête : statut d'emploi, poste, responsable, statut d'accès, prochaine action. Onglets : Profil · Emploi & contrat · Documents · Temps & absences · Accès.
- **Q19** : en-tête compact, onglets en défilement, actions dans un menu bas.
- **Q20** : le champ libre « Intitulé de poste » et le champ « Type de contrat » des champs personnalisés sont à **dépublier** du catalogue des champs de la fiche (données conservées) ; un seul endpoint d'édition (`PersonFieldWriter`) [P].

### B3. Aliases `/company/members`, `/company/members/:id`, `/company/workforce/employees`, `/company/workforce/employees/:id`
- Redirections vers Équipe. `members/[id].vue` (461 lignes) et `employees/[id].vue` (429 lignes) gardent l'ancienne interface après la redirection [P].
- **Décision** : **ALIAS** de quelques lignes et suppression du code mort (P4). Aucun backend.

### B4. `/invitations` — accepter une invitation (compte déjà existant)
- **Q1–Q3** : rejoindre une société quand on a déjà un compte. **Q4** : simple (2 API) [P]. **Q12** : après définition du mot de passe d'un **nouveau** compte, l'utilisateur est renvoyé vers `/login` au lieu d'être connecté (parcours 9 [R]).
- **Impasse confirmée** [R parcours 26b/26c] : une personne dont l'accès a été retiré puis rendu reçoit une invitation à accepter. Si elle se connecte avant, elle voit des écrans vides et des réponses **400** (`/api/nav`, `/api/modules`) ; rien ne la dirige vers `/invitations` (1 clic pour accepter une fois sur la page).
- **Q16** : **CONSERVER**. **Q17** : à la connexion, une invitation en attente est proposée en premier ; connexion automatique après définition du mot de passe [C]. **Q20** : garde de démarrage côté interface ; D14.

---

## C. Organisation

### C1. `/company/workforce/organization/:tab` — Départements · Postes · Lieux · captures `owner__workforce_organization_departments.jpg`, `journeys/j08.jpg`
- **Q1** : décrire la structure. **Q2** : RH et direction (`workforce.view`, y compris le manager [R]).
- **Q3** : départements (responsable, sous-départements), postes, lieux.
- **Q4** : oui. Le bandeau « Organisation proposée » (preset) en tête de page est utile au démarrage, encombrant ensuite [R].
- **Q5** : la fiche poste ne montre **pas** ce que le poste exige [P].
- **Q6** : « Ajouter un poste » : 4 clics, 5 champs (intitulé, département, niveau, description, taux horaire) [R parcours 8].
- **Q8** : « Taux horaire par défaut » est une donnée de paie, dans un formulaire d'organisation [R].
- **Q12** : l'URL de l'onglet « Postes » est `/organization/roles` [P] (confusion poste et rôle) ; « Départements » ici, « services » dans les Réglages [P].
- **Q13** : lecture pour `workforce.view` ; l'édition n'est pas distinguée [P].
- **Q15** : Réglages › « Sites, services & postes » mène ici ; l'assistant RH (étape « Votre organisation ») recoupe cette page.
- **Q16** : **RECONSTRUIRE** autour de la fiche **Poste**, qui porte les exigences.
- **Q18** : liste des postes : intitulé, effectif, exigences (N pièces, M champs), service. Les départements et les lieux sont des onglets secondaires.
- **Q19** : liste de postes en cartes ; édition plein écran.
- **Q20** : exigences par poste lues et écrites via `requirements` (`RequirementConfigService` existant) ; URL `/organization/positions` (alias de `roles`).

---

## D. Rôles et permissions

### D1. `/company/roles` → Équipe › « Rôles & autorisations » · captures `owner__roles.jpg`, `journeys/j10.jpg`
- **Q1** : administrer les rôles RBAC. **Q2** : administrateurs (`roles.view`).
- **Q3** : décider qui peut voir et faire quoi.
- **Q4** : la vue « Par capacité » est lisible (module → capacités → puces de rôles) [R]. **Créer un rôle** n'est possible qu'en « Par rôle (avancé) » [R parcours 10].
- **Q5** : « Aucune proposition disponible pour votre activité » sur une société Logistique neuve [R] ; le chauffeur est coché sur « Informations entreprise » [R].
- **Q8** : le tiroir d'un rôle édite aussi des **champs** (`field_config`) [P].
- **Q12** : la page vit dans Équipe, alors que c'est un réglage [R].
- **Q13** : périmètres invisibles : « voir les salariés » = `workforce.view` **et** `workforce.manage` [P].
- **Q15** : assistant de module (étape « Accès »), accès en attente (Équipe).
- **Q16** : **DÉPLACER** vers Réglages › Accès et **SIMPLIFIER** : droits uniquement, avec périmètre explicite (moi / mon équipe / l'entreprise) par capacité.
- **Q18** : liste des rôles avec leur nombre de membres ; matrice par capacité ; « Nouveau rôle » visible.
- **Q19** : consultation seule sur mobile [C].
- **Constat** [R parcours 10] : le formulaire « Nouveau rôle » liste les modules sous leur **nom technique anglais** (« Workforce Leave », « Workforce Documents », « Workforce Planning », « Theme »).
- **Q20** : retirer l'édition de `field_config` de l'interface ; afficher le périmètre calculé par `WorkforceAccess` [P] ; noms de modules traduits.

---

## E. Embauche et intégration

### E1. Dialogue « Ajouter une personne » (`_PersonAddDrawer`) · `journeys/j04.jpg`, `j09.jpg`
- Sans accès : 3 clics, 2 champs, 1 choix [R parcours 4]. Avec accès : 6 clics, 5 champs, 2 choix (rôle RBAC choisi **à l'embauche**) [R parcours 9].
- **Aucun poste, contrat ni pièce** ; la date d'entrée est préremplie [R].
- **Q16** : **FUSIONNER** dans l'assistant « Embaucher » (mode « personne déjà en poste » conservé).

### E2. Dialogue « Inviter une recrue » (`_OnboardingInviteDialog`) · `journeys/j05.jpg`
- Étape 1 : Prénom, Nom, E-mail (facultatif), Date d'arrivée prévue, Poste (facultatif) → « Continuer » [R]. L'étape 2 (sélection des éléments, finalité, base légale, texte d'information, e-mail du candidat) est détaillée dans `03-end-to-end-journeys.md` (parcours 5).
- **Crée un salarié préparatoire** distinct du salarié créé par E1 [P].
- **Q16** : **FUSIONNER** dans « Embaucher » (mode « à distance »).

### E3. Dialogue « Revue du dossier » (`_OnboardingReviewDialog`)
- Décision par valeur (accepter ou garder l'existant), 7 champs, 14 boutons [P]. Validation séparée de la file Documents [P].
- **Q16** : **CONSERVER** comme étape « Vérifier » de l'embauche ; les dépôts sont aussi visibles dans Documents › À traiter.

### E4. `/onboarding` — portail recrue (public)
- Lien sécurisé, code par e-mail, quarantaine antivirus (ADR-796.1) [P]. Rejoué : voir parcours 5.
- **Q16** : **CONSERVER** (mode B de l'embauche). **Q19** : conçu mobile d'abord [P].

### E5. `/company/workforce/requirements` — « Informations & documents demandés » · `owner__workforce_requirements.jpg`
- **Q4–Q5** : la page indique elle-même qu'elle n'a **pas d'effet** sur les fiches, demandes, conformité ou rappels ; « Rien de demandé » alors que 3 pièces sont requises [R].
- **Q15** : Documents › Paramètres, Rôles (champs), Équipe › réglages des champs.
- **Q16** : **FUSIONNER** dans Organisation › Poste › Exigences. **Q20** : bascule d'autorité (lot de refonte), voir `08`.

---

## F. Documents

### F1. `/company/documents/:tab` — Documents · captures `owner__documents_todo.jpg`, `owner__documents_templates.jpg`, `owner__documents_signatures.jpg`
- **Q1** : traiter, demander, conserver, produire et faire signer les documents. **Q2** : RH et gestionnaires documentaires.
- **Q3** : conformité documentaire.
- **Q4** : « À traiter » est clair : demandes en attente (58), documents manquants (67), signatures (7) [R].
- **Q5** : les 67 « manquants » contredisent le widget « Aucun document requis configuré » [R].
- **Q6** : « + Nouveau » et « Outils » en tête ; 4 onglets visibles (À traiter, Entreprise, Collaborateurs, Signatures). L'inventaire détecte **15 valeurs d'onglets** et **14 dialogues** (8 367 lignes avec les composants) [P].
- **Q7** : paramétrage (types de pièces), modèles, campagnes et import de bulletins dans la même page que le traitement quotidien [P].
- **Q11** : recherche globale « document, personne » utile [R].
- **Q13** : `anyPermission` documents / documents RH / signatures [P].
- **Q15** : `/company/workforce/documents`, `/signature`, `/contract/templates` (alias).
- **Q16** : **SIMPLIFIER**. Les onglets de travail (À traiter, Personnes, Entreprise, Signatures) restent ; paramètres et modèles **déplacés** vers Réglages › Documents.
- **Q18** : file « À traiter » unifiée (dépôts, intégrations, expirations, signatures), puis recherche.
- **Q19** : file « À traiter » en cartes avec Approuver / Refuser.
- **Q20** : conformité par poste ; les dépôts d'intégration rejoignent la file ; aucune création à la lecture.

### F2. `/sign/:token` — signature externe (public)
- **CONSERVER** : parcours externe autonome (4 API) [P].

### F3. Aliases `/company/workforce/documents`, `/signature`, `/contract/templates`
- **ALIAS** [P].

---

## G. Planning

### G1. `/company/workforce/planning` · `owner__workforce_planning.jpg`
- **Q1** : planifier l'équipe (vues semaine, jour, postes, mois). **Q2** : planificateurs et managers (`planning_view`).
- **Q4** : pour un planificateur, oui. La barre d'outils compte une dizaine d'**icônes sans libellé** [R].
- **Q5** : « Couverture 41 à couvrir », « Créneaux au total » : utiles [R].
- **Q6** : « Nouveau créneau » et « Préparer ma semaine » visibles. **Q7** : « Préférences », « Création en lot » et « Modèles » en tête, plus d'actions.
- **Q12** : le hub « Planning & temps » mélange 4 onglets : Planning · Temps & pointages · Absences · **Mon activité** (vue personnelle) [R].
- **Q13** : refus métier traduit si pas de contrat actif (ADR-812) ; l'écran ne montre pas d'avance qui est planifiable [P].
- **Q15** : aucun doublon. **Q16** : **SIMPLIFIER**. Conserver le moteur et les vues ; retirer « Mon activité » du hub ; donner un libellé aux icônes.
- **Q18** : semaine, filtres (site, service, poste), « Nouveau créneau », « Publier ».
- **Q19** : vue jour en liste ; création en plein écran.
- **Q20** : aucun ; page de 8 565 lignes à découper en composants (P4, sans changement fonctionnel).

---

## H. Temps de travail

### H1. `/company/workforce/time` — Temps & pointages · `owner__workforce_time.jpg`, `salarie__workforce_time.jpg`
- **Q1** : suivre présences, pointages et feuilles. **Q2** : managers ; **ouvert au salarié** via `timesheet_view` (« own ») [R].
- **Q4** : « le travail de l'équipe au même endroit » : clair pour un manager ; trompeur pour un salarié, qui voit des compteurs collectifs à 0 [R].
- **Q5** : compteurs (en travail, en pause, terminés, non pointés, incomplets, anomalies, feuilles à valider) [R].
- **Q6** : aucune action visible sur la ligne d'anomalie ; la correction s'ouvre en cliquant la ligne (drawer « Corriger le pointage », motif obligatoire) [R parcours 14]. **Blocage confirmé** : un oubli de sortie (entrée « en travail ») ne peut pas être corrigé ; le serveur répond en **anglais brut** « Cannot update a time entry with status 'working'. Only completed or idle entries can be corrected. » [R]. **Q11** : filtres date, département, employé, statut.
- **Q13** : écran de gestion servi au salarié [R]. **Q15** : `/company/workforce/me` (vue salarié).
- **Q16** : **SIMPLIFIER** (gestion uniquement, garde `time_manage`, `timesheet_approve` ou `timesheet_approve_team`).
- **Q19** : liste « non pointés / anomalies » en tête.
- **Q20** : garde de page ; **permettre de clôturer une entrée en cours** (sortie manquante) avec motif, via le cas d'usage de correction existant ; traduire le refus.

### H2. `/company/workforce/time/:id` — feuille de temps
- **CONSERVER** : détail d'une feuille (4 onglets détectés, 511 lignes) [P].

---

## I. Congés et absences

### I1. `/company/workforce/leave` — Absences · `owner__workforce_leave.jpg`
- **Q1** : demandes, calendrier, soldes, types. **Q2** : valideurs et RH ; aussi le demandeur (`leave_request`) [P].
- **Q4** : clair : compteurs En attente, Approuvées, Refusées ; liste avec actions ✓ ✗ ⦸ [R].
- **Q6** : les actions par ligne sont des **icônes sans libellé** [R].
- **Q7** : « Types de congés » (paramétrage) au même niveau que les demandes [R].
- **Q12** : dans le hub « Planning & temps ». **Q15** : `/company/workforce/me` (demande salarié).
- **Q16** : **SIMPLIFIER**. Valideur : demandes, calendrier, soldes. Les **Types** vont dans Réglages › Règles RH. Le demandeur passe par Mon activité.
- **Q18** : « En attente » en tête avec Approuver / Refuser libellés. **Q19** : cartes de demandes.
- **Q20** : aucun.

---

## J. Contrats et RH

### J1. Contrats dans la fiche (`_EmployeeContract`) · `journeys/j06.jpg`
- Créer : 5 clics, 2 champs (CDI préselectionné, modèle horaire, heures, date, convention, rémunération) ; « Activer » est une étape distincte [R parcours 6].
- **Q15** : le champ « Type de contrat » existe aussi dans les informations complémentaires [R].
- **Q16** : **CONSERVER** le composant ; l'intégrer à l'étape « Contrat » de l'embauche, prérempli (poste, date, heures du poste).
- **Q20** : préremplissage à partir du poste (lecture) [C].

### J2. `/company/workforce/settings/:tab` — Paramètres RH · `owner__workforce_settings_worktime.jpg`
- **Q4** : 4 onglets (Général, Temps de travail & pauses avec sous-étapes A–E, Contrats de travail, Signature électronique) [R].
- **Q5** : société 1 : « convention non confirmée » alors que l'assistant RH affiche « Terminé » [R].
- **Q15** : assistant RH, assistant de module (étape temps de travail).
- **Q16** : **CONSERVER** comme **unique** lieu des règles RH (Réglages › Règles RH) ; les assistants y écrivent.

### J3. Aliases `/company/workforce/configuration`
- **ALIAS** [P].

---

## K. Paie

### K1. `/company/workforce/payroll` et `/payroll/:id` · `owner__workforce_payroll.jpg`
- **Q1** : préparer les périodes de paie. **Q2** : gestionnaire de paie (`payroll_prepare`).
- **Q4** : le bandeau « Espace de préparation… estimations indicatives… à faire valider par un expert-comptable » est clair et honnête [R].
- **Q5** : compteurs (runs, brouillons, calculés, validés) et liste ; « Anomalies 10 » visibles [R].
- **Q13** : accès sensible séparé (`payroll_*`, `compensation_read`) [P].
- **Q15** : la fiche personne montre aussi les bulletins.
- **Q16** : **CONSERVER**. Ajouter un écran de **prérequis** avant « Nouvelle période » : contrats actifs, feuilles validées, profil légal.
- **Q20** : lecture des prérequis seulement ; **aucun changement du moteur de paie** (contrainte).

### K2. `/company/workforce/dsn` et `/dsn/:id`
- **CONSERVER** (déclarations, 6 onglets détectés) [P] ; regroupé sous Paie.

---

## L. Logistique et expéditions

### L1. `/company/shipments`, `/shipments/create`, `/shipments/:id` · `owner__shipments.jpg`, `journeys/j18.jpg`
- **Q3** : créer, planifier, assigner, suivre. Parcours 18 : 5 clics, 2 champs, 1 choix [R].
- **Q5** : la création ne demande que deux adresses (4 champs détectés) ; l'assignation se fait sur la fiche, après planification [R].
- **Q13** : l'assignation vise un **compte** (`assigned_to_user_id`), pas un salarié [P].
- **Q16** : **CONSERVER** et **SIMPLIFIER** (assigner dès la création, facultatif).
- **Q20** : aucun pour l'interface ; affectation à un salarié = décision (D10).

### L2. `/company/my-deliveries`, `/my-deliveries/:id` · `driver__my-deliveries.jpg`
- Parcours 19 : 2 clics pour « Démarrer le transit » puis « Marquer comme livrée » [R].
- **Q16** : **CONSERVER** ; vue chauffeur mobile d'abord.

### L3. Modules sans interface `logistics_tracking`, `logistics_fleet`, `logistics_analytics`
- Présentés dans Modules › « À découvrir » avec « Bientôt disponible » [R] ; aucune permission ni page [P].
- **Décision** : les retirer du catalogue Company tant qu'ils ne sont pas livrés (règle anti-dérive ADR-566 : « pas de coquille vendable »).

---

## M. Modules et configuration

### M1. `/company/modules` · `owner__modules.jpg`, `journeys/j03.jpg`
- **Q1** : découvrir, activer, désactiver. **Q4** : clair ; onglets À découvrir, Inclus dans votre formule, Activés, Tous [R].
- **Q6** : activation en 2 clics, avec devis dans le dialogue (« 0,00 €/mois », dépendances, « données conservées ») [R parcours 3].
- **Q5** : « 5 salarié inclus » (accord du pluriel) [R]. **Noms incohérents** : la carte dit « Documents RH », « Absences et congés », « Planning », « Signature électronique », « Paie » ; la ligne « Requis par » et le dialogue de désactivation disent « Documents Employés », « Congés & Absences », « Planning & Pointage », « Signatures électroniques », « Paie & Déclarations » [R parcours 3 et 25]. Les dates du dialogue de désactivation sont **en anglais** (« December 17, 2026 ») [R].
- **Q13** : réglages, devis et impact lisibles via l'API sans `modules.manage` (R2) [R].
- **Q16** : **CONSERVER**, en masquant les modules non livrés (L3).
- **Q20** : garde `modules.manage` sur les 3 routes de lecture (lot sécurité).

### M2. `/company/modules/:key` — détail et assistant d'un module · `journeys/j3b.jpg`
- L'assistant annonce « Trois étapes : accès, temps de travail, confirmation » ; boutons « Conserver les accès actuels » et « Paramètres avancés » [R].
- **Q15** : assistant RH `/company/workforce/onboarding`.
- **Q16** : **CONSERVER** comme **seul** assistant de configuration.

### M3. `/company/workforce/onboarding` — « Assistant de configuration RH » · `owner__workforce_onboarding.jpg`
- 7 étapes recoupant Réglages, Organisation, Rôles, Congés, Temps de travail et Équipe [R].
- **Q16** : **FUSIONNER** dans l'assistant du module (M2). Nom à changer : ce n'est pas un « onboarding ».

---

## N. Finance et abonnement

### N1. `/company/billing/:tab` (+ `/invoices/:id`, `/pay`, `/consolidated-preview`) · `owner__billing_plan.jpg`
- **Q4** : clair : Starter « 0,00 € HT / mensuel », membres 2/5, stockage 0 B / 1 GB, période [R parcours 20].
- **Q7** : l'onglet « Aperçu consolidé V2 » expose un terme technique [R].
- **Q16** : **CONSERVER** ; renommer l'aperçu consolidé (ou le masquer tant que la facturation V2 est désactivée).
- **Q20** : aucun (contrainte Billing).

### N2. Aliases `/company/billing`, `/company/plan`
- **ALIAS** [P].

---

## O. Paramètres

### O1. `/company/settings` — hub Réglages · `owner__settings.jpg`
- **Q4** : très clair : sections Entreprise, Organisation, Accès & sécurité, Travail & RH, Documents, Abonnement, Automatisations, Avancé ; recherche [R].
- **Q13** : pas de garde de routeur ; pour un salarié, « Aucun réglage n'est accessible avec vos droits » [R].
- **Q16** : **CONSERVER** comme **unique** porte des réglages. Y rattacher Rôles, Types de pièces, Types de congés et Règles RH.

### O2. `/company/profile/:tab` — Profil de l'entreprise
- Parcours 2 : 2 clics, 1 champ, enregistré [R].
- Un message hors sujet s'affiche : « En fonction du secteur d'activité… informations à renseigner pour chaque membre » [R].
- **Q16** : **CONSERVER**, en retirant ce message hors contexte.

### O3. `/company/audit` — Journal d'audit
- Libellés lisibles depuis ADR-813 [P]. **CONSERVER** sous Réglages.

### O4. `/company/notifications`
- « Aucune notification » pour une société neuve [R]. **CONSERVER** ; le centre reste accessible par la cloche.

---

## P. Mon espace et expérience salarié

### P1. `/company/my-space/:tab` · `owner__my-space_overview.jpg`
- **Q4** : excellent : Aperçu (documents « 1 à fournir », bulletins, signatures, Mon activité, profil, sécurité), Mes documents, Mes bulletins, Mes signatures [R].
- Parcours 7 : dépôt de pièce [R, voir 03].
- **Q16** : **CONSERVER** comme modèle de cohérence.

### P2. `/company/workforce/me` — Mon activité · `owner__workforce_me.jpg`, `driver-mobile__workforce_me.jpg`
- **Q4** : clair : Aujourd'hui, Mes heures, Mon planning, Solde congés, Mon contrat, Documents reçus [R].
- **Q12** : affiché comme onglet du hub de **gestion** « Planning & temps » [R].
- **Blocage confirmé** [R parcours 15] : « Aucun solde de congés n'est ouvert pour vous. Votre entreprise doit d'abord activer votre contrat » alors que le contrat est **actif**. Le module Congés a été activé **après** l'activation du contrat, et les soldes n'ont pas été ouverts. Le message indique une mauvaise cause.
- **Q16** : **DÉPLACER** vers le menu personnel uniquement.
- **Q20** : ouvrir les soldes à l'activation du module pour les contrats déjà actifs (service d'acquisition existant), ou message exact.

### P3. `/account-settings/:tab` — Compte
- Profil, mot de passe, 2FA [P]. **CONSERVER**.

---

## Q. Transverse

### Q1. `/company/403`
- Accès refusé avec redirection temporisée [R]. **CONSERVER**.

### Q2. `/company/dev-lab`, `/ui-lab`
- Laboratoires livrés dans l'application [P]. **SUPPRIMER** de la build de production.

---

## R. Autres modules Company

### R1. `/company/workflows`, `/workflows/:id` — Automatisations · `owner__workflows.jpg`
- **Q2** : `automations.view`. **Q16** : **CONSERVER** sous Réglages › Automatisations (pas d'entrée de menu principale).

### R2. `/company/support`, `/support/:id` · `owner__support.jpg`
- **CONSERVER** (accès aussi par le lien « Support » du pied de page).
