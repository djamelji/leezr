# ADR-815.2 — 05. Architecture fonctionnelle cible de l'espace Company

> **Choix de conception [C]**, fondés sur les constats de `02` à `04`. L'architecture est pensée à partir des **objets métier** et des **intentions des utilisateurs**, pas à partir des menus actuels. Elle reste compatible avec la règle du projet : les **manifestes des modules déclarent** groupes et entrées, le Core assemble.

## 1. Principes

1. **Quatre objets pivots** :
   - la **Personne** (fiche salarié, avec ou sans compte) ;
   - le **Poste** ;
   - le **Travail** (planning, temps, absences) ;
   - l'**Activité métier** (expéditions, et demain les autres verticales).

   Tout écran est soit une **liste**, soit une **fiche** de l'un d'eux, soit un **réglage**.
2. **Séparer agir et configurer.** Les pages de travail ne contiennent pas de paramétrage. Tout paramétrage vit sous **Réglages**, sauf un lien contextuel « Configurer » depuis la page concernée.
3. **Le « moi » est un espace à part** : menu personnel et bloc « Moi » de l'accueil. Il n'apparaît jamais comme onglet d'un hub de gestion.
4. **Un concept = un nom = un écran de configuration.** Voir le glossaire du §6.
5. **La visibilité découle des permissions et du contexte.** Le rôle d'accès ne décrit pas un métier.
6. **Aucun menu vide** : un groupe sans entrée accessible n'apparaît pas (comportement actuel de `NavBuilder`, conservé).

## 2. Arborescence cible complète

```
ACCUEIL                                   /home
  └─ blocs : Moi · À traiter · Entreprise · Démarrage (tant que la checklist n'est pas terminée)

ÉQUIPE                                    (groupe « team », module core.members + workforce)
  ├─ Personnes                            /company/people
  │    ├─ filtres : poste · service · site · statut d'emploi · statut d'accès · « en cours d'embauche »
  │    ├─ actions : [Embaucher] · menu « Plus » (importer, actions en lot : changer de poste / responsable / service)
  │    └─ Fiche personne                  /company/people/{personRef}
  │         ├─ Vue d'ensemble   (prochaine action, checklist d'embauche, alertes)
  │         ├─ Profil           (UN formulaire : identité + champs exigés par le poste ; source = PersonFieldWriter)
  │         ├─ Emploi & contrat (poste, service, responsable, site, dates, contrats, rémunération*)   *permission
  │         ├─ Documents        (exigés par le poste, reçus, à signer ; demander / déposer)
  │         ├─ Temps & absences (si module ; planning, pointages, soldes)
  │         └─ Accès            (compte, rôle d'accès, invitation, suspension)
  │         dialogues : Terminer la collaboration · Changer de poste · Donner / retirer l'accès · Demander un document
  └─ Assistant « Embaucher »              dialogue plein écran depuis Personnes
       étapes : Personne → Poste → Mode (sur place | lien sécurisé) → Dossier → Contrat → Finaliser

ORGANISATION                              (groupe « organisation », module workforce)
  ├─ Postes                               /company/organization/positions
  │    └─ Fiche poste : Description · Exigences d'intégration (données, pièces, documents produits) · Personnes
  ├─ Services                             /company/organization/departments
  └─ Sites                                /company/organization/sites

DOCUMENTS                                 (groupe « documents », core.documents + workforce_documents + signature)
  ├─ À traiter                            /company/documents/todo   (file unique : dépôts, intégrations, expirations, signatures)
  ├─ Par personne                         /company/documents/people
  ├─ Documents de l'entreprise            /company/documents/company
  ├─ Signatures                           /company/documents/signatures
  └─ Campagnes                            /company/documents/campaigns

PLANNING & TEMPS                          (groupe « time »)
  ├─ Planning                             /company/workforce/planning     (vues jour / semaine / mois / postes)
  ├─ Pointages & feuilles                 /company/workforce/time         (gestion uniquement)
  └─ Absences                             /company/workforce/leave        (validation, calendrier, soldes)

PAIE                                      (groupe « payroll », workforce_payroll)
  ├─ Périodes                             /company/workforce/payroll  (+ écran de prérequis)
  └─ Déclarations                         /company/workforce/dsn

ACTIVITÉ (verticale)                      (groupe « activity » ; un manifeste par verticale)
  └─ Expéditions                          /company/shipments  (+ création, fiche)

RÉGLAGES                                  /company/settings (hub)
  ├─ Entreprise        profil, coordonnées, identité légale, marché et langue
  ├─ Accès             rôles & permissions (avec périmètre), accès en attente, sécurité (2FA obligatoire, sessions)
  ├─ Règles RH         temps de travail, convention, types d'absence, contrats (modèles), signature
  ├─ Documents         types de pièces de la société, formats, expiration, modèles de documents
  ├─ Modules           catalogue, activation, assistants (seul assistant de configuration)
  ├─ Abonnement        formule, consommation, factures, paiement, stockage
  ├─ Notifications     préférences de la société
  ├─ Automatisations   workflows
  └─ Journal d'audit

MENU PERSONNEL (avatar)
  ├─ Mon espace        /company/my-space/{tab}   (mes documents, signatures, bulletins, mes informations)
  ├─ Mon activité      /company/workforce/me     (si salarié lié : pointage, planning, heures, congés)
  ├─ Mes livraisons    /company/my-deliveries    (si shipments.view_own)
  └─ Mon compte        /account-settings/{tab}

PUBLIC  /register · /login · /invitations · /reset-password · /onboarding (portail recrue) · /sign/{token} · /help-center
```

