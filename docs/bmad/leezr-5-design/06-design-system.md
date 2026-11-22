# Leezr 5.0 — 06 · Langage visuel commun (Platform et Company)

> Prototype : `/platform/design-lab/components` — [desktop](captures/proto/platform-components-desktop.jpg) · [mobile](captures/proto/platform-components-mobile.jpg).
>
> **Principe.** Pas de second design system ni de nouveau framework, et aucune modification de `@core` ou `@layouts`. On **généralise ce qui existe** :
> - les jetons du thème Vuetify et Vuexy (`--v-theme-*`) ;
> - la couche « Leezr Premium » (`.lzr-card*`, `.lzr-kpi__value`, ADR-741), utilisée aujourd'hui par **2 fichiers** ;
> - les presets `resources/ui/presets/` : dashboards CRM, Analytics, eCommerce, Logistics et 55 cartes ;
> - les wrappers `App*`.

## 1. Page

- **En-tête** : fil d'Ariane (petit), **titre = objet courant** (change avec l'onglet), compteur éventuel (« Abonnements · 26 »), sous-titre d'une phrase, puis les actions à droite.
- **Pas de bandeau d'explication permanent.** L'aide passe par le sous-titre, une infobulle (`AppMetricHelp`, existant) ou le centre d'aide.
- **Onglets** : 5 au maximum. Au-delà, on groupe (« Suivre / Recouvrer / Contrôler ») ou on passe à une navigation de section verticale.
- **Largeur de lecture** : 60 à 70 caractères pour les textes.

## 2. Actions

| Niveau | Rendu Vuetify | Règle |
|---|---|---|
| Principale | `VBtn color="primary"` (pleine) | **une par écran ou par tiroir** |
| Secondaire | `variant="tonal"` | une ou deux au maximum |
| Tertiaire | `variant="text"` | navigation, exporter, annuler |
| Destructive | `variant="tonal" color="error"`, puis une confirmation | jamais au même niveau que « Configurer » |
| Reste | menu « ⋯ » (`MoreBtn`) | — |

Tout bouton-icône a un **libellé accessible** et une infobulle. Il y a 697 boutons-icônes muets aujourd'hui (01 §1).

## 3. Couleurs sémantiques (une couleur = un sens, partout)

| Jeton | Sens | Exemples |
|---|---|---|
| `success` | bon, terminé, actif | accès actif, livré, configuré |
| `info` | information, en attente **d'autrui** | invité, en attente de signature |
| `primary` | en cours **chez nous**, marque | en intégration, sélection |
| `warning` | action attendue | à valider, essai J-5 |
| `error` | bloquant ou en retard | paiement échoué, document expiré |
| `secondary` | inactif ou archivé | sans accès, archivé |

**Interdit** : une couleur codée en dur dans un composant métier.

**Exceptions documentées** : contrastes AA déjà décidés (`.lzr-link`, `.lzr-cta`, ADR-743/745/831) et le panneau sombre de supervision (une seule surface par page).

**Leçon du prototype en mode sombre.**
- Les libellés au centre des graphiques en anneau et le bouton blanc d'un bandeau de marque **doivent** prendre leur couleur du thème.
- Les deux ont échoué dans la première version et sont corrigés.
- Règle : toute couleur de graphique passe par `theme.current.value.colors` ; tout bouton sur bandeau de marque utilise la classe `lz-on-brand`.

## 4. Surfaces, rayons, ombres, espacements

| Élément | Règle |
|---|---|
| Cartes | `.lzr-card--flat` (conteneurs, tableaux) · `.lzr-card--raised` (widgets, KPI ; se soulève de 3 px au survol) · `.lzr-card--active` (sélection) |
| Rayons | 10 px contrôles · 12 px cartes · 16–20 px héros et panneaux |
| Espacements | échelle 4 / 8 / 12 / 16 / 24 / 32 ; gouttière de grille 24 px ; marge latérale mobile 16 px (existant, R2) |
| Densité | lignes de liste de 56 px, en-tête de widget de 56 px, tuile KPI d'au moins 150 px |
| Surface sombre | une seule par page (supervision) ; ombres plus profondes en thème sombre (existant) |

