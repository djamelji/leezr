# ADR-815.2 — 06. Spécifications des pages cibles

> **Choix de conception [C]**, à valider. Chaque page réutilise les API et services existants (cités). Seuls les changements backend listés dans `08` sont nécessaires.
>
> **Composants** : stock Vuexy/Vuetify et composants Leezr existants (`05` §5) ; `@core` n'est pas modifié.
>
> **Gabarit commun** (valable pour toutes les pages, sauf mention contraire) :
> - **Chargement** : squelette.
> - **Erreur** : `ErrorState` avec message métier et « Réessayer » ; 403 ou 404 = « indisponible », sans « Réessayer ».
> - **Mobile** : menu en tiroir, tableaux en cartes, action principale en bas.
> - **Permissions** : vérifiées au routeur (`meta.permission`) **et** à l'API (source de vérité).

---

## 1. Accueil — `/home`

- **Domaine** : A. **Objectif** : savoir quoi faire maintenant. **Profils** : tous.
- **Structure** :
```
┌ Bonjour {prénom} · {date} ──────────────────────────────────────────┐
│ [DÉMARRAGE] (propriétaire, tant que la checklist n'est pas finie)    │
│  Profil entreprise ✓ · Premier poste · Première embauche · Module …  │
├ MOI (si fiche salarié liée) ──────────────────────────────────────── ┤
│ [Pointer l'arrivée]  Prochain créneau  ·  Mes congés  ·  À fournir 1 │
├ À TRAITER (si au moins une capacité de gestion ; périmètre) ──────── ┤
│ Pièces à valider 3 › · Congés à valider 1 › · Non pointés 2 ›        │
│ Embauches en cours 1 › · Expéditions non assignées 1 ›               │
├ ENTREPRISE (si droit de lecture collective) ──────────────────────── ┤
│ Grille d'indicateurs personnalisable (dispositions existantes)       │
└──────────────────────────────────────────────────────────────────────┘
```
- **Informations** : uniquement ce que le profil peut traiter ou consulter.
- **Actions principales** : pointer ; ouvrir une tâche.
- **Actions secondaires** : personnaliser la grille (menu ⋮ du bloc Entreprise).
- **États vides** :
  - Moi sans fiche salarié : bloc absent ;
  - À traiter vide : « Rien à traiter pour le moment » ;
  - aucun bloc : « Rien à faire pour vous ici », plus des liens vers les espaces autorisés.
- **API réutilisées** : `/profile/space`, `/workforce/me/today`, `/dashboard/priorities` (`HomePrioritiesReadModel`), `/dashboard/onboarding`, `/dashboard/layout`, `/dashboard/widgets/*`.
- **Backend** : contexte utilisateur et salarié dans `batchResolve` ; portée de widget ; plus d'écriture lors de l'affichage (L5).
- **Mobile** : Moi d'abord, puis À traiter ; pas de grille.
- **Critères d'acceptation** :
  - un salarié ne reçoit aucun indicateur d'entreprise ;
  - le propriétaire n'a qu'un accueil ;
  - aucun `PUT` lors de l'ouverture.

## 2. Équipe › Personnes — `/company/people`

- **Domaine** : B. **Objectif** : trouver, embaucher et gérer des personnes. **Profils** : `members.view` (annuaire restreint, D3), `workforce.view` (périmètre).
- **Structure** :
```
Équipe                                         [Embaucher]  [⋮ Plus : Importer · Actions en lot]
[Recherche……………] [Poste ▾] [Service ▾] [Site ▾] [Statut ▾] [Accès ▾] [Mon équipe ⇄ Toute l'entreprise]
┌ Nom / poste ────────────┬ Service ─┬ Statut d'emploi ─┬ Accès ─────────┬ Embauche ─┐
│ Claire Chauffeur         │ Livraison │ Actif            │ Connectée      │ 5/5        │
│ Chauffeur-livreur        │           │                  │                │            │
```
- **Informations** : nom, poste, service, statut d'emploi, statut d'accès, progression d'embauche.
  - Pas d'e-mail de connexion en colonne (visible dans la fiche, onglet Accès).
- **Actions principales** : Embaucher ; ouvrir une fiche.
- **Actions secondaires** : import, actions en lot existantes (changer de poste, de responsable, de service).
- **Filtres** : poste, service, site, statut, accès, « en cours d'embauche », périmètre.
- **Tableau** : `VDataTableServer`, tri par nom ; cartes sur mobile.
- **Dialogues** : assistant Embaucher (§4) ; actions en lot (`_EmployeeBulkActionDialog`).
- **État vide** : « Aucune personne pour l'instant » + [Embaucher] + [Importer].
- **API** : `GET /company/people` (filtres poste, service, statut à ajouter côté lecture), `/workforce/onboarding-files`.
- **Permissions** :
  - liste = `members.view` ou `workforce.view` ;
  - Embaucher = `workforce.manage` ;
  - actions en lot = `workforce.manage`.
