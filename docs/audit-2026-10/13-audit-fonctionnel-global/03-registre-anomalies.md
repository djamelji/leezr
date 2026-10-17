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

### ANO-003 — Modules sellable (Workforce/logistics) invendables en PROD — **P1** ✅
- **Périmètre** : Company Modules / entitlements — tous les add-ons `type:addon`.
- **Repro** : PROD `owner@leezr.test` (pro+logistique) → Workforce `is_entitled=false`, `display_state=contact_sales`, 0 module activé ; staging → activé (DevSeeder). « marche en dev, pas en prod ».
- **Attendu** : un add-on correctement configuré (vendable/inclus) activable par un client entitled, **identiquement** prod et staging (config catalogue = donnée produit).
- **Cause racine (démontrée)** : la config commerciale (`is_sellable` + `addon_pricing`) vivait **uniquement dans `DevSeeder`** (dev-only). `SystemSeeder` (prod) ne la posait pas → `EntitlementResolver=not_available` → `contact_sales` → invendable/inactivable en prod. La divergence de seeders **masquait** la panne (staging force-activait). `DependencyResolver::canActivate` (qui ignore l'entitlement) avait initialement masqué le vrai état.
- **Correction** : extraction vers `ModuleProductConfigSeeder` (idempotent, données produit) + appel dans `SystemSeeder` → prod cohérent. Workforce : `contact_sales` → `available`. Valeurs de prix **existantes** (non inventées) ; promotion prod = validation produit (commit local, non déployé).
- **Tests** : `WorkforceModuleActivationJourneyTest` (5).
- **Finalisation** : `ModuleProductConfigSeeder` rendu **non destructif** (initialise seulement si `addon_pricing` absent → n'écrase pas une config commerciale admin légitime) + idempotent.
- **Risque données** : aucun (données produit catalogue, pas tenant ; pas de modif financière).

### ANO-002 — `/platform/billing/coupons` : 2× `401` en console — **P2** 🔎
- **Périmètre / page** : Platform Billing — `/platform/billing/coupons`.
- **Repro** : E2E staging, `admin@leezr.com` → page rend mais 2 ressources `401` (parmi `/billing/coupons`, `/plans`, `/modules`).
- **Attendu** : aucune erreur console ; dropdowns plans/modules peuplés.
- **Observé** : 2× `401` ; **non reproduit en local** (20/20 vert).
- **Cause** : **hypothèse à confirmer** — ni 2FA ni permission (qui renvoient 403) ; probable flaky/parallélisme ou état session staging. **Non corrigé** (repro déterministe requise avant fix).
- **Test attendu** : une fois reproduit, test API ciblé sur les 3 endpoints sous session platform admin.

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
| Company suspendue affichée active | [NON] à vérifier | — |
| Module désactivé toujours accessible | [CODE] gated (ModuleGate/entitlements) ; [NON] en E2E | — |
| Bouton sans endpoint | [NON] balayage non exhaustif ; jobdomains OK | — |
| Fonctionnalité annoncée non implémentée | voir audits antérieurs (modules horizontaux) | ../02 |

> Les lignes [NON]/[CODE] indiquent explicitement les contrôles **non encore prouvés au navigateur** — à traiter dans les lots d'audit suivants.