**Raccourcis** :
- recherche globale (barre du haut) : personnes, documents, expéditions ;
- bouton « + » contextuel par section ;
- cloche (notifications) ;
- lien « Configurer » depuis chaque page de travail vers sa section de Réglages.

## 3. Contribution des modules (manifestes)

| Module | Groupe(s) de navigation déclarés | Entrées | Contributions transverses |
|---|---|---|---|
| `core.dashboard` | home | Accueil | — |
| `core.members` | team | Personnes, Embaucher | onglet Accès de la fiche |
| `workforce` | team, organisation, settings › Règles RH | Organisation (postes, services, sites) | onglets Emploi & contrat, Profil (champs RH) ; blocs Moi |
| `workforce_planning` | time | Planning | onglet Temps de la fiche ; bloc Moi (prochain créneau) |
| `workforce` (temps) | time | Pointages & feuilles | bloc Moi (pointage) ; À traiter (anomalies) |
| `workforce_leave` | time, settings › Règles RH | Absences ; Types d'absence | À traiter (demandes) ; Mon activité |
| `core.documents`, `workforce_documents` | documents, settings › Documents | Documents | onglet Documents de la fiche ; À traiter |
| `workforce_contract`, `workforce_signature` | documents, settings › Règles RH | Signatures ; Modèles | étape Contrat de l'embauche |
| `workforce_payroll` | payroll | Paie, Déclarations | Mon espace › bulletins |
| `logistics_shipments` | activity | Expéditions | Mes livraisons ; À traiter (non assignées, retards) |
| `logistics_tracking`, `logistics_fleet`, `logistics_analytics` | — | **aucune tant que non livrés** (catalogue masqué) | widget flotte (déjà existant) |
| `core.roles`, `core.settings`, `core.modules`, `core.billing`, `core.audit`, `core.workflows`, `core.theme`, `core.notifications`, `core.support`, `core.documentation`, `core.jobdomain` | settings (et aide) | sous-sections de Réglages | — |

## 4. Contextes

| Contexte | Où il s'exprime | Règle |
|---|---|---|
| **Utilisateur courant** | Menu personnel, bloc Moi | Données strictement personnelles, résolues côté serveur (utilisateur et salarié lié). |
| **Personne** | Fiche personne | Tout ce qui concerne une personne y est accessible, selon les permissions par onglet. |
| **Équipe (périmètre managérial)** | Listes filtrées par défaut sur « Mon équipe », bloc À traiter | `manager_id` (un seul support) ; l'éditeur de rôles montre le périmètre. |
| **Poste** | Fiche poste ; filtres | Exigences d'intégration ; affichage des effectifs. |
| **Service / site** | Filtres de toutes les listes ; planning | Pas d'établissement juridique multi-SIRET (hors périmètre, décision D9). |
| **Entreprise** | Bloc Entreprise, Réglages | Lecture collective = permission explicite. |

## 5. Expérience simple et cohérente (design system fonctionnel, Vuexy/Vuetify, sans toucher `@core`)

| Pattern | Règle unique | Composants existants |
|---|---|---|
| **Liste** | En-tête (titre, action principale, menu « Plus »), filtres en ligne, tableau serveur, passage en cartes sous 600 px | `AppResponsiveDataView`, `VDataTableServer`, `TablePagination` |
| **Fiche** | En-tête (identité, statut, prochaine action), onglets, **un formulaire par onglet**, un seul bouton Enregistrer | `AppSectionNavigation`, `AppSurface` |
| **Création** | Simple : tiroir latéral. À étapes : dialogue plein écran (Embaucher, assistant de module). | `VNavigationDrawer`, `AppStepper` (stock Vuexy) |
| **Validation** | Boutons **libellés** « Approuver » et « Refuser » (jamais une icône seule) ; refus = motif obligatoire | `SmartActionBar` |
| **Suppression / fin** | Dialogue de confirmation qui dit ce qui est conservé | `ConfirmDialog` |
| **États** | Libellés communs : Brouillon, À faire, En attente, Validé, Refusé, Annulé, Expiré (codes inchangés) | `StatusChip` |
| **Vide** | Phrase d'explication + action qui fait sortir de l'état vide | `EmptyState` |
| **Erreur** | Message métier traduit (jamais le message serveur brut), action proposée | `ErrorState`, toasts |
| **Chargement** | Squelette de la liste ou de la fiche, pas de spinner plein écran | `VSkeletonLoader` |
| **Mobile** | Menu en tiroir, actions principales en bas d'écran, tableaux en cartes, pas de débordement horizontal (constaté conforme en ADR-815) | — |
| **Traductions** | Toute chaîne visible passe par i18n ; pluriels gérés (corriger « 5 salarié inclus ») | vue-i18n |

## 6. Glossaire cible (un nom par concept)

| Concept | Nom à l'écran | Noms à abandonner |
|---|---|---|
| Fiche salarié | **Personne** | collaborateur, membre, employé (selon les pages) |
| `workforce_job_roles` | **Poste** | rôle (URL `/organization/roles`), intitulé de poste (champ libre), fonction |
| `company_roles` | **Rôle d'accès** | rôle, rôle dans Leezr, Dispatcher/Driver comme métier |
| `workforce_departments` | **Service** | département |
| `workforce_work_locations` | **Site** | lieu |
| Dossier recrue + checklist | **Embauche** | onboarding, intégration (pour la recrue) |
| Configuration d'un module | **Configuration du module** | assistant RH, onboarding |
| Checklist société | **Démarrage** | onboarding |
| Module « Documents RH » | un seul nom par module, identique sur la carte et dans « Requis par » | Documents Employés / Documents RH ; Congés & Absences / Absences et congés ; Planning & Pointage / Planning ; Signatures électroniques / Signature électronique ; Paie & Déclarations / Paie |