- **Mobile** : recherche, puis cartes, puis bouton flottant « Embaucher ».
- **Critères d'acceptation** :
  - un seul bouton de création ;
  - l'onglet Rôles a disparu de la page (accessible dans Réglages › Accès) ;
  - le chauffeur ne voit que nom, photo et poste (D3).

## 3. Fiche personne — `/company/people/{personRef}`

- **Domaine** : B/J. **Objectif** : tout savoir et tout faire pour une personne.
- **Structure** :
```
← Équipe
[CC] Claire Chauffeur · Chauffeur-livreur · Actif · Accès : connectée      [Modifier] [Actions ▾]
Prochaine action : « Valider le permis de conduire » ›
Vue d'ensemble | Profil | Emploi & contrat | Documents | Temps & absences | Accès
```
- **Onglets** :
  - **Vue d'ensemble** : checklist d'embauche (existante, complétée des pièces), alertes (expirations), activité récente.
  - **Profil** : **un seul formulaire**. Identité, coordonnées, puis champs exigés par le poste, avec les champs sensibles masqués par défaut (`sensitive_read`). Un bouton « Enregistrer ».
  - **Emploi & contrat** :
    - poste, service, responsable (actifs uniquement), site, date d'entrée ;
    - contrats (composant `_EmployeeContract` existant) ;
    - rémunération (`compensation_read`).
  - **Documents** : exigés par le poste (état), reçus, à signer ; [Demander] [Déposer].
  - **Temps & absences** (si modules) : planning, pointages, soldes.
  - **Accès** : compte, rôle d'accès, invitation, suspendre, retirer, rendre.
- **Actions (menu)** : Changer de poste ; Terminer la collaboration ; Demander un document ; Faire signer.
- **États vides** : par onglet, avec l'action qui fait sortir de l'état vide (ex. « Aucun contrat » + [Créer le contrat]).
- **Erreurs** : 403 ou 404 → « Fiche indisponible » (existant, ADR-812).
- **API** :
  - `/company/people/{ref}` ;
  - `/workforce/employees/{id}/file` ;
  - `/workforce/employees/{id}/fields` (`PersonFieldWriter`) ;
  - contrats ;
  - `…/documents` ;
  - **ne pas appeler les modules inactifs**.
- **Permissions** : par onglet (existantes) ; périmètre `WorkforceAccess`.
- **Mobile** : en-tête compact, onglets défilants, actions dans une feuille basse.
- **Critères d'acceptation** :
  - aucun champ en double ;
  - un seul bouton d'enregistrement par onglet ;
  - aucune requête 403 vers un module inactif.

## 4. Assistant « Embaucher » — dialogue plein écran depuis Équipe

- **Domaine** : E. **Objectif** : embaucher en un seul parcours. **Profils** : `workforce.manage`.
- **Structure** (composant `AppStepper`) :
```
1 Personne  ›  2 Poste  ›  3 Mode  ›  4 Dossier  ›  5 Contrat  ›  6 Finaliser
```
  1. **Personne** : prénom*, nom*, e-mail (facultatif), date d'entrée* (obligation signalée). Détection de doublon (personne existante).
  2. **Poste** : liste des postes. Affiche les exigences du poste (« 3 informations, 2 pièces, 1 document produit ») ; lien « Modifier le poste ».
  3. **Mode** :
     - ( ) **Compléter maintenant avec la personne** ;
     - ( ) **Envoyer un lien sécurisé**, avec e-mail de vérification, validité, et finalité / base légale **préremplies** depuis Réglages (D7).
  4. **Dossier** :
     - mode A : formulaire des informations et dépôt des pièces, sur place ;
     - mode B : suivi (envoyé, ouvert, transmis), puis vérification (revue existante) ;
     - le snapshot et les révisions existent déjà.
  5. **Contrat** : brouillon prérempli (date d'entrée, heures du poste) ; générer et faire signer si les modules sont actifs ; peut être différé.
  6. **Finaliser** : récapitulatif ; [Activer le salarié] ; option « Donner accès à Leezr » + rôle d'accès (D15).
- **États** : l'assistant peut être quitté et repris (le dossier existe dès l'étape 3) ; il apparaît dans « Embauches en cours ».
- **Erreurs** : doublon d'e-mail ; poste supprimé ; limite de membres de l'offre (accès).
- **API** :
  - création du salarié préparatoire (`OnboardingInvitationService::create`) ;
  - `OnboardingPlanner::propose` ;
  - envoi et portail existants ;
  - `PersonFieldWriter` ;
  - contrats ;
  - `MembershipInvitation`.
  - Nouveau : mode « sur place » et « finaliser » (L2).
