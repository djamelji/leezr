# LEEZR — Vision Produit & Description Complète de la Plateforme

> Document de référence autonome. Destiné à toute personne rejoignant le projet sans contexte préalable.
> Rédigé le 16 octobre 2026, posture fondateur/CEO/CTO/CPO. Fondé sur `docs/bmad/00→03`, les ADR (notamment **ADR-561→567**), le code vérifié et l'audit 360° d'octobre 2026 (`docs/audit-2026-10/`).
>
> **Légende de maturité utilisée partout :** **[EXISTANT]** code vérifié en place · **[PARTIEL]** commencé, incomplet · **[PRÉVU]** décidé en ADR, non codé · **[ENVISAGÉ]** évoqué, sans ADR · **[NON-DÉCIDÉ]** ouvert.
> Chaque affirmation majeure est rattachée à une source (ADR, fichier, route, comportement vérifié). Aucune idée n'est présentée comme une fonctionnalité officielle sans preuve.

---

## 1. L'ADN DE LEEZR

### Qu'est-ce que Leezr ?
Leezr est **une plateforme SaaS B2B modulaire, multi-tenant, multi-vertical et multi-pays** qui **assemble automatiquement le logiciel de gestion de chaque entreprise** à partir d'un socle commun et de modules métier activables. *(Source : `docs/bmad/00-context.md:10` ; ADR-561.)*

La formule **« système d'exploitation métier pour les entreprises »** est une **interprétation stratégique** (vision V2, non inscrite telle quelle dans le BMAD fondateur). La vision BMAD officielle parle de « socle SaaS flexible dont l'expérience s'adapte au métier » *(`01-business.md:9`)* et de « plateforme modulaire multi-tenant multi-vertical » *(`00-context.md:10`)*. L'analogie « OS métier » est fidèle à l'intention (un noyau + des applications composables) mais doit être présentée comme **ambition**, pas comme état actuel.

### Pourquoi ce projet existe-t-il ? Quel problème ?
*(Source : `01-business.md:5-9`.)* Un large spectre d'entreprises (vendeurs indépendants, commerces, salons, restaurants, transporteurs…) ont besoin d'outils adaptés à leur métier, mais les solutions existantes sont **soit trop généralistes** (ERP paramétrables lourds), **soit trop verticales** (SaaS de niche impossibles à étendre). Leezr veut offrir **l'adaptation d'un logiciel vertical avec la mutualisation d'un socle horizontal**.

### À qui s'adresse-t-il ?
PME et TPE de métiers variés *(`01-business.md:7`)*. Le premier vertical de validation est la **logistique/transport** *(`00-context.md:28`)*. Trois types d'acteurs : l'**éditeur** (Platform), l'**entreprise cliente** (Company), les **collaborateurs** (Users/Employees).

### Proposition de valeur
« La plateforme qui assemble automatiquement votre logiciel métier » : à l'inscription, l'entreprise choisit son métier et obtient **un espace déjà composé** (modules, navigation, rôles, tableaux de bord) sans intégration technique. *(ADR-561 ; mécanisme [EXISTANT] via `JobdomainGate::assignToCompany`.)*

### Ce qui le différencie des SaaS traditionnels
- Un **seul socle** (auth, facturation, documents, IA, notifications…) réutilisé par **tous** les métiers, au lieu de le réécrire par produit.
- Une **composition déclarative** : un métier = un assemblage de modules, pas un fork de code *(ADR-564)*.
- Une **neutralité multi-vertical** : le cœur ne connaît aucun métier *(principe P1, `00-context.md:327`)*.

### Pourquoi une plateforme modulaire plutôt que plusieurs logiciels ?
Parce que 80 % des besoins sont **transverses** (gérer des personnes, des documents, de la facturation, des plannings). Réécrire ces briques par vertical = coût × nombre de verticaux. Les mutualiser dans un socle horizontal = coût marginal d'un nouveau vertical **quasi nul** *(intention ADR-563 ; aujourd'hui [PRÉVU], socle non construit)*.

### Ambition 3 / 5 / 10 ans *(ENVISAGÉ — projection stratégique, pas d'ADR chiffré)*
- **3 ans** : socle horizontal complet + 3-5 verticaux + 2-3 pays.
- **5 ans** : dizaines de modules, automatisations intermodules, IA transverse, marketplace interne.
- **10 ans** : référence de l'« OS métier » pour PME multi-pays, écosystème de verticaux.

