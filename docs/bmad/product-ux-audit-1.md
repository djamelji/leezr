# PRODUCT-UX-AUDIT-1 — Audit transversal de l'espace Company (Leezr)

**Date** : 2026-10-31 · **Scope** : espace Company + portail salarié + dépendances Platform · **Environnement audité** : DEV réellement servi (`dev.leezr.com`), rôle owner + rôle limité (bob).
**Méthode** : inventaire exhaustif du code (routes auto-générées, manifests de modules, composants), navigation réelle en navigateur (captures `scratchpad/audit/`), tests de permission réels. Légende des constats : **[OBS]** observé en navigateur · **[CODE]** déduit du code · **[HYP]** hypothèse UX · **[ABS]** fonctionnalité absente/non livrée.

> **Thèse** : Leezr est aujourd'hui organisé comme une **collection de modules techniques exposés 1:1 en pages**. L'entreprise doit connaître notre découpage interne (Workforce, Planning, Pointage, Documents, Contract, Signature, Payroll, DSN) pour accomplir une activité métier simple. Le découplage backend est sain ; sa **projection 1:1 en frontend** est la cause première de la charge cognitive.

---

## A. DIAGNOSTIC GLOBAL

- **Pages Company auditées** : ~45 fichiers de pages routées (hors sous-composants `_*.vue`). Domaines : Workforce (≈18 pages), Core/admin (≈20), Billing (6), Logistics (5).
- **Modules concernés** : ~26 (14 core + 7 addons Workforce + 4 Logistics + 1 Payments). Dépendances : `workforce` racine ; `planning/leave/documents` → workforce ; `contract/signature` → documents ; `payroll` → workforce+leave.
- **Pages/actions redondantes majeures** : au moins **10 chevauchements** fonctionnels (détail §C, matrice A→J).
- **Parcours fragmentés** : les 7 activités métier clés traversent 2 à 5 pages et 2 à 4 modules (matrice §E).
- **Charge cognitive — causes principales** :
  1. **Projection module→page 1:1** : chaque addon = une entrée de nav + une page, même quand l'activité est la même (ex. Documents / Contrats / Signatures = 3 pages pour « les documents »).
  2. **Doublons d'actions** : la même opération existe sur 2 pages (génération de bulletins = Paie **et** Documents RH ; signature déclenchable depuis Documents **et** Signatures).
  3. **Pages « lanceur »** : `/company/workforce` (Tableau de bord RH) et `Paramètres RH` présentent des **cartes/onglets qui ne font que renvoyer** vers d'autres pages. **[OBS]**
  4. **Homonymies** : « Rôles » = RBAC (CompanyRole) **et** postes RH (JobRole) ; « Documents » = documents company (core) **et** documents RH (workforce). **[OBS/CODE]**
  5. **Fragmentation de l'identité** : une personne existe en 3 entités (User / Membership / Employee) réparties sur 4 pages (Membres, Rôles, Organisation, Collaborateurs) sans vue unifiée. **[CODE]**
  6. **Redondance fiche ↔ pages globales** : la fiche collaborateur a déjà des onglets Planning/Pointage/Congés/Documents qui dupliquent les pages globales (mini-tableaux + « voir tout »). **[CODE]**

**Ce qui rend Leezr compliqué aujourd'hui** : pour « faire signer un contrat », une RH doit comprendre qu'un **modèle** vit dans *Contrats de travail*, que la **génération** se déclenche depuis la *fiche*, que le **PDF** atterrit dans *Documents RH*, que la **demande de signature** se pilote dans *Signatures*, et que le **document signé** réapparaît dans les trois. Aucune de ces pages n'est « fausse » ; leur **multiplication** l'est.

---

## B. AUDIT PAGE PAR PAGE (synthèse ; captures dans `scratchpad/audit/`)

