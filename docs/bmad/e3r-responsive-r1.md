# E3-R / R1 — Audit responsive, contrat cible & inventaire des anomalies

> Statut : **R1 livré (audit — aucun changement de comportement)**. Build audité : `fed47bb` (DEV). Environnement de mesure : local `leezr.test` (code identique à DEV). ADR : **ADR-693**.

Objectif E3-R : faire d'une utilisation de Leezr sur téléphone une **vraie expérience de gestion mobile**, pas une réduction de l'interface desktop. R1 = audit + contrat responsive + patterns cibles + inventaire mesuré, **avant** toute correction (R2→R4).

Stack : Vue 3.5.22 · Vuetify **3.10.8** · Vuexy v9.5. Pas de mise à jour majeure aveugle envisagée.

---

## 1. Responsabilités (ne pas tout attribuer à Vuexy)

| Couche | Emplacement | Statut |
|---|---|---|
| **Vuexy template** | `resources/js/@core/**`, `resources/js/@layouts/**` (+ SCSS miroir `resources/styles/@core/**`) | **NE PAS forker**. Le layout (drawer/navbar/overlay) y est réimplémenté en HTML/CSS natif (pas `VNavigationDrawer`/`VAppBar`/`VMain`). |
| **Couche Leezr** | `resources/js/layouts/**`, `resources/js/components/**`, `resources/js/core/components/**`, `resources/styles/styles.scss`, `resources/styles/variables/_template.scss`, `themeConfig.js`, `resources/views/application.blade.php` | **Point d'action**. Mécanisme d'override officiel `_template.scss` (`@forward … with`) **présent mais inexploité**. |
| **Pages métier** | `resources/js/pages/**` | Adoptent les primitives Leezr ; aucune logique sectorielle. |

Conclusion : le responsive se corrige **par-dessus** Vuexy (couche Leezr + surcharges CSS globales + nouveaux composants App*), **sans** modifier `@core`/`@layouts`.

---

## 2. Inventaire des anomalies — MESURÉ (Playwright, `tests/e2e/e3r-anomaly-audit.spec.js`)

Mesures sur 9 pages × 8 largeurs (320/375/390/430/768/1024/1366/1920), owner, Company + portail + module non-Workforce.

| Page | Débordement horizontal max | Onglets tronqués | Cibles tactiles < 36px @390 |
|---|---|---|---|
| dashboard | — | — | 4 |
| **annuaire** (E3-A) | — | — | 13 (table compressée, densité compacte) |
| **fiche** | — | **OUI** | 0 |
| **time** | — | **OUI** | 4 |
| **leave** | **+10px @320** | **OUI** | 2 |
| **payroll** | **+55px @320** | **OUI** | 2 |
| **organization** | — | **OUI** | 8 |
| **portail** (`/workforce/me`) | **+139px @375 · +124 @390 · +84 @430** | — | 0 |
| members (Core, non-Workforce) | — | — | 4 |

