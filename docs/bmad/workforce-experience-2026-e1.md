# WORKFORCE EXPERIENCE 2026 — E1 : Audit UX, benchmark, architecture cible & cadrage entrée/sortie

> Incrément de **cadrage** (ADR-689). Aucune refonte d'écran livrée ici — maquettes/prototypes browsables + plan E2→E6. Fondé sur le code réel (ADR-673 → 688).

## 0. Point de départ honnête

P1 (LOT A/B/C) + P2 + P3 ont **déjà** beaucoup consolidé : espaces métier à onglets (Documents, Temps de travail, Paie & déclarations), accueil orienté actions unique, Organisation (départements/postes/lieux), paramètres RH rationalisés, fiche collaborateur unifiée + cycle de vie des accès (ADR-688). La nav Workforce est donc **déjà largement orientée activités** (9 entrées). E1 cible ce qui **reste faible** : (a) **qualité visuelle** (écrans « admin génériques »), (b) **cycle entrée/sortie** (sortie quasi absente), (c) **accès personnel post-départ** (inexistant), (d) **parcours non-RH** (manager/paie/salarié) à rendre naturels.

---

## 1. Benchmark RH 2026 (pratiques publiques observables — adaptées, non copiées)

| Produit | Pattern de nav | Forces UX retenues pour Leezr |
|---|---|---|
| **Personio** | Nav par activité (People, Time, Payroll, Recruiting) + profil employé central riche | Profil employé = hub ; onboarding/offboarding en **workflows à étapes assignables** ; « to-do » par rôle |
| **Factorial** | Espaces (Team, Time, Documents, Finance) + « Inbox » de tâches | **Inbox de validations** transverse ; time tracking mobile-first ; documents par personne |
| **Rippling** | Plateforme « employee graph » ; onboarding/offboarding orchestrés multi-systèmes | **Offboarding checklist** (révocation accès + derniers éléments) ; granularité permissions fine |
| **Deel** | Contrats + compliance par pays ; portail contractor/employee | **Compliance par Market** ; documents de fin de contrat guidés ; self-service fort |
| **Lucca (FR)** | Modules FR (Figgo congés, Timmi temps, Pagga paie/coffre-fort) ; **coffre-fort bulletins** | **Coffre-fort du salarié** (bulletins accessibles longue durée) ; conformité FR ; validations managériales |
| **HiBob** | « Bob » social + org chart interactif + workflows RH | **Org chart** navigable ; timeline de cycle de vie ; home personnalisé par rôle |