| # | Page / route | Module | Usage | Utilisateur | Fréquence | Redondances | Problème UX | Décision |
|---|---|---|---|---|---|---|---|---|
| 1 | `/dashboard` | core.dashboard | Accueil, widgets configurables | Dirigeant/RH | Quotidien | **2ᵉ dashboard** (hub RH) | OK mais coexiste avec le hub RH | **Conserver** (accueil unique) |
| 2 | `/company/workforce` (Tableau de bord RH) **[OBS]** | workforce | Cartes-modules + KPI + actions rapides | RH | Occasionnel | Duplique nav + dashboard | **Présente le produit comme des modules** | **Transformer** en landing orientée tâches OU fusionner dans dashboard |
| 3 | `/company/workforce/employees` (liste) | workforce | Annuaire, filtres, import/export, bulk | RH | Quotidien | — | Bon | **Conserver** |
| 4 | `…/employees/[id]` (fiche) | workforce | Hub par personne (8 onglets) | RH | Quotidien | onglets planning/time/leave/docs = mini-doublons des pages globales | Déjà le bon « hub personne » | **Conserver + enrichir** (pivot central) |
| 5 | `…/planning` | workforce_planning | Grille/calendrier, templates, lieux | RH/Manager | Quotidien | lieux ⊂ planning (devrait être Organisation) | Prévu non confronté au réel (sauf cockpit) | **Fusionner** → Temps de travail |
| 6 | `…/time` (cockpit/entries/timesheets) | workforce | Pointages, feuilles de temps, écarts | RH/Manager | Quotidien | cockpit = seul lieu prévu/réel | Séparé de Planning | **Fusionner** → Temps de travail |
| 7 | `…/leave` (requests/calendar/balances/config) | workforce_leave | Congés (déjà 4 onglets) | RH/Manager/Salarié | Quotidien | soldes aussi fiche+portail | **Déjà unifié** (bon modèle) | **Conserver** (référence à suivre) |
| 8 | `…/payroll` (+ `[id]`) | workforce_payroll | Cycles de paie, calculs, bulletins | RH | Mensuel | **génération bulletins = aussi Documents RH** | Workflow réel (pas simu) | **Conserver + fusionner** avec DSN |
| 9 | `…/dsn` (+ `[id]`) | workforce_payroll | Déclarations sociales | RH | Mensuel | Export depuis run validé (lien paie) | Gateway abstraite (sandbox/file/null) — **ne pas présenter comme URSSAF validé** [CODE] | **Fusionner** avec Paie (étape « Déclarer ») |
| 10 | `…/documents` (Documents RH) **[OBS]** | workforce_documents | Registre docs générés + 2 cartes génération bulletins | RH | Hebdo | **cartes génération = doublon Paie** | Mélange registre + action paie | **Transformer** (registre pur) + **fusionner** avec Contrats/Signatures |
| 11 | `…/contract-templates` | workforce_contract | Bibliothèque de **modèles** de contrat | RH admin | Occasionnel | — (mais perçu comme « les contrats ») | Nom ambigu (modèles vs contrats réels) | **Fusionner** → espace Documents (onglet Modèles) |
| 12 | `…/signature` | workforce_signature | Demandes de signature (6 statuts) + supervision | RH | Hebdo | signature aussi déclenchée depuis Documents + fiche | Objet « signé » exposé à 3 endroits | **Fusionner** → espace Documents (onglet Signatures) |
| 13 | `…/organization/[tab]` (Départements, Rôles) | workforce | Structure RH | RH admin | Config | **lieux absents** (dans Planning) ; **« Rôles » = JobRole** (homonyme RBAC) | Collision de nommage | **Conserver + clarifier** (postes ≠ rôles) ; rapatrier lieux |
| 14 | `…/settings/[tab]` (Paramètres RH) **[OBS]** | workforce | 9 onglets ; plusieurs = **renvois** | RH admin | Config | onglets = liens ; `rules` dup `worktime` | Centre-lanceur partiel | **Transformer** (config réelle seulement) |
| 15 | `…/configuration` | workforce | **Redirige** vers settings/worktime | — | — | legacy | Route morte | **Retirer de la nav** (garder redirect) |
| 16 | `…/onboarding` | workforce | Assistant d'organisation | RH | 1 fois | lien dupliqué (settings+organization+hub) | Point d'entrée dispersé | **Conserver** (1 seul point d'entrée) |
| 17 | `…/me` (Mon espace) | workforce | Portail salarié (pointage/planning/contrat/congés/bulletins/docs) | Salarié | Quotidien | soldes/docs dupliqués (normal côté salarié) | Bon | **Conserver** |
| 18 | `/company/members` (+ `[id]`) | core.members | Comptes applicatifs (Membership) | RH admin | Occasionnel | chevauche Collaborateurs | Membre ≠ Collaborateur (confus) | **Conserver + relier** à la fiche |
| 19 | `/company/roles` | core.roles | RBAC (CompanyRole + permissions) | Admin | Config | homonyme « Rôles » JobRole | Collision | **Conserver + renommer** clairement |
| 20 | `/company/profile/[tab]` | core.settings | Profil entreprise (**1 onglet** overview) **[OBS]** | Admin | Config | — | Coquille partielle (UI réduite) | **Conserver** (à étoffer, hors scope) |
| 21 | `/company/documents/[tab]` | core.documents | Coffre documents **company** (vault/requests/compliance) | Admin | Occasionnel | homonyme « Documents » RH | `/company/documents` sans tab → **404** [OBS] | **Conserver + renommer** (Documents entreprise) |
| 22 | `/company/modules` | core.modules | Activation addons, pricing, quotes | Admin/Dirigeant | Occasionnel | — | OK | **Conserver** |
| 23 | `/company/billing/*` + `/company/plan` | core.billing | Abonnement, factures, paiement | Dirigeant | Occasionnel | plan.vue ~ billing plan tab | OK (riche) | **Conserver** |
| 24 | `/company/notifications` | core.notifications | Inbox + préférences | Tous | Quotidien | — | OK | **Conserver** |
| 25 | `/company/audit` · `/company/workflows` · `/company/support` | core.* | Journal / automatisations / tickets | Admin | Occasionnel | support **hors nav** (footer) | Support peu visible | **Conserver** (support → remonter) |
| 26 | `/company/shipments` · `/my-deliveries` | logistics_shipments (**addon**) | Expéditions / vue chauffeur | Opérations | Quotidien (si activé) | — | OK | **Conserver** (hors RH) |
| — | `account-settings/[tab]` | core.settings | Compte perso (compte/sécurité/préférences/docs) | Tous | Occasionnel | docs perso ⊂ me + profile/documents | self-service éclaté | **Conserver + relier** à Mon espace |

**Modules catalogués SANS surface UI (ne pas annoncer « disponibles »)** **[CODE]** : `logistics_tracking`, `logistics_fleet`, `logistics_analytics` (manifests, pas de page) ; `payments.stripe` (hidden stub). **DSN** : opérationnelle en UI mais **gateway commutable** (Net-Entreprises réel **ou** export fichier **ou** null/sandbox) → ne jamais présenter une soumission comme une déclaration URSSAF légalement validée sans configuration réelle.

---

## C. REDONDANCES & FRAGMENTATION (constats, A→J)

| Réf | Fragmentation | Où | Preuve |
|---|---|---|---|
| **A** | « Contrat » éclaté sur 5 surfaces | modèles (contract-templates), données (fiche onglet contract), génération (dialog fiche), PDF (documents RH), signé (documents+signatures+fiche) | [CODE/OBS] |
| **B** | Documents salarié à 3 endroits | documents RH (global), fiche onglet documents, portail me | [CODE] |
| **C** | **Génération de bulletins DUPLIQUÉE** | `payroll/[id]` **et** `documents/index` (`_PayslipActionCards`, mêmes actions store) | **[OBS]** (2 cartes vues sur Documents RH) |
| **D** | Soldes de congés à 3 endroits | leave onglet balances, fiche onglet leave, portail me | [CODE] |
| **E** | Prévu vs réel réconcilié à **1 seul** endroit | `_TimeCockpit` (planned/actual/gap) ; ailleurs déconnecté | [CODE] |
| **F** | Fiche ↔ pages globales en double | onglets planning/time/leave = mini-tableaux + « voir tout » | [CODE] |
| **G** | Signatures : double/triple exposition | signature/index + documents/index (wizard) + fiche (_EmployeeSignaturesSection) | [CODE] |
| **H** | Lieux (locations) mal rangés | gérés dans **planning** au lieu d'**organisation** | [CODE] |
| **I** | Paramètres RH = lanceur + `rules` dup `worktime` | settings onglets = renvois ; rules read-only = worktime éditable | [CODE] |
| **J** | 2 dashboards | `/dashboard` (core) **et** `/company/workforce` (hub RH cartes-modules) | **[OBS]** |
| **K** | Identité éclatée | User / Membership / Employee → Membres / Rôles / Organisation / Collaborateurs ; homonyme « Rôles » RBAC vs JobRole **au niveau code** (libellés déjà distincts : « Postes »/« Job Roles ») | [CODE] |

---

## D/F. ARCHITECTURE CIBLE (orientée activité, modules backend inchangés)

**Principe** : une **page de composition** par ACTIVITÉ métier, qui consomme plusieurs modules via leurs **interfaces publiques** (endpoints/permissions existants). Le backend reste découplé ; le frontend s'unifie. Chaque vue reste **gated** par son module + sa permission (un onglet « Signatures » n'apparaît que si `workforce_signature` actif + `view`).

