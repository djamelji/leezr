# Presets UI — Dashboards

> Widgets dashboard standalone.
> Source : `resources/ui/presets/dashboards/`

## Analytics (10 widgets)

| Widget | Visualisation | Description |
|--------|--------------|-------------|
| AnalyticsWebsiteAnalytics | Area chart | Trafic site avec stats |
| AnalyticsAverageDailySales | Bar chart | Moyenne ventes/jour |
| AnalyticsSalesOverview | Donut + stats | Vue globale ventes |
| AnalyticsEarningReportsWeeklyOverview | Bar chart | Revenus semaine |
| AnalyticsSupportTracker | Radial bar | Tickets support |
| AnalyticsSalesByCountries | List | Ventes par pays |
| AnalyticsTotalEarning | Line chart + list | Revenus totaux |
| AnalyticsMonthlyCampaignState | Bar chart | Campagnes mois |
| AnalyticsSourceVisits | Bar chart | Sources de visite |
| AnalyticsProjectTable | DataTable | Projets avec progression |

## CRM (11 widgets)

| Widget | Visualisation |
|--------|--------------|
| CrmOrderBarChart | Bar |
| CrmSalesAreaCharts | Area |
| CrmRevenueGrowth | Line |
| CrmEarningReportsYearlyOverview | Bar |
| CrmAnalyticsSales | Radial bar |
| CrmSalesByCountries | List |
| CrmProjectStatus | Line |
| CrmActiveProject | DataTable |
| CrmRecentTransactions | List |
| CrmActivityTimeline | Timeline |
| CrmSessionsBarWithGapCharts | Bar |

## E-commerce (11 widgets)

| Widget | Visualisation |
|--------|--------------|
| EcommerceCongratulationsJohn | Banner card |
| EcommerceStatistics | 4 stat cards |
| EcommerceTotalProfitLineCharts | Line |
| EcommerceExpensesRadialBarCharts | Radial |
| EcommerceGeneratedLeads | Donut |
| EcommerceRevenueReport | Bar + line |
| EcommerceEarningReports | Bar |
| EcommercePopularProducts | DataTable |
| EcommerceOrder | List |
| EcommerceTransactions | List |
| EcommerceInvoiceTable | DataTable |

## Note importante

Tous ces widgets sont **standalone** avec données hardcodées.
Pour les utiliser en production, il faudra :
1. Ajouter des props pour les données
2. Connecter aux stores Pinia / API

## Extraits

_Aucun pour l'instant._
