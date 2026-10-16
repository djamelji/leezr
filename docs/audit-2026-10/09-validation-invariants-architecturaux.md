# Livrable 9 — Validation de la vision produit & audit des invariants architecturaux

> Octobre 2026. Confrontation vision ↔ ADR ↔ code réel. Légende : **[CONFIRMÉ]** vérifié dans le code · **[HYPOTHÈSE]** plausible non prouvé · **[RECO]** recommandation. Sources citées (fichier:ligne, ADR).

## 1. Vision confirmée (principes cohérents avec l'architecture)

| Principe | Statut | Preuve |
|---|---|---|
| Plateforme modulaire multi-tenant | **[CONFIRMÉ]** | `BelongsToCompany` + `CompanyScope` + `SetCompanyContext` ; ~76 tables `company_id` |
| **1 Company = 1 JobDomain** | **[CONFIRMÉ — garanti techniquement]** | voir §2 (double verrou DB + colonne) |
| Composition par entitlement | **[CONFIRMÉ]** | `EntitlementResolver` 5 gates ; `minPlan` + `addon_pricing` câblés au billing |
| Nav dynamique manifest-driven | **[CONFIRMÉ]** | `NavBuilder` flatMap `capabilities.navItems`, filtré plan/jobdomain/perm/rôle |
| RBAC 2 scopes hermétiques appliqué serveur | **[CONFIRMÉ]** | `CompanyAccess` (4 abilities) + `EnsurePlatformPermission` + middleware sur routes |
| Onboarding orchestré automatique | **[CONFIRMÉ]** | `JobdomainGate::assignToCompany` (transaction : modules+champs+rôles+dashboard) |
| Personnalisation sans dev (EAV) | **[CONFIRMÉ]** | suite `Core/Fields/*` (Definition/Activation/Value/Resolver/Validation) |
| Billing SaaS automatisé | **[CONFIRMÉ]** | ~20 tâches `routes/console.php` ; webhooks idempotents + DLQ |
| IA documentaire | **[CONFIRMÉ]** | pipeline OCR+Vision, auto-remplissage/auto-rejet |

## 2. INVARIANT #1 — 1 Company = 1 JobDomain : GARANTI, pas supposé

**Verdict : l'invariant est doublement verrouillé dans le code.** Le cumul de JobDomains dans une Company est **impossible**.

1. **Contrainte d'unicité DB** — `database/migrations/2026_02_11_200002_create_company_jobdomain_table.php:12` :
   `$table->foreignId('company_id')->unique()...` → au plus **une** ligne pivot par company.
2. **Colonne source de vérité** (ADR-167a) — `companies.jobdomain_key` est une **colonne scalaire unique**. `JobdomainGate::assignToCompany` (`app/Core/Jobdomains/JobdomainGate.php:117-121`) fait `update(['jobdomain_key' => …])` + `jobdomains()->sync([$id])`. `sync()` **remplace** l'association, ne l'ajoute pas.
3. **Cohérence doctrinale** — P8 (`00-context.md:334`) : « 1 company = 1 jobdomain, pivot `company_jobdomain` avec `company_id UNIQUE` (ADR-025) ».

