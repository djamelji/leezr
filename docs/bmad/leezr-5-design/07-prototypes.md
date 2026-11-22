# Leezr 5.0 — 07 · Prototypes visuels

> Prototypes **locaux, isolés, non connectés**.
> - Chaque page porte le bandeau « Prototype Leezr 5.0 — données fictives » et le chemin de l'écran actuel à comparer.
> - Toutes les pages sont `devOnly` : le garde du routeur (`guards.js`, `!import.meta.env.DEV`) les refuse dans un build de production. Elles y sont compilées mais **inaccessibles**, comme les laboratoires existants.
> - Elles n'ajoutent aucune entrée de menu, aucune API, aucune donnée.

## Accès

1. Lancer `pnpm dev` (Vite), puis ouvrir l'application locale (`https://leezr.test` ou le serveur de recette du clone).
2. Se connecter avec un compte du clone : un administrateur Platform pour les pages `/platform/design-lab/*`, un propriétaire Company pour `/company/design-lab/*`.

| Proposition | Adresse | Captures | À comparer avec |
|---|---|---|---|
| 1. Cockpit Platform (V35) | `/platform/design-lab/cockpit` | [desktop](captures/proto/platform-cockpit-desktop.jpg) · [mobile](captures/proto/platform-cockpit-mobile.jpg) · [sombre](captures/proto/platform-cockpit-desktop-dark.jpg) | [actuel](captures/current/shots/p-superadmin/platform_dashboard_overview.jpg) |
| 2. Espace de travail Company (V22) | `/company/design-lab/workspace` | [desktop](captures/proto/company-workspace-desktop.jpg) · [mobile](captures/proto/company-workspace-mobile.jpg) · [sombre](captures/proto/company-workspace-desktop-dark.jpg) | [actuel](captures/current/shots/c1-owner/home.jpg) |
| 3. Personnes | `/company/design-lab/people` (`?preview=1` ouvre la consultation rapide) | [liste](captures/proto/company-people-desktop.jpg) · [consultation rapide](captures/proto/company-people-quickview-desktop.jpg) · [mobile](captures/proto/company-people-mobile.jpg) | [actuel](captures/current/shots/c1-owner/company_people.jpg) |
| 4. Bibliothèque de widgets | `/platform/design-lab/widgets` | [desktop](captures/proto/platform-widgets-desktop.jpg) · [mobile](captures/proto/platform-widgets-mobile.jpg) | `/platform/presentation-lab` |
| 5. Composants partagés | `/platform/design-lab/components` | [desktop](captures/proto/platform-components-desktop.jpg) · [mobile](captures/proto/platform-components-mobile.jpg) | `/ui-lab` |

**Interactions démontrables :**
- cockpit : « Voir comme » Super admin ou Support masque les données financières ;
- espace Company : bascules Métier, Rôle et Nouvelle société ;
- Personnes : segments, recherche, vues liste ou cartes, tiroir ;
- widgets : 5 tailles réelles, états, tailles refusées ;
- composants : tiroir à étapes et modale de confirmation.

## Fichiers

| Fichier | Rôle |
|---|---|
| `resources/js/pages/platform/design-lab/{cockpit,widgets,components}.vue` | pages Platform (layout `platform`, `devOnly`) |
| `resources/js/pages/company/design-lab/{workspace,people}.vue` | pages Company (layout par défaut, `devOnly`) |
| `resources/js/components/lab/LzHero.vue`, `LzKpiTile.vue`, `LzWidget.vue`, `LzLivePanel.vue`, `LzTrendWidget.vue`, `LabBanner.vue`, `useCountUp.js` | composants expérimentaux (06 §9) |

**Réutilisation, sans duplication** : la taille réelle des widgets est mesurée par `useSurfacePresentation` et `resolvePresentationMode` (ADR-748/750), les cartes utilisent `.lzr-card*` (ADR-741), les champs `AppTextField` et `AppSelect`, les graphiques `VueApexCharts` (déjà enregistré).

## Honnêteté des données

- Toutes les valeurs sont **fictives**.
- Chaque bloc correspond à une source **existante** (02 §2, 03 §3, 04 §4), sauf trois cas signalés :
  - l'**historique** de santé Platform : nouvelle mesure ;
  - les rendez-vous **Services** : module scheduling absent, présenté comme « module proposé » ;
  - le **rapport mensuel** : à créer.
- Les boutons ne déclenchent rien.

## Captures

- Mode de prise : Chrome sans interface, 1440 × 900 (desktop) et 390 × 844 en densité ×2 (mobile), pages entières, thème clair, plus le thème sombre pour les deux accueils.
- Écrans actuels : `captures/current/shots/` (128 pages).
- Prototypes : `captures/proto/` (16 images).
