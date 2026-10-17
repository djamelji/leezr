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
