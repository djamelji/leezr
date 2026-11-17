# ADR-815 — Architecture fonctionnelle cible de l'espace Company

> Proposition soumise à validation. **Rien n'est implémenté.**
>
> Principes :
> - les menus restent **construits par les manifestes des modules** (`NavBuilder`, groupes métier) ;
> - un utilisateur ne voit que ce que ses permissions et sa fiche (salarié lié) lui ouvrent ;
> - pas de nouveau moteur ;
> - les écrans spécialisés indispensables (planning, pointage, paie, expéditions) sont conservés.

## 1. Arborescence cible

```
Accueil                       /home                    (core.dashboard)       tous
Équipe                        /company/people          (core.members)         members.view | workforce.view
  ├─ Personnes                 liste + fiche /company/people/{personRef}
  │    fiche : Identité · Poste & contrat · Embauche · Documents · Temps · Congés · Accès
  ├─ Embaucher                 assistant (dialogue) depuis la liste
  └─ Intégrations en cours     onglet de la liste (dossiers ouverts)
Organisation                  /company/organization    (workforce)            workforce.view (lecture) / settings.manage (édition)
  ├─ Services
  ├─ Postes                    fiche poste : description · exigences d'intégration (champs, pièces, documents produits)
  └─ Lieux
Documents                     /company/documents/{tab} (core.documents)       documents.view | workforce_documents.view
  ├─ À traiter                 file unique : dépôts, intégration, renouvellements, signatures en attente
  ├─ Demandes & campagnes
  ├─ Registre
  ├─ Modèles                   documents produits par l'entreprise
  └─ Paramètres                types de pièces de la société (activation, formats, expiration)
Planning & temps              groupe « time »
  ├─ Planning                  /company/workforce/planning   workforce.planning_view
  ├─ Pointage & feuilles       /company/workforce/time       workforce.time_manage | timesheet_approve | timesheet_approve_team (gestion ; `timesheet_view` = soi uniquement)
  └─ Congés                    /company/workforce/leave      workforce.leave_view_all | leave_approve
Paie                          /company/workforce/payroll, /dsn   workforce.payroll_*
Expéditions                   /company/shipments            shipments.view
Finance                       /company/billing/{tab}        billing.manage
Réglages                      /company/settings  (hub)      administratif
  ├─ Entreprise (profil, identité légale)
  ├─ Rôles & accès             /company/roles  (droits UNIQUEMENT)
  ├─ Modules                   /company/modules
  ├─ Règles RH                 /company/workforce/settings/{tab} (temps de travail, convention, congés)
  ├─ Journal d'audit           /company/audit
  └─ Automatisations           /company/workflows
Menu personnel
  ├─ Mon espace                /company/my-space/{tab}  (mes documents, signatures, bulletins, mes informations)
  ├─ Mon activité              /company/workforce/me   (si salarié lié : pointage, planning, congés, heures)
  ├─ Mes livraisons            /company/my-deliveries  (si shipments.view_own)
  └─ Compte                    /account-settings/{tab}
Public : /login, /register, /invitations, /reset-password, /onboarding (portail recrue), /sign/{token}, /help-center
```

## 2. Sections : public, objectif, actions, données, modules, droits, liens

