# Leezr 5.0 — Catalogue des widgets (L4, ADR-856)

Source : `DashboardWidgetRegistry` (bornes `layout()` relues dans le code au moment de la livraison), carte des composants `widgetComponentMap.js`, inventaire de la recette.
Bornes : `défaut w×h · min w×h → max w×h` sur la grille de 12 colonnes. Une taille hors bornes est **refusée avec son explication** (« ce widget s’arrête à w×h : il n’a rien de plus à montrer »).

## Règles communes

- **États** : chargement = squelette ; vide = état utile (texte métier, jamais « 0 » trompeur) ; erreur = « Données indisponibles » + **Réessayer** (re-résout le tableau de bord, même moteur) ; un lot en échec (réseau, 5xx) met chaque widget en erreur.
- **Droits** : catalogue filtré serveur (Platform par permission, Company par `forArchetype` : rôle, modules, JobDomain) ; la disposition enregistrée est filtrée à l’écriture et à la lecture : un widget interdit ne revient jamais d’une ancienne disposition.
- **Mobile** : chemin de priorité métier ; KPI appariés 2 par rangée ; panneaux en pleine largeur, recomposés en tuile KPI quand ils ont une présentation KPI, sinon hauteur lisible (`MOBILE_ROWS`).
- **Axes** : étiquettes de catégories allégées selon la largeur mesurée (`thinCategories`), jamais de chevauchement ; l’infobulle garde la date complète.

## Catalogue