- **Permissions** : `workforce.manage` ; accès = `members.invite` + anti-escalade.
- **Mobile** : une étape par écran.
- **Critères d'acceptation** :
  - embauche sur place ≤ 3 écrans, ≈ 10 clics (mesure à refaire) ;
  - les modes A et B produisent les mêmes données ;
  - un seul salarié créé.

## 5. Organisation › Postes — `/company/organization/positions` (alias de `/company/workforce/organization/roles`)

- **Domaine** : C. **Objectif** : définir les postes et ce qu'ils exigent. **Profils** : lecture `workforce.view` ; édition `settings.manage` ou `documents.configure`.
- **Liste** : intitulé · service · effectif · exigences (N infos, M pièces) · [⋮].
- **Fiche poste** (tiroir large ou page) :
  - **Description** : intitulé, service, niveau, description.
  - **Exigences d'intégration** : trois listes.
    - « Informations à fournir » (champs) ;
    - « Pièces à fournir » (types de documents, avec un niveau **bloquant avant l'entrée** ou **à compléter après**) ;
    - « Documents produits par l'entreprise » (modèles).

    Recommandations du métier marquées « suggéré » ; règles légales marquées « obligatoire » (non retirables, `Requirement` imperative).
  - **Personnes** : effectif sur ce poste.
- **Retiré du formulaire** : le taux horaire (déplacé vers la rémunération, Emploi & contrat).
- **État vide** : « Aucun poste » + [Adopter les postes recommandés pour votre métier] (preset existant) + [Créer un poste].
- **API** : `/workforce/job-roles`, `/workforce/requirements*` (`RequirementConfigService`), presets d'organisation.
- **Critères d'acceptation** : une modification des exigences s'applique aux nouvelles embauches ; l'application aux personnes en poste se fait sur action explicite, avec aperçu.

## 6. Organisation › Services · Sites

- **Services** : arbre, responsable par défaut, effectif ; mêmes API qu'aujourd'hui (`workforce_departments`).
- **Sites** : liste, adresse, type ; API des lieux de travail.
- **Critère** : un seul terme, « Service » (glossaire).

## 7. Documents › À traiter — `/company/documents/todo`

- **Domaine** : F. **Objectif** : décider vite. **Profils** : `documents.view` (lecture), `documents.manage` (décision).
- **Structure** :
```
Documents   [Recherche document / personne……]     [+ Demander]  [⋮ Campagne · Importer des bulletins]
À traiter (4) | Par personne | Entreprise | Signatures | Campagnes
┌ Personne ──────────┬ Poste ─────────────┬ Pièce ─────────────┬ Statut ──┬ Depuis ─┬───────────────────────┐
│ Claire Chauffeur    │ Chauffeur-livreur   │ Permis de conduire  │ Transmis │ 17 nov.  │ [Approuver] [Refuser] │
```
- **Informations** : la colonne « Rôle » d'accès est remplacée par « Poste » ; pas d'e-mail de connexion.
- **Sources** : dépôts (y compris le portail d'intégration), pièces manquantes, expirations, signatures en attente.
- **Actions** : Approuver ; Refuser (motif obligatoire) ; Demander un remplacement.
- **État vide** : « Rien à valider. Les pièces transmises apparaîtront ici. »
- **API** : `/company/documents/search`, `…/review`, `/company/document-requests`.
- **Critère** : le même nombre dans l'accueil, les widgets et la page.

## 8. Documents › Par personne · Entreprise · Signatures · Campagnes

- **Par personne** : matrice personne × pièce exigée (couleurs d'état) ; filtre par poste.
- **Entreprise** : pièces de la société (Kbis, assurances) avec leur expiration.
- **Signatures** : demandes en cours, relances, documents signés.
- **Campagnes** : demande en masse, par **poste** (et non plus par rôle).
- **Déplacés vers Réglages › Documents** : types de pièces, modèles.

## 9. Planning — `/company/workforce/planning`

- **Domaine** : G. **Profils** : `planning_view` (lecture), `planning_manage` et `publish`.
- **Structure** : barre « Semaine ▸ · Aujourd'hui · [Site ▾] [Service ▾] [Poste ▾] » ; [Nouveau créneau] ; [Publier (n)] ; [⋮ Modèles · Création en lot · Préférences].
- **Retiré** : l'onglet « Mon activité ». Icônes sans libellé → libellées ou dans le menu ⋮.
- **Informations** : couverture à pourvoir ; personnes **planifiables** (contrat actif) distinguées des autres.
- **État vide** : « Aucun créneau cette semaine » + [Nouveau créneau] + [Appliquer un modèle].
- **API** : `/workforce/planning/*` (inchangé).
- **Mobile** : vue jour en liste.

## 10. Pointages & feuilles — `/company/workforce/time`

- **Profils** : `time_manage`, `timesheet_approve` ou `timesheet_approve_team` (plus `timesheet_view`).
- **Onglets** : Aujourd'hui (anomalies en tête) · Présences · Feuilles à valider.
- **Actions** : bouton **« Corriger »** visible sur chaque ligne d'anomalie, y compris **« Clôturer » une entrée en cours** (oubli de sortie), avec motif (débloque le parcours 14).
- **Erreurs** : refus traduits.
- **API** : `/workforce/time-entries/*`, `/workforce/timesheets/*`.

## 11. Absences — `/company/workforce/leave`

- **Profils** : `leave_view_all`, `leave_approve` (valideurs).
- **Onglets** : À valider · Calendrier · Soldes.
- **Actions** : « Approuver » et « Refuser » libellés.
- **Déplacé** : Types d'absence → Réglages › Règles RH.
- **Déblocage** : à l'activation du module, ouverture des soldes des contrats actifs (parcours 15).

## 12. Paie — `/company/workforce/payroll` · Déclarations `/dsn`

- **Profils** : `payroll_*`.
- **Prérequis** (nouvel écran de lecture, avant « Nouvelle période ») :
```
Avant de préparer la paie de novembre :
✓ 38 contrats actifs   ⚠ 12 salariés sans contrat ›   ⚠ 2 feuilles non validées ›   ✓ Profil légal (SIRET, NAF)
```
- **Inchangé** : le moteur de paie et le bandeau d'avertissement existant.
- **API** : lecture des prérequis seulement.

## 13. Expéditions — liste, création, fiche

- **Création** : origine, destination, date, **chauffeur (facultatif)** ; assignation possible dès la création.
- **Fiche** : statut, chronologie, assignation.
- **API** : `/shipments/*` inchangées.

## 14. Menu personnel

- **Mon espace** (`/company/my-space/{tab}`) : **conservé tel quel** (modèle de clarté) ; Aperçu · Mes documents · Mes bulletins · Mes signatures.
- **Mon activité** (`/company/workforce/me`) : Aujourd'hui · Mes heures · Mon planning · Congés (demande en dialogue) · Mon contrat · Documents reçus. Retiré du hub de gestion.
- **Mes livraisons** : liste, puis fiche, avec les boutons d'état libellés (existant).
- **Compte** : profil, mot de passe, 2FA.
- **Critère** : uniquement des API `self`.

## 15. Réglages — `/company/settings` et sections

| Section | Contenu | Pages et API réutilisées |
|---|---|---|
| Entreprise | Profil, coordonnées, identité légale, marché, langue | `/company/profile/{tab}`, `PUT /company` ; **sans** le message hors contexte sur les membres |
| Accès | Rôles & permissions (vue par capacité **et** « Nouveau rôle » visibles ; **périmètre** affiché), accès en attente, sécurité | `/company/roles`, `/company/role-capabilities*`, `/company/pending-access` ; noms de modules traduits |
| Règles RH | Temps de travail (convention A–E), types d'absence, modèles de contrat, signature | `/company/workforce/settings/{tab}` |
| Documents | Types de pièces de la société, formats, expiration, modèles | `_DocumentsSettings`, `CompanyDocumentActivationCatalog` |
| Modules | Catalogue (modules livrés seulement), activation avec devis, **un** assistant par module, désactivation avec impact | `/company/modules`, `/company/modules/{key}` ; un seul nom par module ; dates traduites |
| Abonnement | Formule, consommation, factures, paiement | `/company/billing/{tab}` ; « Aperçu consolidé V2 » renommé ou masqué |
| Notifications | Préférences de la société | `/notifications/preferences` |
| Automatisations | Workflows | `/company/workflows` |
| Journal d'audit | Libellés (ADR-813) | `/company/audit` |

- **Critère** : chaque règle a **un** écran ; la recherche du hub trouve chaque réglage.

## 16. Portail recrue `/onboarding`, invitations `/invitations`, signature `/sign/{token}`

- **Inchangés** sur le fond.
- **`/invitations`** : proposé **automatiquement à la connexion** si une invitation est en attente. Plus d'écrans vides ni de 400 (L8).