| Section | Public | Objectif métier | Actions principales | Données | Modules contributeurs | Permissions | Liens |
|---|---|---|---|---|---|---|---|
| **Accueil** | Tous | Savoir quoi faire maintenant | Pointer, ouvrir une tâche, voir les indicateurs | Bloc Moi (self), À traiter (périmètre), Entreprise (collectif) | Tous, par contributions (`HomePrioritiesReadModel`, widgets) | Blocs gardés par permission ; widget `company` = droit de lecture collective explicite | Vers chaque outil |
| **Équipe** | RH, propriétaire, managers (périmètre) | Connaître et gérer les personnes | Embaucher, compléter une fiche, inviter, suspendre | Personnes, postes, contrats, état d'intégration, accès | core.members, workforce, workforce_contract, documents | `members.view` (annuaire), `workforce.view` (RH, scopé) | Documents, Organisation, Planning |
| **Organisation** | RH, direction | Décrire l'entreprise : services, postes, lieux | Créer un poste, définir ses exigences, adopter un preset | `workforce_departments`, `workforce_job_roles`, `requirements` (poste), lieux | workforce, presets métier | lecture `workforce.view` ; édition `settings.manage` ou `documents.configure` | Équipe (affectation), Documents |
| **Documents** | RH, gestionnaires documentaires | Obtenir, valider, conserver les pièces ; produire les documents | Valider ou refuser, demander, lancer une campagne, générer, faire signer | Demandes, pièces, modèles, signatures | core.documents, workforce_documents, workforce_contract, workforce_signature | `documents.*`, `workforce_documents.*`, `workforce_signature.*` | Fiche personne (onglet Documents), Embauche |
| **Planning & temps** | Planificateurs, managers | Organiser le travail et contrôler le temps | Planifier, publier, corriger, valider | Créneaux, pointages, feuilles, congés | workforce_planning, workforce, workforce_leave | `planning_*`, `time_manage`, `time_approve`, `timesheet_approve(_team)`, `leave_*` | Fiche personne, Paie |
| **Paie** | Gestionnaire de paie | Préparer et déclarer | Calculer, valider, exporter, DSN | Runs, bulletins | workforce_payroll | `payroll_*` | Documents (bulletins) |
| **Expéditions** | Exploitation | Gérer les livraisons | Créer, planifier, assigner, suivre | Expéditions | logistics_shipments | `shipments.*` | Mes livraisons (chauffeur) |
| **Finance** | Propriétaire | Abonnement, factures, paiement, stockage | Payer, changer d'offre, télécharger | Abonnement, factures | core.billing | `billing.manage` | Modules |
| **Réglages** | Administrateurs | Paramétrer l'entreprise et les accès | Rôles, modules, règles RH, audit | Configuration | core.settings, core.roles, core.modules, workforce | administratif + clé de chaque sous-section | — |
| **Menu personnel** | Tout utilisateur | Mes informations et mes tâches | Déposer une pièce, signer, pointer, demander un congé | Les miennes uniquement | core.documents, workforce | aucune permission de gestion (`/profile/space` décide) | Accueil (bloc Moi) |

## 3. Écrans supprimés de l'architecture cible

| Écran actuel | Raison | Devenir |
|---|---|---|
| `/dashboard` | Doublon de `/home` (même moteur, même grille) | Alias vers `/home` |
| `/company/members`, `/company/members/:id`, `/company/workforce/employees`, `/company/workforce/employees/:id` | Déjà redirigés vers Équipe ; ils gardent 460 et 430 lignes d'ancienne interface | Alias de 10 lignes ; code mort retiré (P4) |
| `/company/workforce` | Redirige vers `/dashboard` | Alias vers `/home` |
| `/company/workforce/configuration`, `/documents`, `/signature`, `/contract/templates`, `/company/plan`, `/company/billing` | Redirections historiques | Conservées comme alias (liens existants) |
| `/company/workforce/requirements` (« mode parallèle ») | La configuration des exigences passe sur la fiche **Poste** | Fusionné dans Organisation → Postes |
| `/company/workforce/onboarding` (assistant RH) | Homonyme du dossier d'intégration ; doublon des assistants de module (ADR-803) | Fusionné dans Réglages → Règles RH (assistant de module) |
| `/company/dev-lab`, `/ui-lab` | Laboratoires de développement | Retirés de la build de production, ou gardés derrière un drapeau DEV |
| Bloc « Mes widgets » de `/home` | Grille collective dans l'accueil personnel | Devient le bloc « Entreprise », réservé aux détenteurs du droit collectif |

## 4. Articulation des fonctions complémentaires (sans duplication)

- **Poste ↔ Documents** : le poste **définit** ce qui est exigé ; Documents **traite** les demandes qui en découlent ; la fiche personne **montre** l'état pour une personne. Il y a une seule file « À traiter ».
- **Embauche ↔ Intégration à distance** : un seul dossier (moteur `OnboardingInvitationService`). « Sur place » et « à distance » sont deux modes de saisie du **même** dossier.
- **Rôles & accès ↔ Poste** : le rôle donne des droits ; le poste donne des exigences et un métier. Le choix du rôle se fait à l'ouverture d'un accès, jamais à l'embauche.
- **Accueil ↔ outils** : l'accueil résume et renvoie ; il n'édite rien.
- **Mon espace ↔ Mon activité** : Mon espace = documents et informations ; Mon activité = temps de travail. Les deux sont gardés, mais leurs blocs « documents » ne se recoupent plus (ADR-813 a clarifié « Documents reçus »).