### Arborescence ACTUELLE (nav RH, 11 entrées plates regroupées W-HR-UX-7)
Collaborateurs(Employés) · Temps&planning(Pointage,Planning) · Absences(Congés) · Paie&déclarations(Paie,DSN) · Documents RH(Documents,Contrats,Signatures) · Paramètres RH(Organisation,Paramètres) + hub `/company/workforce`.

### Arborescence CIBLE (par activité)
1. **Accueil** — un seul tableau de bord (fusion dashboard + hub RH, orienté « ce qui demande mon intervention »).
2. **Collaborateurs** — annuaire + **fiche = pivot** (identité, contrat, planning, pointage, congés, documents, accès). Les opérations courantes se font DANS la fiche.
3. **Temps de travail** *(composition planning+pointage)* — onglets **Planning (prévu)** · **Présences (réel)** · **Feuilles de temps (validation)** · **Écarts prévu/réel** (le cockpit devient central). Services `workforce_planning` + `workforce` (time) préservés.
4. **Absences** — **déjà conforme** (Demandes/Calendrier/Soldes/Config). Modèle de référence.
5. **Documents** *(composition documents+contract+signature)* — onglets **Documents générés** · **Modèles** (contrats) · **Signatures**. Un seul endroit pour « où est le contrat signé ? ».
6. **Paie & déclarations** *(composition payroll+dsn)* — le cycle de paie porte l'étape « Déclarer (DSN) ». Génération de bulletins **uniquement ici** (retirée de Documents).
7. **Organisation & accès** *(paramètres avancés)* — départements, **postes** (ex-« rôles » JobRole, renommés), lieux (rapatriés du planning), + liens vers Membres/Rôles RBAC. Config technique reléguée ici.
8. **Mon espace** (salarié) — inchangé.