| Clé | Public | Famille | Données | Permission / module | Bornes | Présentations | Interactions | État L4 |
|---|---|---|---|---|---|---|---|---|
| `billing.plan_badge` | company | Bandeau | client | billing.manage · core.billing | 12×2 · 6×2 → 12×3 | bandeau d’offre | — | à faire |
| `shipments.overview` | company | Donut composite | server | shipments.view · logistics_shipments | 8×4 · 4×3 → 12×5 | ≤ 200 px : tuile KPI ; moyen : donut + légende ; large : + chiffres du jour | lien vers la page métier, réessayer | refait |
| `onboarding.setup` | company | Hors grille | client | settings.view · core.dashboard | 12×2 · 12×2 → 12×2 | rendu hors grille (ADR-383) | — | à faire |
| `compliance.rate` | company | Jauge | client | documents.view · core.documents | 3×2 · 3×2 → 6×4 | jauge + ventilation sur 2 colonnes | —, réessayer | partiel |
| `compliance.pending` | company | KPI (compteur) | client | documents.view · core.documents | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `deliveries.my_today` | company | KPI (compteur) | server | shipments.view_own · logistics_shipments | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `drivers.active` | company | KPI (compteur) | server | shipments.assign · logistics_shipments | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `shipments.in_transit` | company | KPI (compteur) | server | shipments.view · logistics_shipments | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `shipments.late` | company | KPI (compteur) | server | shipments.view · logistics_shipments | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `shipments.today` | company | KPI (compteur) | server | shipments.view · logistics_shipments | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `shipments.unassigned` | company | KPI (compteur) | server | shipments.assign · logistics_shipments | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `workforce.leave_pending` | company | KPI (compteur) | server | — · workforce_leave | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `workforce.timesheet_pending` | company | KPI (compteur) | server | workforce.timesheet_approve · workforce | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `compliance.overdue` | company | KPI + ventilation | client | documents.view · core.documents | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `deliveries.completed_today` | company | KPI + ventilation | server | shipments.view_own · logistics_shipments | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `deliveries.next` | company | KPI + ventilation | server | shipments.view_own · logistics_shipments | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `logistics.fleet` | company | KPI + ventilation | server | shipments.view · logistics_shipments | 4×3 · 3×2 → 8×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `planning.shifts` | company | KPI + ventilation | server | workforce.planning_view · workforce | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `workforce.anomalies` | company | KPI + ventilation | server | — · workforce | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `workforce.headcount` | company | KPI + ventilation | server | workforce.view · workforce | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `workforce.clock_live` | company | KPI / donut | server | — · workforce | 3×2 · 3×2 → 6×3 | KPI en micro, donut dès qu’il y a de la présence | —, réessayer | conservé |
| `compliance.roles` | company | Liste | client | documents.view · core.documents | 6×4 · 4×3 → 12×8 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `compliance.types` | company | Liste | client | documents.view · core.documents | 6×4 · 4×3 → 12×8 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `documents.detail` | company | Panneau | client | documents.view · core.documents | 6×3 · 4×3 → 12×4 | lignes de types ajustées à la hauteur | liens du panneau, réessayer | refait |
| `workforce.hours_trend` | company | Tendance | server | — · workforce | 8×4 · 3×2 → 12×6 | ≤ 3×2 : tuile KPI (total) ; moyen : total, moyenne, meilleur jour + graphe ; étendu : + liste des meilleurs jours | lien vers la page métier, réessayer | refait |
| `ai.confidence` | platform | KPI (compteur) | server | view_ai · documents | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `ai.docs_analyzed` | platform | KPI (compteur) | server | view_ai · documents | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `billing.ar_outstanding` | platform | KPI (compteur) | server | view_billing · platform.billing | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `billing.mrr` | platform | KPI (compteur) | server | view_billing · platform.billing | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `billing.pending_dunning` | platform | KPI (compteur) | server | view_billing · platform.billing | 3×2 · 3×2 → 6×2 | micro → compact → standard (valeur, contexte, lien) | lien vers la page métier, réessayer | refait |
| `ai.auto_actions` | platform | KPI + ventilation | server | view_ai · documents | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `ai.detections` | platform | KPI + ventilation | server | view_ai · documents | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `billing.refund_ratio` | platform | KPI + ventilation | server | view_billing · platform.billing | 3×2 · 3×2 → 6×3 | compact → wide (ventilation en ligne) → expanded (grille de ventilation, lien) | lien vers la page métier, réessayer | refait |
| `billing.last_invoices` | platform | Liste | server | view_billing · platform.billing | 6×4 · 3×2 → 12×6 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `billing.last_payments` | platform | Liste | server | view_billing · platform.billing | 6×4 · 3×2 → 12×6 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `billing.last_refunds` | platform | Liste | server | view_billing · platform.billing | 6×4 · 3×2 → 12×6 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `billing.top_failure_reasons` | platform | Liste | server | view_billing · platform.billing | 4×4 · 3×2 → 8×6 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `platform.recent_activity` | platform | Liste | server | view_audit_logs · platform.activity | 8×4 · 3×3 → 12×8 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `platform.trials_ending` | platform | Liste | server | view_companies · platform.companies | 4×4 · 3×2 → 8×8 | lignes ajustées à la hauteur, « + N autres », 2 colonnes ≥ 760 px | lien vers la page métier, réessayer | refait |
| `billing.failed_payments_7d` | platform | Panneau cockpit (L1) | server | view_billing · platform.billing | 3×2 · 3×2 → 6×4 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `billing.revenue_mtd` | platform | Panneau cockpit (L1) | server | view_billing · platform.billing | 3×2 · 3×2 → 6×4 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.catalog_overview` | platform | Panneau cockpit (L1) | server | manage_modules · platform.modules | 4×4 · 3×2 → 12×6 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.commercial_overview` | platform | Panneau cockpit (L1) | server | view_billing · platform.billing | 8×5 · 3×2 → 12×8 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.companies_overview` | platform | Panneau cockpit (L1) | server | view_companies · platform.companies | 3×2 · 3×2 → 12×5 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.plan_distribution` | platform | Panneau cockpit (L1) | server | view_billing · platform.billing | 4×4 · 3×3 → 12×6 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.security_overview` | platform | Panneau cockpit (L1) | server | security.alerts.view · platform.security | 4×3 · 3×2 → 12×4 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.signups` | platform | Panneau cockpit (L1) | server | view_usage_monitoring · platform.dashboard | 3×2 · 3×2 → 12×6 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.support_overview` | platform | Panneau cockpit (L1) | server | manage_support · platform.support | 4×3 · 3×2 → 12×4 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `platform.system_health` | platform | Panneau cockpit (L1) | server | view_system_health · platform.dashboard | 4×5 · 4×3 → 12×8 | présentations ADR-855 (inchangées) | liens du panneau, réessayer | L1 |
| `billing.cashflow_trend_30d` | platform | Tendance | server | view_billing · platform.billing | 8×4 · 3×2 → 12×6 | ≤ 3×2 : tuile KPI (total) ; moyen : total, moyenne, meilleur jour + graphe ; étendu : + liste des meilleurs jours | lien vers la page métier, réessayer | refait |
| `billing.revenue_trend` | platform | Tendance | server | view_billing · platform.billing | 8×4 · 3×2 → 12×6 | ≤ 3×2 : tuile KPI (total) ; moyen : total, moyenne, meilleur jour + graphe ; étendu : + liste des meilleurs jours | lien vers la page métier, réessayer | refait |

## Restant à faire

- `onboarding.setup`, `billing.plan_badge` : non retravaillés (hors grille / bandeau).
- `compliance.rate` : jauge conservée, ventilation sur 2 colonnes ; pas de mode KPI dédié.
- `workforce.clock_live` : conservé (bornes alignées sur la grille : min 3 colonnes).
- Widgets résolus côté client (`compliance.*`, `documents.detail`) : leurs états vide/erreur ne sont pas simulables dans la matrice (données du store de conformité).
- Les KPI unitaires logistiques et `compliance.pending/overdue/types` restent au catalogue « Ajouter un widget », hors disposition recommandée (ADR-745).