### Ce que Leezr n'est PAS *(Source : `00-context.md:350-359`.)*
Ni un **ERP configurable** (chaque métier a ses modules, pas une vue générique filtrée), ni une **marketplace de modules tiers** (modules définis par l'éditeur), ni un **CMS**, ni un **no-code**, ni un **framework**. Et surtout : **Leezr n'est pas un logiciel RH** — Workforce n'est qu'**un module horizontal** parmi d'autres *(ADR-561)*. Ce n'est pas non plus un logiciel logistique : la logistique est le **premier vertical de validation**, pas le produit.

---

## 2. LA PHILOSOPHIE FONDAMENTALE — Core → Modules → JobDomains → Markets → Companies → Users

### Vue d'ensemble (diagramme textuel)
```
 PLATFORM (éditeur)  ── gouverne ──►  catalogue de Modules, Plans, Markets, Jobdomains
      │
      ▼
 CORE (invariant, aucun métier)  ── fournit ──►  auth, tenancy, RBAC, billing, documents, IA, notifications…
      │  consommé par
      ▼
 MODULES (tout le métier)  ── horizontaux (réutilisables) + verticaux (spécifiques)
      │  assemblés par
      ▼
 JOBDOMAIN (assembleur)  ── sélectionne modules/champs/rôles/nav pour un métier
      │  appliqué à
      ▼
 COMPANY (tenant)  ── 1 jobdomain, 1 market, des modules actifs, des membres
      │  habité par
      ▼
 USERS / EMPLOYEES  ── accès personnalisé selon rôle + permissions
```

### Rôle, responsabilités et limites de chaque couche

| Couche | Rôle | Porte de la logique métier ? | Source |
|---|---|---|---|
| **Core** | Socle invariant : auth, tenancy, modules registry, jobdomain registry, fields, documents, billing-SaaS, notifications, realtime, automation, audit, IA, framework Markets | **Non** (règle P1) | `00-context.md:38,327` ; ADR-562 |
| **Module** | Unité autonome de logique métier, activable par company ; déclare ses capabilities (nav, routes, permissions) | **Oui** | `00-context.md:39` ; ADR-021/022 |
| **Jobdomain** | Variante produit : **sélectionne et assemble** (modules, champs, rôles, nav, documents) ; **ne calcule jamais** (P3) | **Non** | `00-context.md:40,66` ; ADR-564 |
| **Market** | Paramètres pays : devise, TVA, locale, fuseau, statuts légaux, langues, règles datées | **Non** (données) | `app/Core/Markets/` ; ADR-562#6 |
| **Company** | Tenant : unité d'isolation des données ; possède 1 jobdomain, des modules actifs, des membres | **Non** (conteneur) | `00-context.md:41` ; trait `BelongsToCompany` |
| **User/Employee** | Identité ; accès filtré par rôle + permissions | **Non** | `memberships`, RBAC |

### Ce qui doit être universel / configurable / spécifique
- **Universel (Core)** : s'authentifier, isoler un tenant, facturer l'abonnement, stocker/générer un document, envoyer une notification, tracer un audit, appeler l'IA. *(EXISTANT)*
- **Configurable (Jobdomain + Company)** : quels modules, quels champs, quels rôles, quelle navigation. *(EXISTANT pour modules/champs ; PARTIEL pour rôles/nav générés)*
- **Spécifique métier (Module vertical)** : une expédition, un chantier, un rendez-vous médical. *(1 seul existant : logistique)*
- **Spécifique pays (Market)** : TVA, devise, identifiants légaux, format d'adresse, règles de paie. *(PARTIEL — voir §8)*
- **Propre à l'entreprise (Company)** : ses données, sa config, ses membres, ses champs custom. *(EXISTANT)*
- **Personnalisable par l'utilisateur** : thème clair/sombre, langue, préférences de notification. *(EXISTANT — `Core/Theme`, `Core/Notifications` préférences)*

### Exemples concrets de composition et réutilisation
- Un **transporteur** et un **garage** partagent tous deux : `contacts` (clients), `invoicing` (factures), `documents`, `workforce` (salariés). Ils diffèrent par leur module vertical (`shipments` vs `réparations`). → **le même code de facturation sert les deux** *(principe ADR-563 ; aujourd'hui [PRÉVU] car `contacts`/`invoicing` n'existent pas encore)*.
- **Aujourd'hui [EXISTANT]** : à l'assignation du jobdomain `logistique`, la company reçoit automatiquement `core.members`, `core.settings`, `core.documents`, `core.theme`, `logistics_shipments`, `core.documentation` *(vérifié en DB : `default_modules`)*.

### Comment éviter le monolithe ingérable
Trois garde-fous architecturaux : (1) **un module ne dépend d'aucun autre sauf via le Core** *(D2, `00-context.md:343`)* ; (2) **le Core ne connaît aucun métier** *(P1)* ; (3) **le jobdomain assemble sans calculer** *(P3)*. Tant que ces trois règles tiennent, ajouter un vertical n'augmente pas la complexité des autres.
**⚠️ État réel (audit oct. 2026)** : la règle P1 est **violée** — `app/Core/Workforce/` contient de la paie/DSN française *(ADR-562 la qualifie de dette assumée bornée ; LOT-7 de remédiation)*.

---

## 3. LE PARCOURS COMPLET D'UNE ENTREPRISE

> Distinction **[EXISTANT]** (ce qui marche) vs **[CIBLE]** (parcours visé). Source principale : `RegisterCompanyUseCase`, `JobdomainGate::assignToCompany`, `AuthController`, `OnboardingStatusController`, audit oct.

### Ce qui se passe réellement aujourd'hui — [EXISTANT]
```
1. Wizard d'inscription en 6 étapes
   (resources/js/pages/register/_RegisterStep{Plan,Industry,Company,Account,Addons,Summary}.vue)
   → choix PLAN + JOBDOMAIN(industry) + MARKET + addons + champs — tout est saisi ici
         │ POST /register
         ▼
2. RegisterCompanyUseCase (1 transaction atomique) :
   ├─ User + Company + Membership(owner)                               [EXISTANT]
   ├─ JobdomainGate::assignToCompany() :                               [EXISTANT]
   │    ├─ active les default_modules (+ dépendances transitives)
   │    ├─ active les champs (field presets)
   │    ├─ active les types de documents
   │    ├─ crée les rôles company + permissions, rattache l'owner
   │    ├─ clone le dashboard par défaut (global + par rôle)
   │    └─ capture un snapshot de preset
   ├─ Subscription (free / trial / payante)                           [EXISTANT]
   └─ Audit + funnel analytics                                        [EXISTANT]
         │ Auth::login automatique
         ▼
3. Boot SPA : nav + dashboard composés depuis les modules actifs       [EXISTANT]
   (NavBuilder filtre par plan/jobdomain/permission/rôle)
         ▼
4. Espace opérationnel immédiat — 0 clic bloquant                      [EXISTANT]
   (widget onboarding 6 étapes ; étapes « compte créé » / « module activé » déjà cochées)
```
**Verdict onboarding (audit)** : orchestration réelle, score ~8/10. L'assemblage est automatique et transactionnel.

### Mapping des 17 étapes demandées

| # | Étape | État | Détail / source |
|---|---|---|---|
| 1 | Découverte commerciale | [PARTIEL] | pages publiques `Public*Controller` (plans, estimation) ; marketing/site non audité |
| 2 | Inscription | [EXISTANT] | wizard 6 étapes + `RegisterCompanyUseCase` |
| 3 | Création de l'espace | [EXISTANT] | atomique, via jobdomain |
| 4 | Choix du pays (Market) | [PARTIEL] | market choisi au register ; **seuls FR/GB seedés**, paie FR-only |
| 5 | Choix du domaine (Jobdomain) | [PARTIEL] | choisi au register ; **1 seul jobdomain réel (`logistique`)** |
| 6 | Choix de l'abonnement | [EXISTANT] | plans 0/29/79 € (DB) |
| 7 | Activation des modules | [EXISTANT] | défaut via jobdomain + addons self-service (`/modules/enable`) |
| 8 | Génération rôles/permissions | [EXISTANT] | `JobdomainGate` crée rôles company + bundles |
| 9 | Composition menus/dashboards | [EXISTANT] | `NavBuilder` + clone dashboard |
| 10 | Invitation collaborateurs | [EXISTANT] | `MembershipController` ; étape onboarding optionnelle |
| 11 | Configuration initiale | [EXISTANT] | champs/profil ; snapshot preset |
| 12 | Première utilisation | [EXISTANT] | espace navigable immédiat |
| 13 | Automatisation quotidienne | [PARTIEL] | billing/documents automatisés ; Workforce manuel (audit) |
| 14 | Évolution de l'entreprise | [PARTIEL] | ajout de membres/modules OK |
| 15 | Ajout de modules | [EXISTANT] | catalogue self-service + facturation addon |
| 16 | Évolution de l'abonnement | [EXISTANT] | upgrade/downgrade billing |
| 17 | Nouveaux établissements (multi-sites) | [NON-DÉCIDÉ] | pas de modèle « établissement » vérifié |

### Ce que voit l'utilisateur vs ce que fait le système
- **L'utilisateur voit** : un wizard, puis un espace prêt (menus de son métier, dashboard peuplé).
- **Le système fait en arrière-plan** : résolution des dépendances de modules, création des rôles/permissions, clonage des dashboards, souscription, audit, funnel — **le tout en une transaction**. *(EXISTANT, `JobdomainGate`.)*
- **Manque [CIBLE]** : email de bienvenue systématique (absent hors trial), event `CompanyCreated` (absent → extension/nurturing difficile), création d'entreprise assistée côté back-office (absente). *(Audit oct., Livrable orchestration.)*

---

## 4. LES TROIS EXPÉRIENCES DE LEEZR

> Deux scopes **hermétiques** (Platform vs Company) + une sous-expérience (Employee) dans le module Workforce. *(Source : `00-context.md:74-122` ; ADR-020/031.)*

### PLATFORM — l'éditeur Leezr [EXISTANT, très mûr]
- **Identité** : table `platform_users` séparée, guard `auth:platform`, sessions indépendantes. Un PlatformUser n'a **jamais** de membership company.
- **Périmètre** : gouvernance SaaS — entreprises, plans, markets, modules, jobdomains, champs, rôles platform, billing SaaS (metrics/dunning/ledger), IA, alertes, automations, email, support, documentation.
- **Volume** : ~330 routes `routes/platform.php`, ~26 sous-modules `app/Modules/Platform/*`, ~119 pages Vue. Sécurité renforcée (`platform.permission` + `platform.2fa`).

### COMPANY — l'entreprise cliente [EXISTANT]
- **Identité** : `users` liés via `memberships` (rôles owner/admin/user) ; contexte résolu par header `X-Company-Id` + `SetCompanyContext` (vérifie l'appartenance réelle).
- **Périmètre** : configuration, membres, modules, documents, workflows, support, audit, notifications, facturation d'abonnement, + modules métier actifs (logistique, workforce…).
- **Volume** : ~275 routes `routes/company.php`.

### EMPLOYEE / USER — le collaborateur [PARTIEL]
- **Identité** : un `User` membre, avec une fiche `Employee` du module Workforce (fix IDOR ADR-465 : l'employé est résolu depuis `$request->user()`, jamais depuis un id client).
- **Périmètre réel** : **1 page** `workforce/me.vue` (pointage, contrat, soldes congés, bulletins, documents).
- **⚠️ Limite (audit)** : les CTA « demander un congé / voir paie / voir documents » **renvoient vers les écrans admin**, pas vers du self-service ; 9 clés i18n brutes affichées. → self-service à finir (catégorie C de la remédiation).

### Matrice de responsabilités (RACI simplifié)

| Capacité | Platform | Company (owner/admin) | Employee |
|---|---|---|---|
| Définir le catalogue (plans/modules/markets/jobdomains) | **A/R** | — | — |
| Activer un module pour sa company | Consulté | **A/R** | — |
| Gérer membres & rôles company | — | **A/R** | — |
| Saisir données métier (expéditions, contrats…) | — | **A/R** | Contribue (selon perm.) |
| Pointer / poser un congé / voir ses bulletins | — | Consulté | **A/R** [PARTIEL] |
| Facturation SaaS (payer l'abonnement Leezr) | **A** | **R** (paie) | — |

---

## 5. TOUS LES CORES DE LEEZR

> Inventaire **réel** (`app/Core/` vérifié). Aucun Core inventé. Légende d'état en fin de chaque ligne.

| Core | Raison d'être | Consommé par | État |
|---|---|---|---|
| **Auth** | Sanctum SPA cookie, 2FA, password lifecycle, résolution workspace | tout | [EXISTANT] |
| **Companies / Scopes / Traits** | Multi-tenancy : `BelongsToCompany` + `CompanyScope` global + `SetCompanyContext` | tous modèles tenant | [EXISTANT] ⚠️ scope no-op hors HTTP (audit SEC-04) |
| **RBAC / Policies** | Permissions company (`CompanyAccess`, 4 abilities) + platform (`PlatformPermissionCatalog`), catalogue généré par manifeste | tout | [EXISTANT] |
| **Modules** | Registry + ActivationEngine + EntitlementResolver (5 gates) + Pricing | composition | [EXISTANT] |
| **Jobdomains** | Registry + Gate (assembleur) + PresetResolver + MarketOverlay | onboarding | [EXISTANT] ⚠️ 1 seul jobdomain, UseCase de création incomplet (ADR-564) |
| **Plans** | Catalogue d'abonnements, niveaux, quotas | billing, entitlement | [EXISTANT] (0/29/79 €) |
| **Billing** | Facturation **SaaS** (du locataire par la plateforme) : invoices, wallet/ledger, proration, dunning, coupons, credit notes | platform/company billing | [EXISTANT, très mûr, ~50 classes] |
| **Markets** | Paramètres pays (devise, TVA, locale, statuts légaux, langues, `MarketRuleSet` daté) | billing (TVA), paie | [PARTIEL] FR+GB seedés, branché TVA/devise ; paie FR-only |
| **Fields** | Champs dynamiques EAV multi-scope, gated par jobdomain | company config | [EXISTANT] |
| **Documents** | Génération PDF, templates, vault, pipeline **IA** (OCR + Vision), lifecycle, compliance | workforce, company docs | [EXISTANT] — le plus « intelligent » |
| **Notifications** | Dispatcher, topics, préférences (polymorphe platform/company) | core/platform modules | [EXISTANT] ⚠️ Workforce n'en émet pas (audit) |
| **Realtime** | SSE sur Redis (predis), publishers, topics, `PublishesRealtimeEvents` | notifications, dashboards | [EXISTANT] ⚠️ Workforce ne publie pas |
| **Automation** | Moteur workflows no-code (triggers/conditions/actions), scheduler, jobs | company workflows | [EXISTANT] ⚠️ actions limitées (notif/webhook/log) |
| **Ai** | Gateway multi-provider (Anthropic/Ollama/Null), quotas, policies, logs | Documents (seul câblé) | [EXISTANT infra] / [PARTIEL usage] |
| **Audit** | AuditLogger + DiffEngine (company + platform logs) | tout | [EXISTANT] — très utilisé (58× dans Workforce) |
| **Security / Alerts** | Détection d'événements, alertes plateforme, escalade | platform | [EXISTANT] |
| **Email** | Hub transactionnel + IMAP/SMTP (inbox, templates, deliverability) | billing, support | [EXISTANT] |
| **Storage** | Quotas, service fichiers (route `/media`) | documents | [EXISTANT] ⚠️ `/media` public (audit SEC-06) |
| **Theme / Typography / Settings / Navigation / Usage / FeatureFlag / Registration / Support / Documentation / System / Events / Models** | Infra transverse (thème DB, nav manifest-driven, quotas d'usage, flags, funnel d'inscription, tickets, help center) | tout | [EXISTANT] |
| **Workforce** ⚠️ | **Métier RH/paie/DSN FR** — **ne devrait pas être dans Core** (viole P1/ADR-562) | module Workforce | [EXISTANT mais mal placé] |

**Mécanismes automatiques notables** : billing (~20 tâches planifiées : renew, dunning, reconcile, expire-trials, SEPA…), documents (auto-remind/auto-renew/check-expiration), alerts (evaluate 5 min / escalate 15 min), IA documentaire (analyse à l'upload). *(EXISTANT, `routes/console.php`.)*

---

## 6. TOUS LES MODULES

> `app/Modules/` réel : `Billing`, `Core`, `Dashboard`, `Infrastructure`, `Logistics`, `Payments`, `Platform`, `Workforce`. **Distinction clé** : `Modules/Core`, `Modules/Platform`, `Modules/Infrastructure` sont des **wrappers d'infrastructure** (exposition HTTP des Cores), pas des modules métier vendables. Les vrais **modules métier** sont **Logistics** et **Workforce**.

### Workforce (RH / paie / DSN) — [EXISTANT, riche, mais non vendable en l'état]
- **Problème** : gérer les salariés (fiches, contrats, pointage, congés, planning, paie, bulletins, DSN).
- **Utilisateurs** : RH, managers, employés (self-service partiel).
- **Données** : Employee (≠ User), EmploymentContract, CompensationPlan, TimeEntry, Timesheet, LeaveRequest/Ledger, Shift, PayrollRun/Line, DsnDeclaration, Department, JobRole.
- **Cores utilisés** : Documents (bulletins PDF), Audit (massif), RBAC (24 permissions), Storage. **N'utilise pas** : Notifications, Realtime, Automation *(manque, audit)*.
- **Activation** : modules `workforce` + `workforce_payroll/leave/planning/documents` (payants, `addon_pricing`). **Non rattaché à un jobdomain** (horizontal, universel).
- **Valeur** : gestion RH complète FR (moteur de paie réel : cotisations URSSAF, CSG/CRDS, RGDU, PAS, YTD, régularisation ; DSN NEODES).
- **État réel** : backend solide et testé ; **6 P0 produit intacts** (centimes exposés, i18n brutes, IDs tapés, self-service cassé, validation absente) → **non vendable sans finition** (audit). **100 % synchrone** (0 job queue → risque timeout sur paie en masse). **Métier logé dans `app/Core/Workforce/`** → dette P1.
- **Doctrine** : module **horizontal en STABILISATION** *(ADR-561)* — finir/polir, pas étendre.

### Logistics (vertical fondateur) — [PARTIEL]
- **`logistics_shipments`** [EXISTANT] : CRUD expéditions + workflow de statuts + assignation chauffeur. Pages `shipments/` + `my-deliveries/`. Vrai module de bout en bout (front+back+DB).
- **`logistics_fleet` / `logistics_tracking` / `logistics_analytics`** [COQUILLES] : manifestes vides (`navItems:[]`, `routeNames:[]`), **aucune page/route/contrôleur**, **mais `is_sellable=true` + pricing** → facturables sans surface (audit CAT-01, à retirer de la vente).

### Documents (service Core exposé) — [EXISTANT]
Pas un module vertical mais un **Core** (`Core/Documents`) exposé côté company (`documents/[tab].vue`) : vault, demandes, compliance, génération, **IA (OCR + Vision Anthropic, auto-remplissage d'échéance, auto-rejet, relances automatiques)**. C'est le **vrai différenciateur « waouh »** aujourd'hui.

### Modules horizontaux de l'ADR-563 — [PRÉVU, 0/6 codés]
`contacts` (référentiel universel personnes/organisations, **pas un CRM**), `invoicing` (facturation **client**, priorité #1), `catalog`, `scheduling`, `inventory`, `projects`. **Aucun n'existe** (vérifié : 0 dossier, 0 route, 0 page). C'est le **chantier structurant** qui rendra le multi-vertical économiquement viable.

### Matrice module × Cores consommés

| Module | Documents | Audit | RBAC | Notifications | Realtime | Automation | IA |
|---|---|---|---|---|---|---|---|
| Logistics (shipments) | — | ⚠️ peu | ✅ | ❌ | ❌ | ❌ | ❌ |
| Workforce | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| *(cible socle horizontal)* | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |

---

## 7. LES JOBDOMAINS : LA DIMENSION MULTI-MÉTIER

> **État réel : 1 seul jobdomain vivant (`logistique`).** La promesse multi-vertical est **architecturalement prévue** mais **non prouvée** (audit). Le `CreateJobdomainUseCase` ne génère aujourd'hui qu'une coquille (pas de rôles/archétypes/nav) *(ADR-564)*. Les tableaux ci-dessous sont donc **[CIBLE/ENVISAGÉ]**, illustrant la composition visée.

### Principe de composition [CIBLE]
Un jobdomain = **socle horizontal commun** + **1-2 modules verticaux fins** + **presets** (rôles, champs, documents, nav, dashboards). Ajouter un métier ne doit **jamais** réécrire les briques communes *(ADR-564)*.

### Exemples (composition cible)

| Vertical | Modules communs (horizontaux) | Module(s) spécifique(s) | Rôles types | Automatisations clés |
|---|---|---|---|---|
| **Livraison / Transport** | contacts, invoicing, documents, workforce, scheduling | shipments *(EXISTANT)*, tracking | Dispatcher, Chauffeur, RH | affectation tournées, preuve de livraison, relance doc véhicule |
| **Garage auto** | contacts, invoicing, catalog, scheduling, inventory, workforce | ordres de réparation | Mécanicien, Accueil, Gérant | devis→facture, rappel révision, stock pièces |
| **Restaurant** | contacts, scheduling, inventory, workforce(planning) | carte/menu, réservations | Serveur, Cuisine, Manager | planning équipe, appro stock, réservation |
| **BTP** | contacts, invoicing, projects, documents, workforce | chantiers/situations | Chef de chantier, Conducteur, RH | situations de travaux, sous-traitants, sécurité doc |
| **Agence immobilière** | contacts, scheduling, documents, invoicing | biens/mandats | Négociateur, Gérant | visites, mandats, transactions |
| **Cabinet comptable** | contacts, documents, invoicing, projects | missions/échéances | Collaborateur, Expert | échéances déclaratives, temps passé |
| **Industrie** | contacts, inventory, projects, workforce | production/OF | Opérateur, Qualité, Planif | ordres de fabrication, maintenance |
| **Commerce / Services** | contacts, catalog, invoicing, scheduling | (léger) | Vendeur, Gérant | devis/facture, RDV |

### Ce qui manque pour assembler un nouveau vertical « sans réécrire »
1. Le **socle horizontal** (ADR-563) — inexistant aujourd'hui.
2. Un **`CreateJobdomainUseCase` complet** (rôles/archétypes/documents/nav) — aujourd'hui coquille.
3. Le **découplage** des presets logistiques codés en dur dans `JobdomainRegistry`.
*(Tout cela est [PRÉVU] par ADR-563/564, catégorie C de la remédiation.)*

---

## 8. LES MARKETS : LA DIMENSION INTERNATIONALE

> **État réel vérifié : 2 markets en DB (FR, GB).** Le framework société/facturation/TVA est multi-pays **[PARTIEL — réel 7/10]** ; la **paie est FR-only par conception [non-opérationnel ailleurs]** ; le **pricing des plans est mono-devise [cassé]** (montant unique sans conversion). *(Audit Markets/JobDomains.)*

### Ce qu'un Market paramètre [EXISTANT, data-driven]
Devise, `vat_rate_bps`, locale, timezone, `dial_code`, statuts légaux, langues, `is_eu`, + règles métier datées (`MarketRuleSet`). Branché : TVA (`TaxResolver`), devise facture/wallet, règles de paie par `market_key`.

### Par pays (attendu vs réel)

| Dimension | France | Belgique / Suisse / Canada | Maroc |
|---|---|---|---|
| Société + TVA + facture SaaS | ✅ [EXISTANT] | ⚠️ ajoutable par seed [PARTIEL] | ⚠️ ajoutable par seed |
| Statuts juridiques | ✅ | ⚠️ à seeder | ⚠️ à seeder |
| Devise facture/wallet | ✅ | ✅ (EUR/CHF/CAD) | ✅ (MAD) |
| **Prix des plans dans la bonne devise** | ✅ | ❌ [cassé] mono-devise | ❌ |
| Langue interface | ✅ fr/en | ⚠️ nl/de manquants | ❌ ar ~96 % manquant |
| Identifiants légaux (SIRET/TVA…) | ✅ | ❌ à créer | ❌ à créer |
| **Paie / déclaratif social** | ✅ (DSN, URSSAF) | ❌ moteur à écrire | ❌ moteur à écrire |

### Séparation universel (Market) vs spécifique pays (Module par-market)
- **Market = données** (devise, TVA, langues, statuts). Un 2ᵉ pays s'ajoute par **seed** pour le volet SaaS/facturation. *(ADR-562#6 : toute spécificité pays passe par les données, jamais un `if('FR')`.)*
- **⚠️ Violation réelle** : `PayrollCalculationEngine.php:324` contient `if ($marketKey === 'FR' …)` et le sous-système DSN est franco-français — la paie par pays nécessitera des **modules par-market**, pas des données. *(Audit, ADR-562.)*

**Honnêteté** : les capacités internationales **au-delà du socle facturation FR/GB ne sont pas opérationnelles**. Ne pas les vendre comme telles.

---

## 9. L'INTELLIGENCE ET L'AUTOMATISATION

> Principe directeur (ADR-567, PP1) : **Leezr doit faire à la place de l'utilisateur, pas seulement permettre.** État : fort sur billing/documents, faible sur Workforce.

### Ce qui est réellement automatique [EXISTANT]
- **Billing** : renouvellement, relances (dunning), réconciliation, expiration de trials, prélèvements SEPA, récupération de webhooks — ~20 tâches planifiées, sans intervention.
- **Documents + IA** : à l'upload, OCR + Vision Anthropic lisent le document, **extraient et pré-remplissent la date d'expiration**, **rejettent un mauvais type**, puis **relancent automatiquement** avant échéance (crons auto-remind/renew/check-expiration).
- **Alertes plateforme** : détection automatique (factures en retard, paiements échoués, tickets non assignés) avec déduplication et auto-résolution.
- **Onboarding** : assemblage complet de l'espace à l'inscription.

### Ce qui reste manuel alors qu'il devrait être automatique [PARTIEL/manque — audit]
- Congés : nombre de jours **tapé** au lieu d'être calculé (dates − fériés/we).
- Bulletins / DSN : **ID de run tapé** au lieu d'un sélecteur.
- Paie : **3 étapes manuelles** au lieu d'un « générer la paie du mois ».
- Workforce : **aucune notification/alerte proactive** (congé approuvé, fin d'essai, document expirant) alors que les Cores existent.
- Prospects abandonnés : **détectés mais pas relancés**.
- Support : **aucune IA** (pas d'auto-assignation/réponse suggérée) alors que le Gateway IA est là.

### Scénario cible complet [CIBLE] — « la paie du mois »
```
Fin de mois →
  système détecte les timesheets validés → propose « Générer la paie de mars »
  → calcule brut→net (moteur existant) → détecte les anomalies (net négatif, taux hors bornes)
  → génère les bulletins PDF (job async) → notifie chaque employé (self-service)
  → prépare la DSN → signale les documents expirants → met à jour le dashboard RH
  L'utilisateur VALIDE, il ne SAISIT pas.
```
Aujourd'hui, 4 de ces 7 étapes sont manuelles. L'infrastructure (moteur de paie, IA, notifications, scheduler) **existe déjà** — c'est un **gisement d'automatisation à coût marginal faible**, pas un chantier from scratch. *(Audit intelligence.)*

---

## 10. LE MODÈLE ÉCONOMIQUE

> Distinction **mécanismes [EXISTANT]** vs **orientations [PRÉVU/ENVISAGÉ]**.

### Existant [EXISTANT, vérifié]
- **Plans** : `starter` 0 € / `pro` 29 € / `business` 79 € (mensuel ; annuel ×10), différenciés par **quotas** (membres, stockage) et `feature_labels`. Niveaux `level` 0/10/20.
- **Deux leviers de monétisation réels** : (a) **gating par plan** (`minPlan` sur un module) ; (b) **add-on facturé** (`addon_pricing` : flat / plan_flat / per-seat), activé en self-service (`/modules/enable` + `ModuleQuoteCalculator` + `AddonBillingListener` → subscription + facture).
- **Cycle SaaS complet** : trial → facture → Stripe/paiement → webhook signé → dunning → wallet/ledger. Automatisé par scheduler.
- **Markets** : TVA par pays branchée sur la facturation.

### Distinction cruciale (à ne jamais confondre)
- **Billing SaaS** = Leezr facture **le locataire** (abonnement). [EXISTANT]
- **Facturation client** = l'entreprise facture **ses propres clients** = module horizontal `invoicing`. **N'EXISTE PAS** [PRÉVU, ADR-563]. C'est le manque commercial n°1 pour 6 des 8 verticaux.

### Orientations stratégiques [PRÉVU/ENVISAGÉ, ADR-564]
- **Repricing par capacité métier** (et non par quotas seuls) ; tiers hauts réels.
- **Multi-devise réelle** (aujourd'hui cassé : montant unique affiché quelle que soit la devise).
- Marketplace interne de modules [ENVISAGÉ].

### ⚠️ Intégrité commerciale (audit)
3 modules (`fleet`/`tracking`/`analytics`) sont **facturables mais vides** → à retirer de la vente (on ne facture pas du vide). *(CAT-01.)*

---

## 11. L'EXPÉRIENCE UTILISATEUR IDÉALE (ADR-567)

### Les 6 principes produit (PP1-PP6) *(Source : ADR-567.)*
1. **PP1** Automatiser plutôt que faire saisir.
2. **PP2** Ne jamais redemander une info déjà connue.
3. **PP3** Réutiliser systématiquement les services transverses (IA, Documents, Notifications, Workflows, Audit).
4. **PP4** Réduire au maximum le nombre de clics.
5. **PP5** Donner l'impression d'un **produit unique**, jamais d'une juxtaposition de modules.
6. **PP6** Chaque fonctionnalité crée une **valeur métier mesurable**.

**État (audit)** : socle UI très cohérent (Vuexy discipliné, ~90 %), mais **PP1/PP2/PP5 enfreints dans Workforce** (saisies manuelles, clés i18n brutes, self-service qui ouvre l'admin). L'expérience passe d'un module « magique » (Documents+IA) à des modules « formulaire ».

### Journée type [CIBLE]
- **Dirigeant** : ouvre le dashboard → voit effectif, coût salarial, trésorerie, alertes de conformité (docs expirants), tâches en attente. Ne saisit rien ; il **décide**.
- **Manager** : valide les congés (avec solde affiché), approuve les feuilles de temps, ajuste le planning par glisser-déposer, reçoit une alerte si conflit.
- **Employé** : pointe en un clic depuis la navbar, pose un congé (jours **calculés** automatiquement), consulte ses bulletins, reçoit ses notifications. Tout depuis **son** espace, sans voir l'admin.

*(Aujourd'hui : dashboard dirigeant réel mais sans coût salarial ni conformité branchés ; validation congé sans solde ; employé renvoyé vers l'admin. → écarts documentés, catégorie B/C.)*

---

## 12. L'ARCHITECTURE TECHNIQUE

> Choix **documentés** (ADR) vs **déductions** (signalées).

| Brique | Choix | Pourquoi (documenté / déduit) | Source |
|---|---|---|---|
| Backend | **Laravel 12 / PHP 8.3** | écosystème mûr, Eloquent, queues, scheduler | stack `00-context.md:367` |
| Frontend | **Vue 3.5 + Vuetify 3.10 + Vuexy** | UI = stock fini Vuexy, interdiction d'inventer (P5) | ADR-002, `06-ui-policy` |
| State | **Pinia 3** | stores par domaine | conventions |
| Auth | **Sanctum SPA cookie** | SPA first-party, CSRF, pas de token en localStorage | ADR-019 |
| Multi-tenant | **DB partagée + `company_id` + scope global** | jamais de DB séparée (coût/ops) | ADR-008/432 |
| RBAC | **2 scopes hermétiques + permissions manifest-driven** | séparation éditeur/client stricte | ADR-020/031/132 |
| Modules | **manifestes + registry + entitlement 5 gates** | composition déclarative | ADR-021/022 |
| Nav | **`NavBuilder` dynamique** (filtré plan/jobdomain/perm/rôle) | menus composés, pas codés | `Core/Navigation` |
| Realtime | **SSE sur Redis (predis)** | EventSource simple, predis car phpredis absent du VPS | ADR-125/126/431 |
| Jobs/Queues | **queue DB + workers self-healing (cron)** | résilience sans supervisor | ADR-429/430 |
| Webhooks | **signés + dédup + dead-letter** | idempotence paiement | ADR-137/222/228 |
| Paiements | **`PaymentGatewayManager` + adapters** (Stripe/Internal/Null) | le front ne construit jamais d'URL provider | ADR-336 |
| IA | **Gateway multi-provider** (Anthropic/Ollama/Null) + quotas/policies | neutralité fournisseur | `Core/Ai` |
| Déploiement | **CI build → artifact → rsync → symlink atomique** | pas de build sur VPS | ADR-018/081 ; ⚠️ script actif = `deploy_release.sh` (migrate:fresh) |

**Qualité backend (audit)** : webhooks idempotents exemplaires, activation de modules transactionnelle, idempotence documents (clé unique DB), **pas de N+1** sur l'échantillon, **suite de tests verte (2955 passés, 490 s)**. Points à durcir : suite non parallélisée (paratest inutilisé), Workforce 100 % synchrone, atomicité de quelques upserts Platform.

---

## 13. CE QUE LEEZR EST AUJOURD'HUI vs CE QU'IL DOIT DEVENIR

| Domaine | Vision cible | Implémentation actuelle | Manques | Prochaine évolution |
|---|---|---|---|---|
| **Socle Core** | invariant, non-métier | **[EXISTANT]** riche et mûr | métier RH logé dans Core | sortir Workforce du Core (LOT-7) |
| **Composition** | Market×Jobdomain×Plan×Module | **[EXISTANT]** moteur réel 5 gates | générateur jobdomain incomplet | compléter `CreateJobdomainUseCase` |
| **Modules horizontaux** | contacts/invoicing/catalog/scheduling/inventory/projects | **[PRÉVU]** 0/6 | tout | construire `contacts`→`invoicing` |
| **Modules verticaux** | plusieurs métiers | **[PARTIEL]** 1 (logistics_shipments) | fleet/tracking/analytics vides | 2ᵉ vertical léger (preuve) |
| **Workforce** | module HR horizontal vendable | **[EXISTANT]** backend fort | 6 P0 produit, synchrone, i18n | stabilisation (finir, pas étendre) |
| **Markets** | multi-pays réel | **[PARTIEL]** FR/GB socle | paie FR-only, pricing mono-devise | 2ᵉ market SaaS (sans paie) |
| **Intelligence** | faire à la place de l'utilisateur | **[PARTIEL]** billing/docs auto | Workforce manuel, IA 1 module | copilote IA + automatisations WF |
| **Billing SaaS** | cycle complet automatisé | **[EXISTANT]** très mûr | — | repricing par capacité |
| **Facturation client** | module invoicing | **[PRÉVU]** inexistant | tout | priorité #1 du socle |
| **UX (PP1-PP6)** | produit unique, automatisé | **[PARTIEL]** chrome cohérent | PP1/PP2/PP5 enfreints WF | finitions + assistance |
| **Sécurité** | prod durcie | **[PARTIEL]** multi-tenant HTTP solide | secret committé, admin public, scope no-op | bloc A de remédiation |
| **Infra/CI** | déploiement sûr multi-env | **[PARTIEL]** CI fonctionnel | migrate:fresh tous env, SSL dev expiré | séparation d'env, garde-fous |
| **i18n** | FR/EN/AR/ES | **[PARTIEL]** FR/EN ok | AR ~96 % manquant, ES absent | compléter + RTL |

**Synthèse honnête** : Leezr = **socle de plateforme excellent + 1 vertical mince + 1 module RH riche mais non fini + 0 socle horizontal**. L'infrastructure tient la promesse ; le **contenu métier composable** reste à construire. Le réalignement (ADR-561→567) est **documenté mais pas exécuté** (seuls 568/569 infra appliqués).

---

## 14. LA VISION À LONG TERME

### Leezr à maturité [ENVISAGÉ]
Des milliers d'entreprises, plusieurs pays, des dizaines de modules, des verticaux assemblés en jours (pas en mois), une IA transverse qui anticipe (relances, anomalies, suggestions), des automatisations intermodules (une expédition livrée → facture client générée → paiement suivi → compta mise à jour).

### Comment rester unifié malgré la diversité
Les **4 invariants à ne jamais sacrifier** :
1. **Le Core ne connaît aucun métier** (P1) — sinon le socle pourrit comme avec Workforce aujourd'hui.
2. **Un module ne dépend que du Core** (D2) — sinon effet domino.
3. **Le jobdomain assemble, ne calcule pas** (P3) — sinon des `if(métier)` partout.
4. **Spécificité pays = données, jamais de `if(pays)`** (ADR-562) — sinon impossible d'ouvrir un marché.

Plus : **l'UI reste un stock fini Vuexy** (P5) et **les 6 principes produit** (ADR-567) garantissent une expérience unique.

### Le fil rouge
La **largeur avant la profondeur** (ADR-565/566) : prouver qu'on sait servir **plusieurs métiers** avant d'approfondir un seul. La leçon de 2026 : avoir construit un module RH FR profond **avant** le socle horizontal et le 2ᵉ vertical était la dérive à corriger.

---

## QUESTION FINALE — Expliquer Leezr en 5 minutes

> « Imagine que chaque entreprise — un transporteur, un garage, un restaurant, un cabinet comptable — doive aujourd'hui assembler sa gestion avec 5 ou 6 logiciels séparés : un pour les clients, un pour la facturation, un pour les plannings, un pour les documents, un pour la paie. Ces logiciels ne se parlent pas, coûtent cher additionnés, et aucun n'est vraiment adapté à son métier.
>
> **Leezr, c'est un seul socle intelligent qui se transforme en le logiciel exact dont chaque métier a besoin.** L'entreprise s'inscrit, choisit son activité et son pays, et son espace est **assemblé automatiquement** : les bons modules, les bons rôles, les bons tableaux de bord, déjà prêts. En dessous, tout est mutualisé — l'authentification, la facturation, les documents avec lecture automatique par IA, les notifications — donc ajouter un nouveau métier ne coûte presque rien, et tout est cohérent et connecté.
>
> Une entreprise choisit Leezr plutôt qu'un assemblage de logiciels parce qu'au lieu de **brancher et payer dix outils qui ne communiquent pas**, elle a **un produit unique** qui connaît son métier, fait le travail administratif à sa place (calculs, relances, documents, conformité) et grandit avec elle — un module à la fois, un pays à la fois, sans jamais changer d'outil.
>
> Techniquement, le socle est déjà là et solide. Ce qu'il nous reste à construire, c'est l'étage métier réutilisable (clients, facturation, agenda, stock, projets) et à le prouver sur un deuxième métier. C'est exactement notre feuille de route. »

---

*Fin du document. Aucune modification applicative ni ADR effectuée. Ce document est une référence descriptive ; en cas de divergence avec le code, le code et les ADR font foi.*