### Pages conservées autonomes
Collaborateurs (liste+fiche), Absences, Paie, Billing, Modules, Notifications, Audit, Workflows, Mon espace, Shipments.

### Pages fusionnées en onglets/vues de composition
Planning+Pointage → **Temps de travail** · Documents+Contrats+Signatures → **Documents** · Paie+DSN → **Paie & déclarations**.

### Pages retirées de la nav (fonctionnalités conservées)
`configuration` (redirect legacy) · `/company/workforce` hub cartes-modules (→ dashboard) · onglets « lanceur » de Paramètres RH · `rules` (doublon `worktime`).

### Services techniques conservés (frontières intactes)
Tous les modules backend restent séparés (manifests, permissions, ModuleGate, ledger congés, moteur de signature/preuves, gateway DSN). **Aucune fusion backend.** Les pages de composition appellent `workforce/planning/*`, `workforce/time-entries/*`, `workforce/documents/*`, `workforce/contract/*`, `workforce/signature/*`, `workforce/payroll/*`, `workforce/dsn/*` tels quels.

### Routes historiques
Préservées par **redirect** vers le nouvel onglet (ex. `/company/workforce/contract-templates` → `/company/workforce/documents?tab=templates`), comme `configuration.vue` et `billing/index.vue` le font déjà.

---

## E. MATRICE DE FRAGMENTATION (scénarios métier — mesures reproductibles)

Mesures = navigation réelle / lecture du code. **Clics** = clics pour accomplir ; **Pages** = pages distinctes traversées ; **Modules** = modules techniques mobilisés. Aucun temps humain inventé.

| Scénario | Pages (actuel) | Modules | Clics (actuel) | Ressaisies | Blocages | Cible |
|---|---|---|---|---|---|---|
| **Embaucher un salarié** | 3–4 (liste→fiche→contrat→accès) | workforce(+documents+contract pour signer) | ~10 (W-HR-UX-4) | 0 (fiche guide) | solde congés OK (W-HR-UX-5) | déjà bon (fiche pivot) |
| **Préparer une semaine** | 1 (planning) mais lieux/templates ailleurs | planning | variable | — | — | **Temps de travail** onglet Planning |
| **Vérifier les heures d'un salarié** | 2–3 (time cockpit/entries ↔ fiche onglet time) | workforce | 2–4 | — | prévu/réel seulement cockpit | **Temps de travail** (Présences+Écarts) |
| **Approuver une absence** | 1 (leave) | leave | 2 | — | — | **déjà bon** |
| **Retrouver + faire signer un contrat** | **4** (contract-templates → fiche génère → documents → signature) | contract+documents+signature | ~6–8 | 0 | « où est le signé ? » 3 endroits | **Documents** (Modèles→génère→Signatures, 1 page) |
| **Préparer les variables de paie** | 2 (payroll run → détail lignes) | payroll(+leave+time) | ~6 | 0 | DSN sur page séparée | **Paie & déclarations** (run + onglet Déclarer) |
| **Donner accès à un collaborateur** | 1 (fiche → dialog) | workforce+core.members | 3 (W-HR-UX-3) | 0 | — | **déjà bon** |

