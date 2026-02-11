# Inventaire UI Vuexy - Projet Leezr

> Source : `resources/ui/presets/` (extrait de Vuexy Full v9.5.0)
> Presets extraits : 722 fichiers (.vue, .js, .jsx)
> Infrastructure sync : plugins (70), fake-api (54), images (375)
> Date : 2026-02-10

---

## Table des matières

1. [Vue d'ensemble](#1-vue-densemble)
2. [Pages (144+)](#2-pages)
3. [Views - Blocs UI réutilisables (526)](#3-views)
4. [Composants génériques (80+)](#4-composants)
5. [Layouts & Navigation](#5-layouts--navigation)
6. [Plugins & Fake API (45+ endpoints)](#6-plugins--fake-api)
7. [Système de styles](#7-système-de-styles)
8. [Assets images (290+)](#8-assets)
9. [Composants à forte valeur réutilisable](#9-composants-à-forte-valeur)
10. [Structure cible recommandée](#10-structure-cible)

---

## 1. Vue d'ensemble

| Catégorie | Quantité | Emplacement |
|-----------|----------|-------------|
| Pages complètes | 144+ | `resources/ui/presets/pages/templates/` |
| View components | 526 | `resources/ui/presets/` |
| Composants globaux | 80+ | `resources/js/components/` + `resources/js/@core/components/` |
| Dialogs | 20 | `resources/ui/presets/dialogs/` |
| Layouts | 4 types | `resources/js/layouts/` + `resources/js/@layouts/` |
| Plugins | 8 | `resources/js/plugins/` |
| Fake API handlers | 17 (45+ endpoints) | `resources/js/plugins/fake-api/handlers/` |
| Fichiers SCSS | 50+ | `resources/styles/` |
| Assets images | 290+ | `resources/images/` |

---

## 2. Pages

### 2.1 Dashboards (3)

| Page | Route | Description |
|------|-------|-------------|
| `dashboards/analytics.vue` | `/dashboards/analytics` | KPIs ventes, charts, support tracker, sources |
| `dashboards/crm.vue` | `/dashboards/crm` | Revenue, projets actifs, transactions, timeline |
| `dashboards/ecommerce.vue` | `/dashboards/ecommerce` | Stats ventes, produits populaires, commandes |

### 2.2 Apps (9 modules)

#### E-commerce (11 pages)
| Page | Type | Description |
|------|------|-------------|
| `apps/ecommerce/product/list` | CRUD List | Liste produits avec filtres stock/catégorie/statut |
| `apps/ecommerce/product/add` | Form | Création produit multi-section (info, media, variants, prix) |
| `apps/ecommerce/product/category-list` | CRUD List | Gestion catégories |
| `apps/ecommerce/order/list` | CRUD List | Commandes avec statut paiement/livraison |
| `apps/ecommerce/order/details/[id]` | Detail | Détail commande, timeline livraison |
| `apps/ecommerce/customer/list` | CRUD List | Clients avec drawer création |
| `apps/ecommerce/customer/details/[id]` | Detail (tabs) | Profil client, overview, sécurité, billing, notifs |
| `apps/ecommerce/manage-review` | CRUD List | Modération avis avec stats chart |
| `apps/ecommerce/referrals` | Dashboard | Programme parrainage avec stats et table |
| `apps/ecommerce/settings` | Settings (6 tabs) | Store, paiement, checkout, shipping, locations, notifs |

#### Invoice (4 pages)
| Page | Type | Description |
|------|------|-------------|
| `apps/invoice/list` | CRUD List | Factures avec widgets stats |
| `apps/invoice/add` | Form | Création facture avec lignes éditables |
| `apps/invoice/edit/[id]` | Form | Edition facture existante |
| `apps/invoice/preview/[id]` | Detail | Aperçu/impression facture |

#### User Management (2 pages)
| Page | Type | Description |
|------|------|-------------|
| `apps/user/list` | CRUD List | Liste utilisateurs, filtres rôle/plan/statut |
| `apps/user/view/[id]` | Detail (tabs) | Profil, compte, sécurité, billing, notifs, connexions |

#### Autres apps
| App | Pages | Description |
|-----|-------|-------------|
| Calendar | 1 | FullCalendar CRUD événements |
| Chat | 1 | Messagerie temps réel avec contacts |
| Email | 1 | Client email avec dossiers/labels |
| Kanban | 1 | Board drag-drop avec cards |
| Academy | 3 | Dashboard, mes cours, détail cours |
| Logistics | 2 | Dashboard stats + fleet tracking Mapbox |
| Permissions | 1 | CRUD permissions avec rôles |
| Roles | 1 | Cartes rôles + liste utilisateurs |

### 2.3 Pages utilitaires

#### Authentification (13 variantes)
| Pattern | Variantes | Layout |
|---------|-----------|--------|
| Login | v1 (simple), v2 (illustration) | blank |
| Register | v1, v2, multi-steps | blank |
| Forgot Password | v1, v2 | blank |
| Reset Password | v1, v2 | blank |
| Verify Email | v1, v2 | blank |
| Two Steps (2FA) | v1, v2 | blank |

#### Account & Profile
| Page | Description |
|------|-------------|
| `pages/account-settings/[tab]` | Compte, sécurité, billing, notifs, connexions |
| `pages/user-profile/[tab]` | Profil, équipes, projets, connexions |

#### Misc
| Page | Description |
|------|-------------|
| `pages/pricing` | Plans tarifaires |
| `pages/faq` | FAQ avec recherche |
| `pages/icons` | Galerie icônes |
| `pages/typography` | Showcase typographie |
| `pages/dialog-examples` | Showcase 20 dialogs |
| `pages/misc/coming-soon` | Page "bientôt disponible" |
| `pages/misc/under-maintenance` | Page maintenance |
| `[...error]` | Page 404 |
| `not-authorized` | Page 401 |
| `access-control` | Demo ACL/CASL |

### 2.4 Front Pages (6)
| Page | Description |
|------|-------------|
| `front-pages/landing-page` | Landing marketing complète |
| `front-pages/pricing` | Plans publics |
| `front-pages/checkout` | Checkout public |
| `front-pages/payment` | Paiement |
| `front-pages/help-center` | Centre d'aide |
| `front-pages/help-center/article/[title]` | Article d'aide |

### 2.5 Wizards (3)
| Page | Steps | Description |
|------|-------|-------------|
| `wizard-examples/checkout` | 4 | Panier → Adresse → Paiement → Confirmation |
| `wizard-examples/property-listing` | 5 | Détails → Propriété → Zone → Prix → Personnel |
| `wizard-examples/create-deal` | 4 | Type → Détails → Usage → Review |

### 2.6 Showcases (36 pages)
- **Components** (16) : Alert, Avatar, Badge, Button, Chip, Dialog, ExpansionPanel, List, Menu, Pagination, ProgressCircular, ProgressLinear, Snackbar, Tabs, Timeline, Tooltip
- **Forms** (18) : Textfield, Select, Checkbox, Radio, Textarea, FileInput, Autocomplete, Combobox, Switch, Slider, RangeSlider, Rating, CustomInput, DateTimePicker, Editors, FormLayouts, FormValidation, FormWizard (x2)
- **Tables** (2) : SimpleTable, DataTable
- **Charts** (2) : ApexChart (10 types), ChartJS (8 types)
- **Extensions** (2) : Swiper, Tour
- **Cards** (5) : Basic, Advance, Statistics, Widgets, Actions

---

## 3. Views

### 3.1 Distribution par domaine

| Domaine | Composants | Usage principal |
|---------|------------|----------------|
| Dashboard widgets | 44 | Analytics, CRM, E-commerce (standalone charts/stats) |
| Apps views | 67 | Academy, Calendar, Chat, E-commerce, Email, Invoice, Kanban, Logistics, Roles, User |
| Card presets | 45 | Basic (3), Statistics (14), Advanced (19), Widgets (10) |
| Demo/Showcase | 294 | Documentation composants (forms, UI elements) |
| Pages views | 93 | Account settings, profil, help center, typography, wizards |
| Front page sections | 10 | Hero, features, pricing, team, contact, FAQ, footer |
| Charts | 18 | ApexCharts (10), ChartJS (8) |

### 3.2 Dashboard Widgets (haute valeur)

#### Analytics (10)
`AnalyticsWebsiteAnalytics`, `AnalyticsAverageDailySales`, `AnalyticsSalesOverview`, `AnalyticsEarningReportsWeeklyOverview`, `AnalyticsSupportTracker`, `AnalyticsSalesByCountries`, `AnalyticsTotalEarning`, `AnalyticsMonthlyCampaignState`, `AnalyticsSourceVisits`, `AnalyticsProjectTable`

#### CRM (11)
`CrmOrderBarChart`, `CrmSalesAreaCharts`, `CrmRevenueGrowth`, `CrmEarningReportsYearlyOverview`, `CrmAnalyticsSales`, `CrmSalesByCountries`, `CrmProjectStatus`, `CrmActiveProject`, `CrmRecentTransactions`, `CrmActivityTimeline`, `CrmSessionsBarWithGapCharts`

#### E-commerce (11)
`EcommerceCongratulationsJohn`, `EcommerceStatistics`, `EcommerceTotalProfitLineCharts`, `EcommerceExpensesRadialBarCharts`, `EcommerceGeneratedLeads`, `EcommerceRevenueReport`, `EcommerceEarningReports`, `EcommercePopularProducts`, `EcommerceOrder`, `EcommerceTransactions`, `EcommerceInvoiceTable`

> **Note** : Tous les widgets dashboard sont **standalone** (pas de props, données hardcodées). Ils devront être adaptés pour accepter des props dynamiques.

### 3.3 Apps Views (composés, nécessitent parent)

| Module | Composants | Rôle |
|--------|------------|------|
| E-commerce Customer | 6 | BioPanel, OrderTable, AddressBilling, Notifications, Overview, Security |
| E-commerce Settings | 6 | StoreDetails, Payment, Checkout, Shipping, Locations, Notifications |
| E-commerce Main | 2 | AddCategoryDrawer, AddCustomerDrawer |
| User Management | 8 | AddNewUserDrawer, BioPanel, InvoiceTable, TabAccount, TabBillings, TabConnections, TabNotifications, TabSecurity |
| Invoice | 4 | AddPaymentDrawer, Editable, ProductEdit, SendInvoiceDrawer |
| Chat | 5 | ActiveChatProfile, Contact, LeftSidebar, ChatLog, UserProfile |
| Email | 3 | ComposeDialog, LeftSidebar, EmailView |
| Calendar | 1 | EventHandler |
| Kanban | 4 | Board, BoardEditDrawer, Card, Items |
| Logistics | 7 | CardStatistics, DeliveryExpectations, DeliveryPerformance, OrderByCountries, OverviewTable, ShipmentStatistics, VehicleOverview |
| Academy | 7 | AssignmentProgress, PopularInstructors, TopCourses, CourseTable, MyCourses, TopicInterested, UpcomingWebinar |
| Roles | 2 | RoleCards, UserList |

---

## 4. Composants

### 4.1 Atomiques (inputs, boutons, indicateurs)

| Composant | Emplacement | Description | Ne pas modifier |
|-----------|-------------|-------------|-----------------|
| `AppTextField` | `@core/components/app-form-elements/` | VTextField + label custom | Pattern de base |
| `AppSelect` | idem | VSelect + label custom | Pattern de base |
| `AppAutocomplete` | idem | VAutocomplete + label | Pattern de base |
| `AppCombobox` | idem | VCombobox + label | Pattern de base |
| `AppTextarea` | idem | VTextarea + label | Pattern de base |
| `AppDateTimePicker` | idem | FlatPickr + Vuetify + dark mode | Config FlatPickr |
| `DialogCloseBtn` | `@core/components/` | Bouton fermeture dialog | - |
| `IconBtn` | `@core/components/` | Bouton icône | - |
| `MoreBtn` | `@core/components/` | Menu "..." actions | - |
| `ScrollToTop` | `@core/components/` | Bouton retour en haut | - |
| `ErrorHeader` | `components/` | En-tête page erreur | - |
| `AppLoadingIndicator` | `components/` | Barre de chargement | - |
| `ThemeSwitcher` | `@core/components/` | Light/Dark/System | - |
| `I18n` | `@core/components/` | Sélecteur de langue | - |

### 4.2 Molécules (groupes, cards, contrôles composés)

| Composant | Description | Contexte d'utilisation |
|-----------|-------------|----------------------|
| `CustomCheckboxes` | Groupe checkboxes stylisés avec slot | Formulaires de sélection |
| `CustomCheckboxesWithIcon` | Idem + icônes | Sélection avec visuels |
| `CustomCheckboxesWithImage` | Idem + images | Sélection visuelle |
| `CustomRadios` | Groupe radios stylisés avec slot | Choix unique |
| `CustomRadiosWithIcon` | Idem + icônes | Choix avec visuels |
| `CustomRadiosWithImage` | Idem + images | Customizer, wizards |
| `CardStatisticsHorizontal` | Card stat horizontale (titre, valeur, icône) | Dashboards |
| `CardStatisticsVertical` | Card stat + mini chart ApexCharts | Dashboards |
| `CardStatisticsVerticalSimple` | Card stat sans chart | Dashboards légers |
| `TablePagination` | Pagination table + info "X to Y of Z" | Toutes les tables |
| `AppStepper` | Stepper multi-direction (H/V), icônes/numéros | Wizards |
| `TiptapEditor` | Éditeur rich text (bold, italic, align...) | Email, descriptions |
| `DropZone` | Upload fichier drag-drop + preview | Création produit |
| `AppBarSearch` | Recherche globale Ctrl+K | Navbar |
| `AppSearchHeader` | Banner recherche avec titre | Pages liste |
| `Shortcuts` | Menu grille raccourcis | Navbar |
| `Notifications` | Centre de notifications avec badge | Navbar |
| `AppDrawerHeaderSection` | Header drawer avec titre + close | Drawers |

### 4.3 Organismes (dialogs, panels complexes)

| Dialog | Usage | Props clés |
|--------|-------|------------|
| `ConfirmDialog` | Confirmation action (3 états) | `confirmationQuestion`, `confirmTitle/Msg`, `cancelTitle/Msg` |
| `UserInfoEditDialog` | Edition profil utilisateur | `userData` |
| `AddEditRoleDialog` | Matrice permissions rôle (9 types x 3 actions) | `rolePermissions` |
| `AddEditPermissionDialog` | Création/edition permission | `permissionName` |
| `CreateAppDialog` | Wizard 5 steps création app | - |
| `TwoFactorAuthDialog` | Choix méthode 2FA | `smsCode`, `authAppCode` |
| `AddAuthenticatorAppDialog` | Setup app 2FA + QR code | `authCode` |
| `EnableOneTimePasswordDialog` | Vérification SMS | `mobileNumber` |
| `CardAddEditDialog` | Ajout/edition carte bancaire | `cardDetails` |
| `AddEditAddressDialog` | Ajout/edition adresse livraison | `billingAddress` |
| `AddPaymentMethodDialog` | Liste méthodes paiement | - |
| `PaymentProvidersDialog` | Liste fournisseurs paiement | - |
| `PricingPlanDialog` | Modal plans tarifaires | - |
| `UserUpgradePlanDialog` | Upgrade abonnement | - |
| `ShareProjectDialog` | Partage projet + permissions | - |
| `ReferAndEarnDialog` | Programme parrainage | - |
| `TheCustomizer` | Drawer customisation thème complet | - |

---

## 5. Layouts & Navigation

### 5.1 Layouts disponibles

| Layout | Fichier | Usage |
|--------|---------|-------|
| **Default** | `layouts/default.vue` | Pages avec nav (switch auto vertical/horizontal) |
| **Blank** | `layouts/blank.vue` | Auth, erreurs, pages sans chrome |
| **Front** | `layouts/front.vue` | Pages marketing/publiques |
| **Content Height Fixed** | Via meta | Chat, Email, Fleet (hauteur fixe) |

### 5.2 Composants navbar

| Composant | Description |
|-----------|-------------|
| `NavSearchBar` | Recherche globale avec suggestions (Cmd+K) |
| `NavbarThemeSwitcher` | Toggle light/dark/system |
| `NavBarNotifications` | Cloche notifications avec dropdown |
| `NavbarShortcuts` | Grille raccourcis (Calendar, Invoice, Users, Roles, Dashboard, Settings) |
| `UserProfile` | Menu profil avec avatar, logout |
| `Footer` | Copyright + liens |

### 5.3 Système de navigation

**Structure d'un item de nav :**
```js
{
  title: string,           // Texte affiché
  icon: { icon: string },  // Icône Tabler
  to: string | object,     // Route (nom ou objet)
  href: string,            // Lien externe (alternatif à to)
  children: Item[],        // Sous-items (crée un groupe)
  heading: string,         // Titre de section (exclusif avec title)
  badgeContent: string,    // Badge (ex: "5")
  badgeClass: string,      // Classe CSS du badge
  disable: boolean,        // Désactivé
  action: string,          // CASL action
  subject: string,         // CASL subject
}
```

**Menu vertical complet :**
- Dashboards (5) : Analytics, CRM, Ecommerce, Academy, Logistics
- Front Pages (5) : Landing, Pricing, Payment, Checkout, Help Center
- Apps (section) : Ecommerce (10), Academy (3), Logistics (2), Email, Chat, Calendar, Kanban, Invoice (4), User (2), Roles & Permissions (2)
- Pages : User Profile, Account Settings, Pricing, FAQ, Misc (4), Auth (12 variantes), Wizards (3), Dialog Examples
- UI Elements : Typography, Icons, Cards (5), Components (16), Extensions (2)
- Forms & Tables : Form Elements (15), Form Layouts, Form Wizard (2), Validation, Tables (2)
- Charts : ApexChart, ChartJS
- Others : Access Control, Nav Levels demo, Disabled demo, Support, Docs

### 5.4 Features du système layout

- Switch automatique vertical ↔ horizontal selon config
- Responsive : bascule en overlay nav sur mobile (<1280px)
- CASL/ACL : items masqués selon permissions
- Persistance cookies : état collapse, type nav, thème
- RTL complet
- Mini mode : nav réduit aux icônes au hover
- Detection route active + expansion auto des groupes parents
- i18n : titres traduisibles

---

## 6. Plugins & Fake API

### 6.1 Plugins

| Plugin | Fichier | Description |
|--------|---------|-------------|
| **Router** | `plugins/1.router/` | Vue Router auto-routes + guards auth + CASL + redirects |
| **Pinia** | `plugins/2.pinia.js` | State management |
| **Vuetify** | `plugins/vuetify/` | Theme (light/dark), icons (Tabler+custom SVG), defaults composants |
| **i18n** | `plugins/i18n/` | Vue I18n, locales JSON, cookie persistence |
| **CASL** | `plugins/casl/` | @casl/ability, règles depuis cookies, `$ability` global |
| **Layouts** | `plugins/layouts.js` | Système layouts + styles |
| **Webfontloader** | `plugins/webfontloader.js` | Google Font: Public Sans |
| **Iconify** | `plugins/iconify/` | Tabler + MDI + Font Awesome en CSS |

### 6.2 Auth system

- **Login** : `POST /api/auth/login` → `accessToken` + `userData` + `userAbilityRules`
- **Users demo** : `admin@demo.com` / `admin` (role: admin) | `client@demo.com` / `client` (role: client)
- **Stockage** : Cookies (`accessToken`, `userData`, `userAbilityRules`)
- **Guards** : Redirect `/login` si non authentifié, `/not-authorized` si CASL refuse

### 6.3 Fake API - Endpoints complets

| Module | Endpoints | Modèle de données |
|--------|-----------|-------------------|
| **Auth** | POST login | email, password → token, userData, abilityRules |
| **Users** | GET list, GET :id, POST create, DELETE :id | fullName, company, role, username, email, currentPlan, status, billing |
| **Products** | GET list, DELETE :id | productName, brand, category, status, stock, price, qty, sku |
| **Orders** | GET list, GET :id, DELETE :id | customer, email, date, status, spent |
| **Customers** | GET list, GET :id | customer, country, email, order, totalSpent |
| **Reviews** | GET list, DELETE :id | product, reviewer, email, date, status |
| **Referrals** | GET list | user, referredId, earning, value, status |
| **Calendar** | GET, POST, PUT :id, DELETE :id | title, start, end, allDay, calendar type |
| **Chat** | GET contacts, GET :userId, POST :userId | messages, unseenMsgs, senderId, feedback |
| **Email** | GET (filter/label), POST update | from, subject, message, isRead, folder, labels |
| **Invoice** | GET list, GET :id, DELETE :id, GET clients | client, invoiceStatus, issuedDate, total, balance |
| **Kanban** | GET, CRUD boards, CRUD items, reorder | boards (title, itemsIds), items (title, labels, members, dueDate) |
| **Logistics** | GET vehicles | location, startCity, endCity, warnings, progress |
| **Permissions** | GET list | name, createdDate, assignedTo[] |
| **Academy** | GET courses, GET details | courseTitle, user, completedTasks, totalTasks, tags |
| **Profile** | GET (tab), GET header | Tabs: profile, connections, teams, projects |
| **App Bar Search** | GET search | Recherche cross-app |
| **Dashboard** | GET analytics/projects | name, leader, project, progress |

### 6.4 Composables & Utilities

| Fichier | Description |
|---------|-------------|
| `composables/useApi.js` | createFetch wrapper avec Bearer token auto |
| `utils/api.js` | ofetch instance avec auth header |
| `utils/constants.js` | `COOKIE_MAX_AGE_1_YEAR` |
| `utils/paginationMeta.js` | "Showing X to Y of Z entries" |
| `@core/composable/useCookie.js` | Cookie management (Nuxt-ported) |
| `@core/composable/useSkins.js` | Gestion skins (default/bordered) |
| `@core/composable/useGenerateImageVariant.js` | Image light/dark selon thème |
| `@core/composable/useResponsiveSidebar.js` | Sidebar responsive avec breakpoint |
| `@core/composable/createUrl.js` | URL builder |
| `@core/utils/validators.js` | Validateurs formulaires |
| `@core/utils/formatters.js` | Formateurs (dates, nombres) |
| `@core/utils/helpers.js` | Helpers divers |
| `@core/utils/colorConverter.js` | Conversion couleurs |
| `@core/utils/plugins.js` | Système enregistrement plugins |
| `@core/utils/vuetify.js` | Résolution thème + cookies |

---

## 7. Système de styles

### 7.1 Architecture SCSS (couches)

```
1. Base Layer       → @core/base/          (framework-agnostic)
2. Vuetify Layer    → @core/base/libs/vuetify/  (overrides Vuetify)
3. Template Layer   → @core/template/      (customisations Vuexy)
4. User Layer       → variables/_template.scss   (point d'entrée custom)
5. Component Layer  → 26 fichiers SCSS     (fine-tuning par composant)
6. Skin System      → skins/               (bordered, extensible)
7. Dark Mode        → CSS variables         (via body.v-theme--dark)
```

### 7.2 Couleurs thème

| Couleur | Hex | Usage |
|---------|-----|-------|
| Primary | `#7367F0` | Actions principales, liens |
| Secondary | `#808390` | Éléments secondaires |
| Success | `#28C76F` | Succès, validations |
| Info | `#00BAD1` | Informations |
| Warning | `#FF9F43` | Avertissements |
| Error | `#FF4C51` | Erreurs |

### 7.3 Typographie

- **Font** : Public Sans (300-700, regular+italic)
- **Tailles** : xs (0.6875rem) → 9xl (8rem)
- **Overrides** : H1-H6, body, subtitle, caption, button, overline

### 7.4 Spacing

Échelle type Tailwind : `0` (0) → `96` (24rem) par incréments de 0.25rem

### 7.5 Élévation/Ombres

- 24 niveaux d'élévation (Vuetify standard)
- Custom shadows : sm (2px), md (4px), lg (6px) avec opacité configurable
- Mixin `custom-elevation($color, $size)` pour ombres colorées

### 7.6 Skins

| Skin | Description |
|------|-------------|
| **Default** | Ombres, design standard |
| **Bordered** | Bordures au lieu d'ombres (cards, dialogs, menus, nav) |

### 7.7 Ce qu'il ne faut PAS modifier

- `@core/base/` : fondations du framework layout
- `@core/base/libs/vuetify/` : variables Vuetify de base
- `@core/base/placeholders/` : placeholders SCSS réutilisés partout
- Les 26 fichiers `@core/template/libs/vuetify/components/` : overrides composants Vuetify

---

## 8. Assets

| Catégorie | Quantité | Emplacement |
|-----------|----------|-------------|
| Avatars | 15 | `images/avatars/` |
| Banners | 42 | `images/banner/` |
| E-commerce produits | 57 | `images/ecommerce-images/` + `images/eCommerce/` |
| Front pages | 28 | `images/front-pages/` |
| Icônes (brands, pays, paiement) | 58 | `images/icons/` |
| Illustrations | 10 | `images/illustrations/` |
| Logos | 11 | `images/logos/` |
| Pages (auth, misc) | 40+ | `images/pages/` |
| SVG | 32 | `images/svg/` |
| Customizer | 8 | `images/customizer-icons/` |
| Cards (paiement) | 3 | `images/cards/` |

---

## 9. Composants à forte valeur réutilisable

### Tables réutilisables
- **`VDataTableServer`** + **`TablePagination`** : Pattern standard pour toutes les listes CRUD
- Pattern : search + filtres + tri + pagination serveur
- Exemples : UserList, ProductList, OrderList, InvoiceList, CustomerList, ReviewList, PermissionList

### Form builders
- **`AppStepper`** : Wizard multi-step (H/V) avec validation
- **Form elements wrapper** : AppTextField, AppSelect, AppAutocomplete, AppCombobox, AppTextarea, AppDateTimePicker
- **Custom inputs** : CustomCheckboxes/Radios (avec variantes Icon/Image)
- **`TiptapEditor`** : Éditeur rich text

### Modals standards
- **`ConfirmDialog`** : Confirmation générique 3 états
- **`DialogCloseBtn`** : Bouton fermeture réutilisable
- **`AppDrawerHeaderSection`** : Header drawer standard
- Pattern drawer : VNavigationDrawer + VForm + PerfectScrollbar (AddNewUserDrawer, CalendarEventHandler, etc.)

### Cards configurables
- **`CardStatisticsHorizontal`** : Stat card (titre, valeur, icône, couleur)
- **`CardStatisticsVertical`** : Stat card + mini chart
- **`CardStatisticsVerticalSimple`** : Stat card simple
- 45 presets de cards dans `views/pages/cards/`

### Layouts dashboard
- 3 dashboards complets (Analytics, CRM, E-commerce)
- 32 widgets standalone réutilisables (charts, stats, tables)
- Pattern : VRow/VCol grid responsive avec widgets indépendants

### Système auth complet
- Login/Register (2 variantes chacun)
- Forgot/Reset password (2 variantes)
- Verify email (2 variantes)
- Two-factor auth (2 variantes + multi-step)
- Guards navigation + CASL
- Token management (cookies)

### Composants Navbar
- `NavSearchBar` (Cmd+K global search)
- `Notifications` (badge, mark read, remove)
- `Shortcuts` (grille configurable)
- `UserProfile` (menu utilisateur)
- `ThemeSwitcher` (light/dark/system)
- `I18n` (sélecteur langue)

---

## 10. Structure cible recommandée

```
resources/js/
├── @core/                    # NE PAS TOUCHER - Framework Vuexy
│   ├── components/           # Composants atomiques/molécules
│   ├── composable/           # Composables core
│   ├── libs/                 # ChartJS, ApexCharts configs
│   ├── stores/               # Config store
│   └── utils/                # Validators, formatters, helpers
│
├── @layouts/                 # NE PAS TOUCHER - Système layouts
│   ├── components/           # Nav verticale/horizontale
│   ├── stores/               # Layout config store
│   └── plugins/              # CASL integration
│
├── components/               # Composants partagés projet
│   ├── dialogs/              # Toutes les modals réutilisables
│   ├── AppLoadingIndicator.vue
│   ├── AppPricing.vue
│   ├── AppSearchHeader.vue
│   └── ErrorHeader.vue
│
├── composables/              # Composables app (useApi, etc.)
│
├── layouts/                  # Wrappers de layout
│   ├── default.vue
│   ├── blank.vue
│   └── components/           # Navbar, Footer, Customizer
│
├── navigation/               # Configuration menus
│   ├── vertical/
│   └── horizontal/
│
├── pages/                    # Routes auto-générées
│   ├── dashboards/
│   ├── apps/
│   ├── pages/
│   └── ...
│
├── plugins/                  # Plugins Vue
│   ├── 1.router/
│   ├── 2.pinia.js
│   ├── vuetify/
│   ├── i18n/
│   ├── casl/
│   ├── fake-api/             # Mock API (dev uniquement)
│   ├── iconify/
│   └── layouts.js
│
├── utils/                    # Utilitaires app
│
└── views/                    # Building blocks UI par domaine
    ├── apps/                 # Composants vues apps
    ├── dashboards/           # Widgets dashboard
    ├── pages/                # Composants vues pages
    └── front-pages/          # Sections marketing
```

### Principes d'organisation

1. **`@core/` et `@layouts/`** = librairie interne, on ne modifie pas
2. **`components/`** = composants génériques réutilisables cross-app
3. **`views/`** = blocs UI spécifiques à un contexte (mais réutilisables dans ce contexte)
4. **`pages/`** = assemblage final des views en pages routées
5. **`plugins/fake-api/`** = référence pour les contrats API à reproduire côté Laravel

### Où trouver les presets par besoin

| Besoin | Emplacement |
|--------|-------------|
| Nouveau dashboard | `resources/ui/presets/dashboards/` |
| Module CRUD | `resources/ui/presets/apps/` + `resources/ui/presets/pages/templates/` |
| Page auth | `resources/ui/presets/auth/` |
| Dialog spécifique | `resources/ui/presets/dialogs/` |
| Widget stat | `resources/ui/presets/dashboards/` ou `resources/ui/presets/cards/` |
| Wizard multi-step | `resources/ui/presets/pages/templates/wizard-examples/` |
| API mock | `resources/js/plugins/fake-api/handlers/` |

---

> Ce document sert de **référence permanente**. Consultez-le avant de créer toute nouvelle UI pour vérifier si Vuexy fournit déjà un composant ou pattern adapté.