**Nuances [CONFIRMÉ]** :
- Le **pivot est en voie de suppression** (commenté « backward compat — will be removed in ADR-167b », `JobdomainGate.php:120`). La source de vérité est désormais la colonne. L'invariant tient dans les deux cas.
- **Réassignation possible** : `sync()` permet de **changer** le jobdomain d'une company (A→B). Ce n'est **pas** un cumul (l'invariant tient) mais une **migration de jobdomain** — sujet classé « edge case / reporté » (`00-context.md:306`). → **[RECO]** décider explicitement si la réassignation est autorisée en prod (risque : modules/données orphelins du jobdomain précédent).
- **Multi-company pour un même user [CONFIRMÉ]** : `Company::users()` = `belongsToMany(User,'memberships')` ; un user appartient à N companies ; le contexte actif est résolu par header `X-Company-Id` via `SetCompanyContext` (vérifie `isMemberOf`). Le modèle « une autre activité = une autre Company » est donc pleinement supporté.
- **Isolation par company [CONFIRMÉ]** : données (`company_id`), memberships, `CompanyRole`, `CompanyModule`, `Subscription`, `Invoice` sont tous `hasMany` depuis Company (`app/Core/Models/Company.php:74-106`).

**Aucune possibilité de créer une Company avec plusieurs JobDomains n'a été trouvée** (ni via pivot, ni via la colonne, ni via `assignToCompany`).

## 3. Personnalisation profonde

| Capacité | État | Preuve / limite |
|---|---|---|
| Champs personnalisés | **[CONFIRMÉ]** | EAV complet `Core/Fields/*`, gated par jobdomain (ADR-042), scope company/user |
| Documents & modèles | **[CONFIRMÉ]** | `Core/Documents/DocumentTemplate` + resolver + variables ; CRUD templates (Workforce) |
| Rôles | **[CONFIRMÉ]** | `app/Company/RBAC/CompanyRole` custom par company + bundles |
| Permissions | **[CONFIRMÉ]** | générées par manifeste (`PermissionCatalogBuilder`) ; appliquées serveur |
| Menus | **[CONFIRMÉ]** | `NavBuilder` dynamique selon modules actifs |
| Paramètres métier | **[PARTIEL]** | `MarketRuleSet` (datés) + presets jobdomain ; par-company limité |
| Workflows / automatisations | **[PARTIEL]** | moteur no-code réel (`AutomationRule`/`ConditionEvaluator`/`ActionExecutor`) **mais actions limitées à notif/webhook/log** |
| Tableaux de bord | **[CONFIRMÉ]** | clonage par jobdomain + par rôle (ADR-327/357) |
| Notifications | **[CONFIRMÉ infra]** | Dispatcher + préférences ; ⚠️ peu émises hors Core/Platform |
| Règles configurables | **[PARTIEL]** | `MarketRuleSet` + presets ; pas de moteur de règles métier généralisé par company |

**Limite structurelle** : la personnalisation est forte sur **champs/rôles/menus/documents** mais le **moteur de workflows est bridé** (3 actions) et il n'y a **pas de règles métier configurables par company** au-delà des champs et des presets. Pour la vision « adapter sans dev », il faudra enrichir les **actions de workflow** (créer tâche, envoyer email, changer statut, appeler IA) et exposer des **règles métier** déclaratives.

## 4. Automatisation native

| Mécanisme | État | Preuve |
|---|---|---|
| Événements métier / events | **[PARTIEL]** | events existants ; **pas d'event `CompanyCreated`** (extension difficile) |
| Jobs / Queues | **[CONFIRMÉ]** | 22 jobs `ShouldQueue` (billing/email/docs) + DLQ ; ⚠️ **Workforce 0 job (100% synchrone)** |
| Scheduler | **[CONFIRMÉ]** | ~20 tâches `withoutOverlapping` |
| Workflows no-code | **[PARTIEL]** | moteur réel, actions limitées |
| Notifications | **[PARTIEL]** | infra OK ; Workforce n'émet rien |
| Documents intelligents / IA | **[CONFIRMÉ]** | OCR+Vision, auto-remplissage/rejet/relances |
| Calculs | **[CONFIRMÉ]** | moteur de paie réel (brut→net, cotisations, DSN) |
| Contrôles de conformité | **[PARTIEL]** | crons documents ; bandeau conformité company **mort** (`index.vue:225`) |
| Automatisations **intermodules** | **[ENVISAGÉ]** | quasi inexistantes (modules peu connectés entre eux) |

**Duplication / manque** : pas de duplication d'infra (les modules réutilisent les Cores), mais **sous-utilisation** : Workforce ignore Notifications/Realtime/Automation déjà disponibles. **Exemples d'automatisation cible** : (livraison) livraison confirmée → facture client auto ; (garage) RDV passé → relance avis + rappel révision ; (RH) timesheet validé → paie calculée + bulletin généré (job) + employé notifié.

## 5. Simplicité radicale UX (PP1-PP6, ADR-567)

| Principe | État | Preuve d'écart |
|---|---|---|
| PP1 Automatiser vs saisir | **Enfreint** (Workforce) | jours de congé tapés, paie 3 étapes, centimes |
| PP2 Ne pas redemander | **Enfreint** | ID de run tapé |
| PP3 Réutiliser transverses | **Partiel** | Workforce sans notif/realtime |
| PP4 Réduire les clics | **Enfreint** | double-calcul paie, bouton « Modifier » = onglet |
| PP5 Produit unique | **Enfreint** | clés i18n brutes, self-service → admin |
| PP6 Valeur mesurable | **Enfreint** | coquilles facturées |

**Conséquences** : l'onboarding et la nav sont excellents (assemblage auto, menus dynamiques) ; les **formulaires et parcours Workforce** trahissent le CRUD brut. **Limite structurelle** : l'assistance (wizards, tooltips, empty-states pédagogiques) est rare (2 wizards sur ~187 pages).

## 6. Cohérence documentaire & architecturale — contradictions

| Sujet | Doc dit | Code dit | Contradiction |
|---|---|---|---|
| Core sans métier (P1) | `00-context.md:327`, ADR-562 | `app/Core/Workforce/` (paie/DSN FR), `if('FR')` `PayrollCalculationEngine.php:324` | **OUI** — dette P1 (ADR-562 la borne) |
| Multi-vertical | ADR-564, vision | 1 seul jobdomain `logistique` | **OUI** — non prouvé |
| Socle horizontal | ADR-563 | 0/6 modules | **OUI** — inexistant |
| Catalogue honnête | ADR-561#4, PP6 | fleet/tracking/analytics `is_sellable=true` vides | **OUI** |
| Réalignement exécuté | ADR-561→567 | seuls 568/569 appliqués | **OUI** — documenté ≠ fait |
| Suite de tests | mémoire « hang » | **2955 verts, 490s** | corrigé (perception ≠ réalité) |
| RBAC « retiré » | ancienne mémoire | RBAC actif complet | corrigé |
| Markets | FR seul (ancienne note) | FR **+ GB** en DB | mineur |

## 7. Décisions à formaliser (ADR futurs — NON créés ici)
- **[RECO ADR]** Réassignation de jobdomain : autorisée ? avec quelle gestion des modules/données orphelins ? (clarifie l'edge case `00-context.md:306`).
- **[RECO ADR]** Suppression effective du pivot `company_jobdomain` au profit de la colonne (finaliser ADR-167b).
- **[RECO ADR]** Enrichissement des actions de workflow (au-delà de notif/webhook/log) + règles métier configurables par company.
- **[RECO ADR]** Contrat d'observabilité uniforme : tout UseCase mutant émet audit + notification + event.
- **[RECO ADR]** Modèle « établissement » (multi-sites d'une même Company) — aujourd'hui [NON-DÉCIDÉ].

## Réponse à la question finale

**OUI, l'architecture actuelle permet de construire la plateforme visée — avec un invariant « 1 Company = 1 JobDomain » déjà solidement garanti — SANS reconstruire les fondations, SOUS TROIS RÉSERVES :**

1. **Le socle horizontal (ADR-563) doit être construit** (contacts/invoicing/…) : c'est une **extension** des fondations, pas une reconstruction. Les fondations (composition, entitlement, nav, RBAC, fields, billing) sont saines et prêtes à l'accueillir.
2. **La frontière Core doit être restaurée** (sortir le métier FR de `app/Core/Workforce/`) : refactor localisé, pas structurel.
3. **Le moteur d'automatisation/personnalisation doit être enrichi** (actions workflow, règles par company, observabilité uniforme) : extension, pas refonte.

**Les fondations ne sont PAS à refaire.** L'invariant produit central est garanti techniquement (double verrou DB + colonne). Les manques sont des **couches à ajouter** sur une base cohérente, pas des défauts de conception des couches basses. Le principal risque n'est pas architectural mais **d'exécution** : le réalignement ADR-561→567 est documenté mais pas réalisé — c'est une question de priorisation, pas de capacité de l'architecture.
