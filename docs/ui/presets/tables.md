# Presets UI — Tables

> Patterns de tables CRUD.
> Source : `resources/ui/presets/apps/`, `resources/ui/presets/pages/templates/tables/`

## Pattern standard CRUD List

```
VCard
├── VCardText (filtres: search + selects)
├── VDivider
├── VDataTableServer
│   ├── colonnes avec slots (#item.xxx)
│   ├── status chips
│   ├── action buttons (view, edit, delete)
│   └── TablePagination
└── Drawer de création (VNavigationDrawer)
```

## Tables disponibles dans Vuexy

| Table | Module | Fonctionnalités |
|-------|--------|----------------|
| UserList | User management | Search, filtres rôle/plan/statut, export, drawer création |
| ProductList | E-commerce | Search, filtres stock/catégorie/statut, tri |
| OrderList | E-commerce | Search, statut paiement/livraison |
| CustomerList | E-commerce | Search, drawer création |
| InvoiceList | Invoice | Widgets stats, filtres statut/date |
| ReviewList | E-commerce | Stats chart, filtres statut |
| ReferralList | E-commerce | Stats widgets |
| PermissionList | ACL | Search, dialog add/edit |
| AcademyCourseTable | Academy | Search, pagination serveur |
| LogisticsOverviewTable | Logistics | Pagination serveur |
| AnalyticsProjectTable | Dashboard | Search, pagination serveur |
| EcommerceInvoiceTable | Dashboard | Mini table dans dashboard |
| BillingHistoryTable | Account settings | Table simple |
| CustomerOrderTable | E-commerce | Commandes d'un client |
| UserInvoiceTable | User | Factures d'un user |

## Showcases
| Page | Contenu |
|------|---------|
| `pages/tables/simple-table.vue` | Basic, theme, density, fixed height/header |
| `pages/tables/data-table.vue` | Dense, slots, selection, expand, group, edit, pagination |

## Extraits

_Aucun pour l'instant._