## 5. Typographie

- Police réglable dans Platform › Thème (`--lzr-font-family`, Public Sans par défaut) ; titres serrés (`letter-spacing: -.03em`), poids 700 pour les titres de page.
- **Accent éditorial** : prénom de l'accueil en italique à empattement (Georgia, police système, sans chargement), **seulement** dans le héros.
- Chiffres : `font-variant-numeric: tabular-nums` dans les tableaux et les agendas.

## 6. Formulaires, tiroirs, modales

- **Tiroir** = créer ou modifier un objet sans quitter la liste. Composition :
  - en-tête fixe (titre et étape) ;
  - progression ;
  - corps qui défile (`.wf-scroll-drawer`, existant) ;
  - pied fixe avec **une** action principale.
- **Modale** = confirmer une décision : icône, titre-question, conséquence en une phrase, « Annuler » et une action. Jamais de formulaire long.
- **Formulaire** : sections `AppFormSection` (existant), libellés au-dessus, aide sous le champ. Les champs demandés **viennent du poste**, pas d'un réglage local (05 §1).

## 7. États

| État | Règle |
|---|---|
| Vide | icône, phrase « ce qui manque », **action suivante** ; jamais une tuile « 0 » sans contexte |
| Chargement | squelette à la forme du contenu (`VSkeletonLoader`), même hauteur que l'état final |
| Erreur | message humain, référence technique en petit, « Réessayer » |
| Confirmation | notification brève, avec « Annuler » si l'action est réversible |
| Lecture seule | état d'accès existant (`AppAccessState`, M3) |

## 8. Mouvement

| Usage | Durée | Règle |
|---|---|---|
| Entrée des cartes | 450 ms, décalage de 60–70 ms | à l'affichage de la page seulement |
| Compteurs KPI | 900 ms | **une fois** ; les mises à jour suivantes sont instantanées (`useCountUp`) |
| Graphiques | 500 ms | `dynamicAnimation: false` : rien ne s'anime au rafraîchissement |
| Survol | 180–220 ms | soulèvement de 2–3 px, ombre |
| Listes filtrées | 200–250 ms | `TransitionGroup` (entrée et sortie) |
| Statut en direct | pulsation 1,6–1,8 s | uniquement pour « en direct » |

`prefers-reduced-motion: reduce` coupe toutes les animations et transformations. C'est implémenté dans chaque composant du prototype.

## 9. Composants Leezr proposés (cohérents avec Vuexy)

| Composant | Rôle | Construit avec | État |
|---|---|---|---|
| `LzHero` | accueil cockpit ou espace de travail | VChip, VProgressCircular, VAvatar | prototype |
| `LzKpiTile` | tuile KPI à profil de taille propre, compteur, mini-courbe | VCard, VueApexCharts, `useSurfacePresentation` | prototype |
| `LzWidget` | enveloppe commune de widget (en-tête, période, lien, mode mesuré) | VCard, `useSurfacePresentation` | prototype |
| `LzLivePanel` | panneau sombre de supervision | VCard `theme="dark"`, ApexCharts | prototype |
| `LzTrendWidget` | tendance recomposée par mode | `LzWidget` | prototype |
| segment « lz-seg » | choix court de 2 à 4 options (période, vue, rôle) | boutons natifs et jetons | prototype ; à extraire en `AppSegmented` |

**Politique UI (CLAUDE.md).** Ces composants **assemblent** des primitives Vuetify et Vuexy ; ils n'inventent aucune interaction. Avant tout usage métier, ils doivent être enregistrés comme presets Leezr dans `resources/ui/presets/` et documentés dans `docs/bmad/06-ui-policy.md` (lot L0). Aujourd'hui, ils ne vivent que dans `resources/js/components/lab/`, utilisés par les pages `design-lab` (`devOnly`).
