# ADR-815 — Inventaire des pages de l'espace Company

> Généré depuis le code (`resources/js/pages/**`, `definePage`, appels `$api`, y compris dans les composants enfants de premier niveau), annoté par l'audit, avec l'**accès réellement observé** pour chaque profil sur le clone isolé.

**Légende des colonnes d'accès** : `OK` page affichée · `403` redirigé vers « Accès refusé » · `→x` redirigé vers x · `API…` page affichée avec une ou plusieurs réponses API en erreur · `·` non visité.

**Profils** : owner · alice · ops · manager · rh · salarie · driver · owner-mobile · driver-mobile

## 1. Matrice principale

| URL | Objectif fonctionnel | Public cible | Module | Permissions (route) | API (nb) | Même fonction ailleurs | Problèmes UX | Problèmes d'autorisation | Décision | Risque | Priorité | Fichier |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `/account-settings/:tab` | Compte : profil, mot de passe, 2FA | Tout utilisateur | — | — | 8 | /company/my-space | — | — | **Conserver (menu personnel)** | — | P3 | `pages/account-settings/[tab].vue` (81 l.) |
| `/company/403` | Accès refusé (redirection temporisée) | — | — | — | 0 | — | — | — | **Conserver** | — | — | `pages/company/403.vue` (91 l.) |
| `/company/audit` | Journal d'audit | audit.view | core.audit | audit.view | 0 | — | — | — | **Conserver (Réglages)** | — | P3 | `pages/company/audit/index.vue` (206 l.) |
| `/company/billing` | Ancienne entrée facturation (redirection) | — | core.billing | billing.manage | 0 | /company/billing/:tab | — | — | **Alias** | S | P4 | `pages/company/billing/index.vue` (18 l.) |
| `/company/billing/:tab` | Abonnement, factures, moyens de paiement | billing.manage | core.billing | billing.manage | 2 | /company/plan, /company/billing (alias) | — | — | **Conserver** | — | P3 | `pages/company/billing/[tab].vue` (103 l.) |
| `/company/billing/consolidated-preview` | Aperçu de facture consolidée | billing.manage | core.billing | billing.manage | 0 | — | Écran sans entrée de menu | — | **Conserver (accès depuis Facturation)** | — | P3 | `pages/company/billing/consolidated-preview.vue` (319 l.) |
| `/company/billing/invoices/:id` | Détail d'une facture | billing.manage | core.billing | billing.manage | 1 | — | — | — | **Conserver** | — | P3 | `pages/company/billing/invoices/[id].vue` (886 l.) |
| `/company/billing/pay` | Paiement | billing.manage | core.billing | billing.manage | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/billing/pay.vue` (1013 l.) |
| `/company/dev-lab` | Laboratoire design system (DEV) | workforce.view | workforce | workforce.view | 0 | /ui-lab | Page de développement livrée en production | Accessible à tout workforce.view | **Retirer de la build de production** | S | P3 | `pages/company/dev-lab.vue` (276 l.) |
| `/company/documents/:tab` | Documents : à traiter, personnes, registre, signatures, modèles, campagnes, paramètres | Gestionnaires documentaires, RH | core.documents | ['documents.view', 'workforce_documents.view', 'workforce_signature.view'] | 22 | /company/workforce/documents, /signature, /contract/templates (alias) | Pièces exigées configurées ici ET dans les rôles ET dans les exigences par poste | — | **Conserver (file unique « À traiter »)** | M | P1 | `pages/company/documents/[tab].vue` (380 l.) |
| `/company/members` | Ancienne liste des membres | — | core.members | members.view | 0 | /company/people | — | Même garde, puis redirection | **Alias (conserver)** | S | P4 | `pages/company/members/index.vue` (15 l.) |
| `/company/members/:id` | Ancienne fiche membre (redirection) | — | core.members | members.view | 7 | /company/people/:personRef | 461 lignes d'ancienne interface après la redirection | — | **Alias + retrait du code mort** | S | P4 | `pages/company/members/[id].vue` (461 l.) |
| `/company/modules` | Catalogue et activation des modules | modules.manage | core.modules | modules.manage | 1 | Réglages | Page de 2 167 lignes | Réglages, devis et impact des modules lisibles par tout membre via l'API (R2) | **Conserver** | S | P1(R2) | `pages/company/modules/index.vue` (2167 l.) |
| `/company/modules/:key` | Détail et assistant d'un module | modules.manage | core.modules | modules.manage | 5 | /company/workforce/onboarding (assistant RH) | — | — | **Conserver** | — | P3 | `pages/company/modules/[key].vue` (343 l.) |
| `/company/my-deliveries` | Mes livraisons | Chauffeur (shipments.view_own) | logistics_shipments | shipments.view_own | 0 | /home (carte) | — | — | **Conserver** | — | P3 | `pages/company/my-deliveries/index.vue` (184 l.) |
| `/company/my-deliveries/:id` | Détail d'une de mes livraisons | Chauffeur | logistics_shipments | shipments.view_own | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/my-deliveries/[id].vue` (246 l.) |
| `/company/my-space/:tab` | Mon espace : mes documents, signatures, bulletins, informations | Tout utilisateur | — | — | 8 | /home (cartes), /company/workforce/me | — | Self-service scopé (/profile/space) | **Conserver** | — | P3 | `pages/company/my-space/[tab].vue` (231 l.) |
| `/company/notifications` | Notifications | Tous | core.notifications | — | 0 | Cloche de la barre | — | — | **Conserver** | — | P3 | `pages/company/notifications/index.vue` (370 l.) |
| `/company/people` | Équipe : liste des personnes, intégrations en cours, gestion RH, rôles | RH, propriétaire, managers ; annuaire pour members.view | core.members | ['members.view', 'workforce.view', 'roles.view'] | 8 | /company/members, /company/workforce/employees (alias) | Deux boutons d'entrée (Ajouter / Inviter une recrue) ; 3 onglets mêlant personnes, outils RH et rôles RBAC | Chauffeur : annuaire complet (R1) | **Simplifier (Embaucher unique ; rôles vers Réglages)** | M | P1 | `pages/company/people/index.vue` (296 l.) |
| `/company/people/:personRef` | Fiche personne unifiée : identité, emploi, documents et contrats, temps, accès | RH, managers (périmètre), annuaire | core.members | ['members.view', 'workforce.view'] | 18 | /company/members/:id, /company/workforce/employees/:id (alias) | Checklist « Intégration du collaborateur » sans les pièces requises ; 4ᵉ sens d'« onboarding » | Chauffeur lit e-mail de connexion, rôle, indicateur administrateur d'un collègue (R1) ; manager 404 hors équipe | **Conserver + onglet Embauche** | M | P1 | `pages/company/people/[personRef].vue` (912 l.) |
| `/company/plan` | Ancienne page offre (redirection) | — | core.billing | billing.manage | 0 | /company/billing/plan | — | — | **Alias** | S | P4 | `pages/company/plan.vue` (14 l.) |
| `/company/profile/:tab` | Profil de l'entreprise (identité légale, adresses) | settings.view | core.settings | settings.view | 0 | Réglages › Entreprise | — | — | **Conserver** | — | P3 | `pages/company/profile/[tab].vue` (84 l.) |
| `/company/roles` | Rôles et permissions RBAC (redirige vers Équipe › onglet Rôles) | Administrateurs | core.roles | roles.view | 1 | Équipe › Rôles & autorisations | Le tiroir d'un rôle édite aussi des champs (field_config) : un rôle n'est pas un poste | — | **Déplacer vers Réglages › Accès ; droits uniquement** | M | P2 | `pages/company/roles.vue` (628 l.) |
| `/company/settings` | Hub Réglages (recherche, sections) | Administratifs (filtrage dans la page) | — | — | 1 | — | Renvoie vers des pages réparties dans 5 modules | meta {} : aucune garde de routeur (R6) | **Conserver (point d'entrée unique)** | S | P3 | `pages/company/settings/index.vue` (285 l.) |
| `/company/shipments` | Expéditions | Exploitation | logistics_shipments | shipments.view | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/shipments/index.vue` (252 l.) |
| `/company/shipments/:id` | Détail d'une expédition | Exploitation | logistics_shipments | shipments.view | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/shipments/[id].vue` (370 l.) |
| `/company/shipments/create` | Créer une expédition | shipments.create | logistics_shipments | shipments.create | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/shipments/create.vue` (135 l.) |
| `/company/support` | Support | support.view | core.support | support.view | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/support/index.vue` (245 l.) |
| `/company/support/:id` | Ticket de support | support.view | core.support | support.view | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/support/[id].vue` (257 l.) |
| `/company/workflows` | Automatisations | automations.view | core.workflows | automations.view | 0 | — | — | — | **Conserver (Réglages)** | — | P3 | `pages/company/workflows/index.vue` (214 l.) |
| `/company/workflows/:id` | Détail d'une automatisation | automations.view | core.workflows | automations.view | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/workflows/[id].vue` (521 l.) |
| `/company/workforce` | Ancien hub Workforce (redirection vers /dashboard) | — | workforce | workforce.view | 0 | /dashboard | — | — | **Alias vers /home** | S | P4 | `pages/company/workforce/index.vue` (16 l.) |
| `/company/workforce/configuration` | Ancienne configuration (redirection) | — | workforce | workforce.view | 0 | /company/workforce/settings/worktime | — | — | **Alias** | S | P4 | `pages/company/workforce/configuration.vue` (20 l.) |
| `/company/workforce/contract/templates` | Anciens modèles de contrat (redirection) | — | workforce_contract | workforce_contract.view | 0 | /company/documents/templates | — | — | **Alias** | S | P4 | `pages/company/workforce/contract/templates.vue` (13 l.) |
| `/company/workforce/documents` | Ancien registre RH (redirection) | — | workforce_documents | workforce_documents.view | 0 | /company/documents/:tab | — | — | **Alias** | S | P4 | `pages/company/workforce/documents/index.vue` (19 l.) |
| `/company/workforce/dsn` | DSN | payroll_prepare | workforce_payroll | workforce.payroll_prepare | 1 | — | — | — | **Conserver** | — | P3 | `pages/company/workforce/dsn/index.vue` (534 l.) |
| `/company/workforce/dsn/:id` | Détail DSN | payroll_prepare | workforce_payroll | workforce.payroll_prepare | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/workforce/dsn/[id].vue` (361 l.) |
| `/company/workforce/employees` | Ancienne liste RH (redirection) | — | workforce | workforce.view | 0 | /company/people | — | — | **Alias** | S | P4 | `pages/company/workforce/employees/index.vue` (20 l.) |
| `/company/workforce/employees/:id` | Ancienne fiche RH (redirection) | — | workforce | workforce.view | 10 | /company/people/:personRef | 429 lignes d'ancienne interface après la redirection | — | **Alias + retrait du code mort** | S | P4 | `pages/company/workforce/employees/[id].vue` (429 l.) |
| `/company/workforce/leave` | Congés : demandes, validation, soldes | Managers, RH, salarié (leave_request) | workforce_leave | workforce.leave_request | 2 | /company/workforce/me (demande salarié) | Page de 1 577 lignes servant demandeur et valideur | — | **Simplifier (valideur ; la demande dans Mon activité)** | M | P3 | `pages/company/workforce/leave/index.vue` (1577 l.) |
| `/company/workforce/me` | Mon activité : pointage, planning, heures, congés, documents reçus | Salarié lié | workforce | — | 4 | /home (bloc Moi) | Recoupe le bloc personnel de /home | — | **Conserver ; /home résume** | S | P2 | `pages/company/workforce/me.vue` (887 l.) |
| `/company/workforce/onboarding` | Assistant de configuration du module RH (organisation, temps de travail, convention) | Administrateurs | workforce | workforce.manage | 10 | Assistants de module ADR-803 (/company/modules/:key) | Homonyme du dossier d'intégration recrue | — | **Fusionner dans Réglages › Règles RH** | M | P2 | `pages/company/workforce/onboarding.vue` (349 l.) |
| `/company/workforce/organization/:tab` | Organisation : départements, postes (onglet `roles`), lieux | workforce.view | workforce | workforce.view | 3 | Réglages › Sites, services & postes | Onglet « Postes » nommé `roles` dans l'URL ; les postes ne portent pas les exigences | — | **Reconstruire (fiche poste + exigences)** | M | P1 | `pages/company/workforce/organization/[tab].vue` (971 l.) |
| `/company/workforce/payroll` | Paie : runs | payroll_prepare | workforce_payroll | workforce.payroll_prepare | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/workforce/payroll/index.vue` (631 l.) |
| `/company/workforce/payroll/:id` | Détail d'un run de paie | payroll_prepare | workforce_payroll | workforce.payroll_prepare | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/workforce/payroll/[id].vue` (480 l.) |
| `/company/workforce/planning` | Planning (semaine, jour, postes, mois) | Planificateurs, managers | workforce_planning | workforce.planning_view | 1 | — | Page de 7 061 lignes | — | **Conserver** | — | P3 | `pages/company/workforce/planning/index.vue` (7061 l.) |
| `/company/workforce/requirements` | Informations & documents demandés par poste (parallèle) | Administrateurs (settings.manage | documents.configure) | workforce | ['settings.manage', 'documents.configure'] | 7 | Documents › Paramètres ; Rôles (field_config) ; Équipe › réglages des champs | Bandeau « n'a pas encore d'effet » ; « Rien de demandé » alors que 3 pièces sont requises | — | **Fusionner dans Organisation › Postes › Exigences** | L | P1 | `pages/company/workforce/requirements/index.vue` (467 l.) |
| `/company/workforce/settings/:tab` | Règles RH : temps de travail, congés, convention | Administrateurs workforce | workforce | workforce.view | 4 | /company/workforce/configuration (alias) | — | — | **Conserver (Réglages › Règles RH)** | S | P3 | `pages/company/workforce/settings/[tab].vue` (239 l.) |
| `/company/workforce/signature` | Anciennes signatures (redirection) | — | workforce_signature | workforce_signature.view | 0 | /company/documents/signatures | — | — | **Alias** | S | P4 | `pages/company/workforce/signature/index.vue` (14 l.) |
| `/company/workforce/time` | Suivi du temps : pointages, feuilles, validation | Managers ; ouvert au salarié via timesheet_view | workforce | ['workforce.timesheet_view', 'workforce.planning_view'] | 0 | /company/workforce/me (vue salarié) | Écran de gestion proposé au salarié (R5) | timesheet_view « own » ouvre l'écran de gestion | **Simplifier (gestion uniquement)** | S | P2 | `pages/company/workforce/time/index.vue` (1491 l.) |
| `/company/workforce/time/:id` | Détail d'une feuille de temps | Managers | workforce | workforce.timesheet_view | 0 | — | — | — | **Conserver** | — | P3 | `pages/company/workforce/time/[id].vue` (511 l.) |
| `/dashboard` | Tableau de bord de gestion : À traiter, actions, vue d'ensemble | Arrivée de tout rôle non field_worker (y compris salarié) | core.dashboard | — | 4 | /home | Doublon de grille ; un salarié y arrive | Arrivée pilotée par l'archétype RBAC (C3) | **Fusionner dans /home (alias)** | M | P2 | `pages/dashboard.vue` (306 l.) |
| `/home` | Accueil personnel : pointage, prochain créneau, mes actions + « Mes widgets » | Tous (arrivée du field_worker) | — | — | 0 | /dashboard (même grille) | Grille collective sous « Mes widgets » ; titre « Accueil personnel » pour un gestionnaire | Aucune garde de page ; widgets collectifs servis sous droits personnels (C2) | **Reconstruire (accueil unique : Moi · À traiter · Entreprise)** | M | P0/P2 | `pages/home.vue` (284 l.) |
| `/invitations` | Accepter une invitation | Utilisateur invité | — | — | 2 | — | — | — | **Conserver** | — | P3 | `pages/invitations.vue` (42 l.) |
| `/onboarding` | Portail recrue (dossier d'intégration à distance) | Personne sans compte (lien) | — | — | 0 | — | Non rejoué (aucun dossier) | Lien sécurisé, code, quarantaine (ADR-796.1) | **Conserver (mode B du parcours Embaucher)** | M | P1 | `pages/onboarding/index.vue` (407 l.) |
| `/sign/:token` | Signature externe | Signataire | — | — | 4 | — | — | — | **Conserver** | — | P3 | `pages/sign/[token].vue` (278 l.) |
| `/ui-lab` | Laboratoire UI (DEV) | Tout utilisateur connecté | — | — | 0 | /company/dev-lab | Page de développement livrée | Aucune meta | **Retirer de la build de production** | S | P3 | `pages/ui-lab.vue` (141 l.) |

Pages publiques non détaillées (hors espace Company) : `/`, `/login`, `/register`, `/forgot-password`, `/reset-password`, `/maintenance`, `/help-center/**`, `/audience/*`, `/:error`. Elles sont conservées.

## 2. Accès observé par profil (recette navigateur, clone isolé)

| URL visitée | owner | alice | ops | manager | rh | salarie | driver | owner-mobile | driver-mobile |
|---|---|---|---|---|---|---|---|---|---|
| `/` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/dashboard` | OK | OK | OK | OK | OK | OK | →/home | OK | →/home |
| `/company/people` | OK | OK | OK | OK | OK | 403 | OK | OK | OK |
| `/company/workforce/planning` | OK | 403 | OK | OK | OK | 403 | 403 | OK | 403 |
| `/company/workforce/time` | OK | 403 | OK | OK | OK | OK | 403 | OK | 403 |
| `/company/workforce/leave` | OK | 403 | 403 | OK | OK | 403 | 403 | OK | 403 |
| `/company/documents/todo` | OK | OK | 403 | 403 | →/documents/people | 403 | 403 | OK | 403 |
| `/company/workforce/payroll` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/shipments` | OK | OK | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/modules` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/settings` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/home` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/company/people/employee-2` | OK | OK | API 404/people/employee-2 | API 404/people/employee-2 | API 404/people/employee-2 | 403 | OK | OK | OK |
| `/company/people/employee-1237` | OK | OK | API 404ople/employee-1237 | API 404ople/employee-1237 | API 404ople/employee-1237 | 403 | OK | OK | OK |
| `/company/documents/requests` | →/documents/people | →/documents/people | 403 | 403 | →/documents/people | 403 | 403 | →/documents/people | 403 |
| `/company/documents/signatures` | OK | →/documents/todo | 403 | 403 | →/documents/people | 403 | 403 | OK | 403 |
| `/company/documents/templates` | OK | →/documents/todo | 403 | 403 | →/documents/people | 403 | 403 | OK | 403 |
| `/company/roles` | →/people | 403 | 403 | 403 | 403 | 403 | 403 | →/people | 403 |
| `/company/profile/overview` | OK | OK | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/billing/plan` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/billing/invoices` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/audit` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/notifications` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/company/support` | OK | OK | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/workflows` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/my-space/overview` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/company/my-space/documents` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/company/workforce/me` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/company/workforce/dsn` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/workforce/organization/departments` | OK | 403 | OK | OK | OK | 403 | 403 | OK | 403 |
| `/company/workforce/organization/positions` | →/workforce/organizatio | 403 | →/workforce/organizatio | →/workforce/organizatio | →/workforce/organizatio | 403 | 403 | →/workforce/organizatio | 403 |
| `/company/workforce/requirements` | OK | OK | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/workforce/settings/worktime` | OK | 403 | OK | OK | OK | 403 | 403 | OK | 403 |
| `/company/workforce/onboarding` | OK | 403 | 403 | 403 | 403 | 403 | 403 | OK | 403 |
| `/company/my-deliveries` | OK | OK | 403 | 403 | 403 | 403 | OK | OK | OK |
| `/company/dev-lab` | OK | 403 | OK | OK | OK | 403 | 403 | OK | 403 |
| `/ui-lab` | OK | OK | OK | OK | OK | OK | OK | OK | OK |
| `/company/members` | →/people | →/people | 403 | 403 | 403 | 403 | 403 | →/people | 403 |
| `/company/members/2` | →/people | →/people | 403 | 403 | 403 | 403 | 403 | →/people | 403 |
| `/company/workforce/employees/2` | →/people/employee-2 | 403 | →/people/employee-2 | →/people/employee-2 | →/people/employee-2 | 403 | 403 | →/people/employee-2 | 403 |
| `/company/workforce` | →/dashboard | 403 | →/dashboard | →/dashboard | →/dashboard | 403 | 403 | →/dashboard | 403 |
| `/company/plan` | →/billing/plan | 403 | 403 | 403 | 403 | 403 | 403 | →/billing/plan | 403 |

Les messages console `ERR_FAILED` relevés par l'outil viennent du flux temps réel que la recette coupe volontairement, et sont exclus. Le « spinner » de la page 403 est son compte à rebours de redirection (faux positif connu, ADR-811).

## 3. Mesures des parcours (domaines A à P)

> **Provenance des chiffres** :
> - « nav » = entrées de menu calculées par `NavBuilder` pour le profil (mesuré) ;
> - « champs » = champs de saisie comptés dans le code du formulaire (`<App*Field|Select|DateTimePicker|Textarea>`, cases et interrupteurs) ;
> - « pages » = écrans à traverser selon le code et la navigation.
>
> Les clics n'ont pas été instrumentés dans cet audit : la colonne correspondante est une **estimation** (indiquée « ≈ »). L'ADR-811 a mesuré la majorité de ces parcours au navigateur ; ses résultats restent valables.

| Domaine | Parcours | Pages | Formulaires / champs | Décisions | Saisies répétées | Messages / états vides | Actions visibles mais interdites | Visibilité par rôle | Mobile |
|---|---|---|---|---|---|---|---|---|---|
| A. Accueil | Arriver, comprendre quoi faire | 1 (`/home` ou `/dashboard`, selon l'archétype) | 0 | 0 | — | « Aucun document requis configuré » contredit « 1 document à régulariser » (C6) | — | Salarié : vue d'entreprise (C2, C3) | Pas de débordement (recette mobile) |
| B. Équipe | Ajouter une personne | 1 tiroir | 1 / **9** | 3 (accès oui/non, rôle, membre du personnel) | Le prénom et le nom seront redemandés si un dossier d'intégration est ouvert ensuite | — | — | Chauffeur : annuaire complet (R1) ; RH : aucune fiche (R10) | — |
| B. Équipe | Compléter une fiche | 1 fiche, 4 à 6 onglets | variable | — | — | Checklist « Intégration du collaborateur » sans les pièces | — | — | — |
| C. Organisation | Créer un poste | Organisation › Postes (`/organization/roles`) | 1 / 5 (intitulé, département, niveau, description, taux) | 2 | — | — | — | `workforce.view` | — |
| C. Organisation | Définir ce qu'un poste exige | **Autre page** (`/workforce/requirements`) | 1 / sélecteurs | 2 par élément | La même pièce peut être exigée ici, dans Documents › Paramètres et dans le rôle | Bandeau « n'a pas encore d'effet » ; « Rien de demandé » | — | Administratifs | — |
| D. Rôles | Créer un rôle | Équipe › Rôles (tiroir) | 1 / nom, administratif, permissions, **champs** | ≥ 3 | — | — | — | Administratifs | — |
| E. Documents | Valider une pièce | Documents › À traiter **ou** fiche › Documents **ou** revue d'intégration | 1 | 1 (approuver ou refuser) | — | — | — | `documents.*` | — |
| F. Intégration | Envoyer un dossier à distance | Équipe › « Inviter une recrue » | 1 / **13** + liste d'éléments à cocher | ≥ 5 (poste, éléments, finalité, base légale, texte d'information) | Identité ressaisie si la personne existe déjà | — | — | `workforce.manage` | Non mesuré |
| F. Intégration | Valider le dossier | Revue d'intégration | 1 / 7 | 1 par valeur (accepter / garder) | — | — | — | — | — |
| F. Intégration | Finaliser l'embauche | **Inexistant** : activer, contrat et accès se font ailleurs | — | — | — | — | — | — | — |
| G. Contrats | Créer le contrat | Fiche › Documents & contrats | 1 / 7 | ≥ 2 | Date d'entrée ressaisie | — | — | `workforce.contracts` | — |
| H. Planning | Planifier | `/workforce/planning` (7 061 lignes) | — | — | — | Refus « contrat actif » traduit (ADR-812) | — | `planning_*` | — |
| I. Temps | Pointer / valider | `/workforce/me` (salarié), `/workforce/time` (gestion) | — | — | — | — | Le salarié voit l'écran de **gestion** (R5) | `timesheet_*` | — |
| J. Congés | Demander / valider | `/workforce/me` (demande), `/workforce/leave` (1 577 lignes, les deux) | 1 / ≈5 (compté approximativement dans le tiroir de Mon activité) | 2 | — | Sans solde : expliqué (ADR-812) | — | `leave_*` | — |
| K. Paie | Préparer | `/workforce/payroll`, `/dsn` | — | — | — | — | — | `payroll_*` | — |
| L. Expéditions | Créer → livrer | `/shipments`, `/my-deliveries` | 1 / 4 (`shipments/create.vue`) | — | — | — | — | `shipments.*` | Chauffeur mobile : pas de débordement |
| M. Modules | Activer et configurer | `/company/modules` (2 167 lignes) + assistant | — | — | — | — | Réglages, devis et impact lisibles par l'API sans droit (R2) | `modules.manage` | — |
| N. Paramètres | Trouver un réglage | Hub `/company/settings` → 5 modules | — | — | — | — | Aucune garde de routeur (R6) | Administratifs | — |
| O. Abonnement | Payer, changer d'offre | `/company/billing/{tab}` | — | — | — | — | — | `billing.manage` | — |
| P. Notifications | Lire | cloche + `/company/notifications` | — | — | — | — | — | Tous | — |

**Embauche complète aujourd'hui** (personne → contrat → pièces → accès), parcours le plus fréquent :
- **Écrans** : 4 au minimum (ajout, fiche › contrat, demandes de pièces pour une personne sans compte, invitation).
- **Formulaires** : 3 à 4.
- **Champs** : 20 environ (9 + 7 + demandes), voire 13 de plus avec la sélection d'éléments si l'intégration à distance est utilisée.
- **Créations** : deux créations de personne possibles pour une même recrue.

**Cible (R3)** : 1 assistant, 3 étapes visibles. La personne, le poste et le mode sont choisis une seule fois ; le contrat est prérempli.
