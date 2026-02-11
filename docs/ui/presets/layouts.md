# Presets UI — Layouts

> Layouts et composants de structure.
> Source : `resources/js/layouts/`, `resources/js/@layouts/`

## Layouts disponibles

| Layout | Fichier | Usage |
|--------|---------|-------|
| Default | `layouts/default.vue` | Pages avec nav (auto-switch V/H) |
| Blank | `layouts/blank.vue` | Auth, erreurs, pages sans chrome |
| Front | `layouts/front.vue` | Pages marketing/publiques |

## Composants navbar

| Composant | Description | Dépendances |
|-----------|-------------|-------------|
| NavSearchBar | Recherche globale Cmd+K avec suggestions | AppBarSearch, API search |
| NavbarThemeSwitcher | Toggle light/dark/system | ThemeSwitcher |
| NavBarNotifications | Cloche + dropdown notifications | Notifications |
| NavbarShortcuts | Grille raccourcis configurable | Shortcuts |
| UserProfile | Menu avatar + logout | Cookies userData |
| Footer | Copyright + liens | - |
| TheCustomizer | Drawer config thème complet | ConfigStore |

## Composants @layouts (NE PAS MODIFIER)

### Vertical Nav
- VerticalNavLayout, VerticalNav, VerticalNavGroup, VerticalNavLink, VerticalNavSectionTitle

### Horizontal Nav
- HorizontalNavLayout, HorizontalNav, HorizontalNavGroup, HorizontalNavLink, HorizontalNavPopper

### Helpers
- TransitionExpand (animation hauteur)
- VNodeRenderer (rendu JSX)

## Options de configuration

| Option | Valeurs | Défaut |
|--------|---------|--------|
| Content width | Fluid, Boxed | Boxed |
| Nav type | Vertical, Horizontal | Vertical |
| Navbar | Sticky, Static, Hidden | Sticky |
| Footer | Sticky, Static, Hidden | Static |
| Navbar blur | true/false | true |
| Vertical nav collapsed | true/false | false |
| Vertical nav semi-dark | true/false | false |
| Theme | light, dark, system | system |
| Skin | Default, Bordered | Default |
| RTL | true/false | false |

## Extraits

_Aucun pour l'instant._