**Lecture** : les gains de consolidation les plus forts (findability + moins de pages) concernent **« faire signer un contrat »** (4 pages → 1) et **« vérifier les heures »** (prévu/réel réunis). « Embaucher », « approuver une absence », « donner accès » sont déjà bons (fiche pivot + Congés tabbé).

---

## PARCOURS UTILISATEURS (synthèse des besoins observés)

- **Dirigeant** : veut un **seul** écran « ce qui demande mon intervention » → aujourd'hui dispersé entre `/dashboard` et le hub RH. Cible : dashboard unique orienté décisions.
- **RH** : veut agir DANS le contexte d'une personne (fiche) et trouver « les documents » / « le temps » sans deviner le module. Cible : fiche pivot + espaces Documents / Temps de travail.
- **Manager** : veut équipe + planning + présences + validations ensemble. Cible : **Temps de travail** (vue équipe) + Absences.
- **Salarié** : Mon espace couvre l'essentiel ; cohérent. À préserver.

---

## F. PLAN DE TRANSFORMATION (lots, bénéfices, dépendances, risques, tests)

### P0 — Dédoublonnages & clarifications sûrs (ce lot, prototype RÉALISÉ)
- **✅ RÉALISÉ : retirer la génération de bulletins de Documents RH** (doublon C) → la génération reste dans Paie ; Documents RH devient un **registre pur** avec un renvoi explicite vers Paie. *Bénéfice* : fin de l'ambiguïté « où génère-t-on un bulletin ». *Risque* : faible (fonction conservée dans Paie). *Test* : la page Documents RH ne montre plus les cartes ; Paie inchangée.
- **Homonymie « Rôles » — déjà atténuée au niveau LIBELLÉ** : l'onglet Organisation affiche **« Postes »** (FR) / **« Job Roles »** (en), distinct du RBAC « Rôles ». Il reste une **dette de nommage au niveau CODE** (route `?tab=roles`, store `jobRoles`, clés `organization.roles.*`) — non visible par l'utilisateur, à nettoyer dans un lot technique.
- *(reporté)* Retirer `configuration` de la nav (déjà un redirect) et l'onglet `rules` (doublon `worktime`) → P2.

### P1 — Pages de composition (fusions à fort gain)
- **Documents** (documents+contract+signature → 1 espace à onglets) : *bénéfice* « faire signer » 4→1 page ; *dépendances* modules documents/contract/signature ; *risque* moyen (composition, double-headers) ; *tests* gating par onglet, permissions, routes legacy redirigées, Playwright parcours signature.
- **Temps de travail** (planning+pointage → 1 espace) : *bénéfice* prévu/réel réunis ; *risque* moyen-élevé (pages lourdes 2390+1449 l.) ; *migration* progressive (onglets wrappant les vues existantes) ; *tests* planning/pointage/timesheets non-régression.
- **Paie & déclarations** (payroll+dsn) : *bénéfice* cycle→déclaration continu ; *risque* moyen ; *tests* workflow paie+DSN, gateway sandbox non présentée comme validée.

### P2 — Rationalisations secondaires
- Hub `/company/workforce` cartes-modules → landing orientée tâches ou suppression au profit du dashboard (dédoublonner J).
- Paramètres RH : ne garder que la config réelle (retirer les onglets « lanceur »).
- Rapatrier **lieux** du planning vers Organisation (H).

### P3 — Perfectionnement ergonomique
- Vue unifiée « Personne » reliant User/Membership/Employee depuis la fiche (K) : lien direct fiche ↔ compte/accès ↔ rôle.
- Densité des pages (filtres, tableaux → vues utiles), dark/responsive.

---

## PÉRIMÈTRE & GARDE-FOUS
DEV uniquement. Modules backend **non fusionnés**. Permissions/ModuleGate/Market/tenant/manager-scope/confidentialité salariale/ledger congés/moteur signature **inchangés**. Routes historiques préservées par redirect. Pas de Billing, main, PROD, reset, moteur crypto, mot de passe SSH compromis (incident ouvert).