**Anomalies qualifiées :**
- **A1 — Débordement du portail salarié (critique)** : la rangée d'actions d'en-tête (`div.d-flex.gap-2` avec bouton « Commencer la journée » en chargement) **ne wrappe pas** → scroll horizontal majeur sur téléphone (jusqu'à +139px). Cassure réelle.
- **A2 — Débordement tables/onglets @320** : `leave` (+10) et `payroll` (+55) débordent au très petit téléphone via `v-slide-group__content` (slider d'onglets) + `table` (VDataTable `text-no-wrap`).
- **A3 — Onglets secondaires tronqués** : `fiche, time, leave, payroll, organization` — VTabs horizontaux à libellés longs → défilement à flèches, libellés partiellement cachés, pas d'alternative mobile.
- **A4 — Tables = desktop compressé** : `text-no-wrap` + largeurs fixes sur ~50 VDataTable → scroll horizontal pur, aucun mode carte (le mode `mobile` natif Vuetify 3.10 n'est jamais activé). L'annuaire E3-A ne déborde pas mais reste une table dense.
- **A5 — App bar surchargée** : 6-7 widgets (langue, thème, apps, aide, notifications, avatar + hamburger) jamais masqués/regroupés en mobile (`NavbarGlobalWidgets.vue:34-48`).
- **A6 — Safe areas absentes** : `viewport-fit=cover` manquant (`application.blade.php:18`) ; `env(safe-area-inset-*)` = 0 occurrence. App bar sticky/footer/drawer n'intègrent pas l'encoche iPhone.
- **A7 — Padding contenu figé** : 24px à tous les breakpoints (`@layouts/styles/_placeholders.scss:7`) — gaspillage d'espace en xs.
- **A8 — Drawer mobile custom** : pas de focus-trap/`aria-modal`, scrim fragile (failsafe 10s `DefaultLayoutWithVerticalNav.vue:18-46`), scroll via PerfectScrollbar (momentum iOS sous-optimal).
- **A9 — Dialogues/drawers largeur fixe** : `max-width`/`width` (400/520/640/720) non conditionnés au breakpoint ; `VNavigationDrawer.touchless:true` (`defaults.js:188`) désactive le swipe mobile.
- **A10 — Cibles tactiles** : usages `density="compact"` dans toolbars/tables → boutons < 44px (heuristique : annuaire 13, organization 8 @390).

Rapport brut : `scratchpad/e3r-audit/anomalies.json`. Captures mobiles/tablette : `scratchpad/e3r-audit/<page>-{390,768}.png`.

---

## 3. Contrat responsive cible (par largeur, intégré à Vuetify)

Breakpoints Vuetify **par défaut** conservés (xs<600, sm 600, md 960, lg 1280, xl 1920 ; bascule nav drawer à 1279/1280). Comportements de référence :

| Plage | Vuetify | Contenu | Tables | Navigation | Dialogues |
|---|---|---|---|---|---|
| **320–359** très petit tel | xs | padding 12px, 1 col | **cartes** | drawer overlay ; onglets → sélecteur/scroll | plein écran |
| **360–430** tel standard | xs | padding 16px, 1 col | **cartes** | idem ; filtres en **bottom-sheet** | plein écran |
| **431–599** grand tel | xs | 16px, 1 col | **cartes** | idem | plein écran |
| **600–959** tablette | sm/md | 2 col, 20px | table **scroll maîtrisé** OU cartes selon densité | onglets compacts ; drawer overlay | plein écran ou large |
| **960–1279** petit desktop | md/lg | grille, 24px | **table** complète | drawer overlay (<1280), onglets classiques | large |
| **≥1280** desktop | lg/xl | boxed 1440, 24px | **table** complète | **drawer permanent**, onglets classiques | large |

Exigences transverses : portrait **et** paysage ; **safe areas** iPhone ; **clavier virtuel** (actions de validation accessibles clavier ouvert) ; **zoom 200%** ; textes longs/traductions (AR/RTL) ; navigation tactile (cibles **≥ 44px**) ; clavier & focus (a11y) ; **aucun scroll horizontal** sur les pages sans tableau métier justifié.

Principe : responsive **fondé sur le besoin du composant et de son conteneur**, pas une collection de media queries. Le choix carte vs table se justifie par la densité de données, pas par dogme.

---

## 4. Patterns & primitives cibles (natif Vuetify d'abord, pas de fork Vuexy)

Patterns déjà présents à réutiliser : `useResponsiveSidebar` (`@core/composable`), `useDashboardGrid` (`isMobile`), `AppBarSearch.vue:87` (`:fullscreen` conditionnel — **seul** exemple), `TablePagination.vue` (pagination adaptative), `register/index.vue:668` (`direction` stepper selon display).

| Primitive Leezr | Rôle | Base native | Priorité |
|---|---|---|---|
| **AppResponsiveDataView** | Table desktop / **cartes** mobile, conserve tri/pagination/recherche/sélection/actions | VDataTableServer + `useDisplay` (+ mode `mobile` natif) | **P1** |
| **AppFilterPanel** | Recherche visible + filtres avancés en **bottom-sheet** mobile (compteur + reset) | `VBottomSheet` + `useDisplay` | P2 |
| **AppAdaptiveDialog** | `:fullscreen` sous `smAndDown` + largeur drawer réactive + swipe réactivé | VDialog/VNavigationDrawer natif | P3 |
| **AppSectionNavigation** | Onglets desktop / sélecteur·scroll·bottom-nav mobile, **sync route** | VTabs / VBottomNavigation / VSelect | P4 |
| **AppPageHeader** / **AppResponsiveActions** | En-tête normalisé (titre + action primaire + overflow), **flex-wrap** mobile | flex + `useDisplay` | P5 |
| Tokens tactiles/espacement | hauteurs min ≥44px, padding responsive | SCSS Leezr (`styles.scss`) | P5 |

**Quick wins natifs (sans nouveau composant)** : conditionner `text-no-wrap` au desktop ; `viewport-fit=cover` + `env(safe-area-inset-*)` ; garde `overflow-x` globale sur `.layout-page-content` + `flex-wrap` sur les rangées d'actions (corrige A1) ; largeurs de drawer réactives ; `touchless` conditionnel ; padding contenu responsive.

---

## 5. Plan d'incréments (points de contrôle entre chacun)

- **R1 — Audit (CE DOCUMENT)** : audit layouts + contrat + patterns + inventaire mesuré. *Aucun code de comportement.*
- **R2 — Fondations** : couche Leezr — `viewport-fit=cover` + safe areas + padding responsive + garde overflow-x + **refonte app bar mobile** (hiérarchie + overflow « plus ») + améliorations drawer (focus/swipe dans la limite Leezr). Correction immédiate A1/A5/A6/A7. Recette multi-contexte (Company/Platform/Employee/non-Workforce).
- **R3 — Composants adaptatifs + correction réelle E3-A** : AppResponsiveDataView (annuaire cartes mobile), AppSectionNavigation (fiche/onglets), AppFilterPanel, AppAdaptiveDialog (création/accès/contrat), fix portail. Recherche/filtre/ouverture dossier/actions **réellement fonctionnels** mobile.
- **R4 — Tests transverses + artefact** : Playwright 320/375/390/430/768/1024/1366/1920, portrait/paysage, light/dark, débordement programmatique, interactions (menus/filtres/onglets/dialogues/formulaires), permission-gating ; non-régression Company/Platform/Employee/non-Workforce + Workforce sans Logistics ; artefact avant/après. **E3-B ne démarre qu'après R4 validé.**

## 6. Préparation E3-B (planning) — fondations seulement
Prévoir AppSectionNavigation + un pattern « vue jour/agenda » mobile vs « grille semaine » desktop via `useDisplay` (ne pas afficher une grille 7j×30 employés sur 390px). **Aucun métier E3-B implémenté en R1.**

## 7. Garde-fous
DEV only · origin/dev · CI par clé · SHA servi vérifié · pas de main/PROD/Billing · pas de moteur Payroll/Signature modifié · pas de contournement RBAC · pas de donnée réelle · SSH compromis non utilisé · isolation tenant/permissions/modules inchangées · les 2 échecs date-dépendants `WorkforceTimesheetRulesTest` restent **comptés comme échecs** (jamais présentés verts).