**Bonnes pratiques transverses retenues** : (1) nav **par activité**, peu profonde ; (2) **profil/fiche = hub** avec timeline de cycle de vie ; (3) **Inbox de tâches/validations** par rôle (manager/RH) ; (4) **workflows entrée/sortie** à étapes assignables, reprenables, tracés ; (5) **coffre-fort documentaire** du salarié (accès longue durée indépendant de l'emploi) ; (6) **conformité par Market** explicite ; (7) **self-service salarié** complet (pas une version diminuée).

**Non-objectifs** : copier un écran concurrent ; recréer un « employee graph » global ; ajouter de l'IA non demandée.

---

## 2. Cinq personas de conception (⚠️ PAS des rôles codés — le RBAC reste la source de vérité)

| Persona | Objectif principal | Permissions typiques (existantes) | Point d'entrée naturel |
|---|---|---|---|
| **Dirigeant** | Comprendre l'activité, décider | owner / is_administrative | Accueil (priorités + situation) |
| **RH** | Embauches, contrats, dossiers, congés, sorties, documents | workforce.view/manage/contracts, leave_*, documents.*, members.invite/manage | Collaborateurs + fiche |
| **Manager / exploitation** | Équipe, planning, présences, pointages, anomalies, validations | planning_*, timesheet_view/approve, time_manage, leave_approve (scopé) | Temps de travail (Aujourd'hui) + Inbox |
| **Gestionnaire de paie** | Périodes, variables, validations, documents paie | payroll_prepare/validate/export, compensation_read | Paie & déclarations |
| **Salarié** | Planning, pointage, heures, congés, documents, bulletins, signatures | self (workforce.me) | Portail « Mon espace » |
| **Ancien salarié** | Ses documents personnels (bulletins, contrat, sortie) | **capacité post-départ dédiée** (à créer, E5) | Espace personnel post-départ |

**Règle d'or** : le menu/les écrans s'affichent selon **permissions réelles + capacités + company scope + manager scope + ownership + ModuleGate + Market + JobDomain** — jamais `role === 'HR'`.

---

## 3. Inventaire des écrans Workforce (Écran → Activité → Utilisateurs → Permissions → Problème UX → Cible)

| Écran actuel | Activité | Utilisateurs | Permissions | Problème UX | Proposition cible |
|---|---|---|---|---|---|
| Accueil (`/dashboard`) | Comprendre / agir | tous (selon perms) | workforce.view | OK (P3-B) ; peu de différenciation par rôle | **Home par rôle** : + Inbox validations (manager/RH) |
| Employés (`employees`) | Annuaire | RH/dirigeant/manager | workforce.view | Liste plate ; embauches/départs non distingués | **Sous-vues** : Annuaire · **Embauches en cours** · **Départs en cours** |
| Fiche (`employees/[id]`) | Dossier individuel (hub) | RH/manager (scopé) | workforce.view + scope | Riche mais dense ; pas de **timeline cycle de vie** ; sortie absente | **Fiche 2.0** : timeline entrée→vie→sortie ; actions « Préparer le départ » |
| Temps de travail | Aujourd'hui/Planning/Présences/Feuilles | manager/expl./salarié | timesheet_view, planning_*, time_manage | Bon (LOT B) ; onglets denses | Polir densité ; **Inbox** feuilles à valider |
| Congés | Demandes/Calendrier/Soldes | RH/manager/salarié | leave_* | Bon ; soldes/politiques à clarifier | Polir ; validations dans Inbox |
| Documents | Générés/Modèles/Signatures | RH/salarié (self) | documents.* | Bon (LOT A) ; registre vs perso à clarifier | Distinguer **coffre-fort salarié** vs registre RH |
| Paie & déclarations | Périodes/Déclarations | paie | payroll_* | Bon (LOT C) ; honnête | Polir ; variables (absentes) documentées |
| Organisation | Départements/Postes/Lieux | admin | workforce.admin | Bon (P2-C) | **Org chart** visuel (managers/rattachements) |
| Paramètres RH | Config (general/worktime/contract/signature) | admin | workforce.view | Bon (P2-B) | Polir |
| Mon espace (`me`) | Self-service | salarié | module workforce | Correct mais « version diminuée » | **Portail salarié 2.0** (quotidien, pas sous-ensemble RH) |
| **Sortie / offboarding** | Préparer le départ | RH | — | **ABSENT** (seul `TerminateEmployeeUseCase`) | **Workflow « Préparer le départ »** (E4) |
| **Solde de tout compte / docs fin de contrat** | STC, certificat, attestation | RH/paie | — | **ABSENT** | **Éléments vérifiés → génération documentaire** (E4) |
| **Accès post-départ** | Consulter ses docs après sortie | ancien salarié | — | **ABSENT** | **Espace personnel post-départ** (E5) |

**Problèmes transverses** : densité visuelle « admin » ; différenciation par rôle faible sur l'accueil ; pas d'Inbox de validations ; cycle de vie employé non visualisé ; sortie non orchestrée.

---

## 4. Navigation : actuelle vs cible

**Actuelle** (≈18 entrées, groupes) : Accueil · Employés · Temps de travail · Congés · Paie · Documents · Organisation · Paramètres RH · Mon espace · Paramètres du compte · Membres · Rôles · Workflows · Profil entreprise · Facturation · Journal d'audit · Modules · Expéditions.

**Cible Workforce** (orientée activités, profondeur réduite — les sous-vues sont des **onglets**, pas des entrées) :

```
ACCUEIL                     (home par rôle : à traiter + situation)
COLLABORATEURS              → Annuaire · Embauches · Départs · (fiche = détail)
TEMPS DE TRAVAIL            → Aujourd'hui · Planning · Présences · Feuilles
CONGÉS & ABSENCES           → Demandes · Calendrier · Soldes & politiques*
DOCUMENTS                   → Mes documents / Registre RH* · Modèles* · Signatures*
PAIE & DÉCLARATIONS         → Périodes · Déclarations*
ORGANISATION                → Départements · Postes · Responsables · Lieux
PARAMÈTRES                  → config réellement administrable
MON ESPACE                  (portail salarié ; groupe « Compte »)
```
(*) onglet/section visible **uniquement** si permission + module. L'admin cœur (Membres/Rôles/Facturation/Audit/Modules) reste dans « Entreprise » (hors Workforce). Expéditions = JobDomain logistique (hors Workforce).

**Principe** : chaque entrée = un **espace de travail** cohérent (pas une page technique) ; une capacité peut être atteinte depuis plusieurs points (ex. valider un congé depuis l'Inbox OU depuis Congés) **sans dupliquer l'implémentation**. 100 % manifest-driven (NavBuilder/NavGroupRegistry), permission-gated, aucune liste Workforce codée en dur, aucun 2ᵉ RBAC.

---

## 5. Matrice activités × utilisateurs × permissions (extrait)

| Activité | Dirigeant | RH | Manager | Paie | Salarié |
|---|---|---|---|---|---|
| Voir l'accueil (priorités) | ✓ | ✓ | ✓ (équipe) | ✓ | — (portail perso) |
| Annuaire collaborateurs | ✓ | ✓ | ✓ (équipe) | lecture | — |
| Créer/éditer fiche | — | ✓ (manage) | — | — | self (profil) |
| Préparer contrat | — | ✓ (contracts) | — | — | — |
| Planning (créer/publier) | — | ✓ | ✓ (planning_manage/publish) | — | lecture (self) |
| Corriger un pointage | — | ✓ | ✓ (time_manage, scopé) | — | — |
| Valider feuille de temps | — | ✓ | ✓ (timesheet_approve, scopé) | — | soumettre (self) |
| Approuver congé | — | ✓ | ✓ (leave_approve, scopé) | — | demander (self) |
| Voir rémunérations | — | si compensation_read | ✗ | ✓ (compensation_read) | self (ses bulletins) |
| Préparer la paie | — | — | — | ✓ (payroll_prepare) | — |
| Inviter / gérer accès | — | ✓ (members.invite/manage) | — | — | — |
| **Préparer le départ** | — | ✓ (nouveau) | — | éléments paie | — |
| **Accès post-départ** | — | — | — | — | **ancien salarié (capacité dédiée)** |

Clé : les cases « Manager » sont **manager-scopées** (`WorkforceAccess::canViewEmployeeData/visibleEmployeeIds`). Aucune case ne dépend d'un intitulé de poste.

---

## 6. Audit du cycle entrée/sortie (capacités réelles)

### Entrée (partiellement livré)
- ✅ Création employé, profil, contrat (`EmploymentContract`), génération contrat (templates), signature (module Signature), invitation/accès (`EmployeeAccessService`), checklist d'intégration (fiche : info/contrat/signature/compte/planning).
- ⚠️ **Manque** : un **parcours guidé reprenable** unique « Embaucher » (aujourd'hui = checklist + actions dispersées) ; étapes assignables à un responsable sans tous les droits RH.

### Sortie (quasi absente)
- ✅ `TerminateEmployeeUseCase` : `Employee.status → terminated`, `termination_date`, termine le contrat courant, audit. ADR-688 : suspension/révocation d'accès.
- ❌ **Manque tout le reste** : workflow « **Préparer le départ** » (nature/dates/préavis/dernier jour), derniers temps/congés restants, **éléments de paie de fin**, **indemnités**, **dernier bulletin**, **reçu pour solde de tout compte (STC)**, **certificat de travail**, **attestation France Travail**, documents complémentaires, signature éventuelle, **retrait des accès à la date d'effet**, **accès personnel post-départ**, clôture administrative tracée/idempotente/récupérable.

### Solde de tout compte (STC) — modèle à concevoir
Distinguer : **calcul des sommes dues** → **préparation des éléments** → **validation par une personne habilitée** → **génération du dernier bulletin** → **génération du reçu STC** → **remise** → **signature facultative** → **conservation des preuves**. ⚠️ Le moteur Payroll est **indicatif/non exhaustif** (ADR-679) → **ne pas prétendre** générer un STC réglementairement fiable automatiquement. Mécanisme : **éléments explicitement vérifiés/validés** par un habilité → génération documentaire **fidèle à ces données** (pas de règle juridique inventée par contrat/Market).

---

## 7. Accès post-départ & conservation (France — à valider auprès de sources officielles)

| Sujet | Règle (à vérifier officiellement) | Implication produit |
|---|---|---|
| **Bulletins de paie électroniques** | **C. trav. art. D3243-8** : l'employeur garantit la disponibilité du bulletin électronique au salarié **pendant 50 ans ou jusqu'aux 75 ans du salarié** (la plus longue des deux) | **Coffre-fort du salarié** avec accès **longue durée indépendant de la Membership** |
| **Certificat de travail** | **L1234-19** : remis **obligatoirement** en fin de contrat | Document de sortie généré + remis |
| **Reçu pour solde de tout compte** | **L1234-20** : inventaire des sommes versées ; dénonçable 6 mois si signé | Document STC + signature facultative tracée |
| **Attestation France Travail (ex-Pôle emploi)** | Obligatoire en fin de contrat (droits chômage) | Document de sortie (données vérifiées ; pas de calcul inventé) |
| **Conservation employeur** (bulletins) | ~5 ans (copie employeur, L3243-4) ≠ disponibilité salarié | Politiques **par Market + type de document** |
| **Fermeture Company / fin d'abonnement / fin du SaaS** | Continuité d'accès à étudier (export avant fermeture) | Politique de continuité (⚠️ **Billing non modifié ce lot**) |

**Principe de conception** : durées/politiques **paramétrables par Market, type de document et règles applicables** — **jamais** « 12 mois » codé en dur, **jamais** de règle juridique inventée. Les chiffres ci-dessus sont **à confirmer** (sources officielles) avant implémentation E5.

---

## 8. Architecture : accès personnel post-départ (conception, E5)

Problème : un ancien salarié n'a plus de Membership active (révoquée) → `SetCompanyContext` le bloque (ADR-688). Il faut une **capacité personnelle sûre**, **indépendante d'une Membership professionnelle**, limitée à la **propriété prouvée du document**.

**Esquisse** (à détailler en E5, aucune implémentation E1) :
- **Identité personnelle** : le User persiste (ADR-688 : revoke ne supprime pas le User). Authentification par **e-mail personnel** (⚠️ prévoir le cas de l'e-mail **pro désactivé** → mécanisme de mise à jour/vérification d'e-mail personnel **avant** le départ, étape du workflow de sortie).
- **Portail post-départ** = route **hors `SetCompanyContext`** (pas de contexte Company complet), gated par une **capacité « self-document »** : accès **uniquement** aux `GeneratedDocument` dont le **subject est l'ancien salarié** (ownership prouvé : `subject_type=employee/payroll_line/contract` lié à son `employee_id`/`user_id`), filtrés par **type autorisé** (bulletins, contrats, avenants, documents de sortie) et **politique de conservation**.
- **Interdits** : équipes, planning, données RH des collègues, permissions managériales, administration Company. **Pas** de simple exclusion du middleware — une **vraie capacité d'autorisation personnelle** scopée à l'ownership.
- **Tests** : accès direct par ID (doc d'autrui → 403), multi-Company (voir ses docs de chaque company sans accès Company), réembauche (nouvelle Membership ≠ retour des privilèges).

---

## 9. Plan de développement E2→E6 (complexité · dépendances · risques)

| Incrément | Contenu | Complexité | Dépendances | Risques |
|---|---|---|---|---|
| **E2 — Navigation** | Nav orientée activités (manifest-driven), sous-vues en onglets, permission-gating par rôle, deep-links, tests par persona | **M** | NavBuilder/NavGroupRegistry (existant) | régression nav / permissions ; déjà bien avancé (P1-P3) → risque **faible** |
| **E3 — Refonte écrans** | Design system Workforce (en-tête, barre d'actions, table de gestion, détail contextuel, timeline, statuts, états vides) ; refonte Collaborateurs/Fiche/Temps/Congés/Documents/Organisation/Paie | **L** | Vuetify/Vuexy ; services métier inchangés | ampleur ; régressions visuelles ; **étaler écran par écran** avec recette avant/après |
| **E4 — Entrée/Sortie** | Workflow « Embaucher » reprenable ; workflow « **Préparer le départ** » (états/dates/étapes assignables/tracé/idempotent/récupérable) ; **STC = éléments vérifiés → documents** ; articulation révocation (ADR-688) | **XL** | Contract/Payroll(indicatif)/Documents/Signature/Access ; **orchestration** multi-modules | **juridique** (ne pas prétendre conformité) ; cohérence transactionnelle ; **le plus gros risque** |
| **E5 — Post-départ** | Capacité « self-document » hors `SetCompanyContext` ; e-mail perso vérifié ; conservation par Market/type ; téléchargements protégés ; multi-Company/réembauche | **XL** | Auth/Membership/Documents ; **sécurité** | **sécurité** (nouvelle surface d'auth) ; conformité conservation ; tests négatifs obligatoires |
| **E6 — Recette globale** | Responsive 1920/1366/768/390 + dark ; parcours 5 rôles + ancien salarié ; mesures avant/après ; corrections | **M** | E2-E5 | couverture ; mesures honnêtes |

**Ordre & dépendances** : E2 (indépendant) → E3 (après E2, écran par écran) → E4 (après E3 pour la fiche) → E5 (après E4 : la sortie prépare l'e-mail perso + les documents) → E6 (final). E4/E5 = cœur de valeur + cœur de risque (juridique + sécurité).

---

## 10. Capacités conservées (ne pas refaire)

Moteurs métier **inchangés** : Payroll (indicatif, ADR-679/681), génération documentaire (dompdf), Signature (crypto), cockpit temps/réconciliation (LOT B), LeaveLedger, EmploymentContract, EmployeeAccessService + suspension/révocation (ADR-688), NavBuilder/manifests, RBAC/ModuleGate/Market/JobDomain, confidentialité salariale serveur (ADR-680/681/684). **Frontières backend préservées** ; **pas** de fusion Employee/User/Membership ; **pas** de 2ᵉ paie/registre doc/RBAC/auth.

---

## 11. Critères d'acceptation E2→E6 (objectifs)

- **E2** : chaque entrée de nav = un espace ; profondeur ≤ 1 (onglets) ; un manager/paie/salarié voit **exactement** les entrées que ses permissions autorisent (tests par persona, refus serveur) ; 0 route cassée ; deep-links OK.
- **E3** : avant/après par écran ; densité réduite (≤ 2 actions principales visibles) ; design system réutilisé ; états vides utiles ; responsive + dark ; **services métier inchangés** (0 régression test).
- **E4** : workflow sortie **à états, tracé, reprenable, idempotent** ; STC = **éléments validés par un habilité** → document fidèle (jamais « conformité auto ») ; révocation d'accès **à la date d'effet** (ADR-688) ; aucune perte d'historique.
- **E5** : ancien salarié accède **uniquement** à ses documents autorisés (tests négatifs : doc d'autrui → 403, accès Company → refusé) ; conservation paramétrable par Market/type ; e-mail perso vérifié avant départ.
- **E6** : parcours des 5 rôles + ancien salarié démontrés en navigateur ; mesures avant/après **réelles** ; cohérence visuelle 1920/1366/768/390 + dark.

---

## 12. Livrables E1
1. Ce dossier (audit + benchmark + matrices + nav + E/S + conservation + plan + critères).
2. **Prototype browsable** (Artifact HTML) : nav cible, écrans phares redesignés (Accueil par rôle, Collaborateurs embauches/départs, Fiche 2.0 avec timeline, **workflow « Préparer le départ »**, Espace post-départ), vues par rôle.
3. Captures « avant » (DEV réel) pour le avant/après.
4. ADR-689 (décision de cadrage).

**Aucune refonte d'écran livrée en E1.** E2→E6 engagés seulement après validation de la direction UX.
