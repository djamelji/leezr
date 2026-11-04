# Smart Responsive UX — Primitives Leezr (contrats d'utilisation)

> Fondations responsive **stabilisées** (E3-R, ADR-693→696). Composants **génériques** du design system
> Leezr, par-dessus Vuexy/Vuetify (aucun fork `@core`/`@layouts`, aucun 2ᵉ framework UI). Réutilisables
> par tous les modules (Platform/Company/Employee/Workforce/Documents/Finance…) et JobDomains.
> **Aucune logique métier** dans ces composants : les modules fournissent données & actions via leurs contrats.

Emplacement : `resources/js/core/components/*` · composable `resources/js/composables/useContainerWidth.js`.
Laboratoire interactif (DEV, hors nav) : `/company/dev-lab`.

---

## `useContainerWidth(elRef)`
Observe la largeur RÉELLE d'un conteneur (ResizeObserver + rAF). Permet un responsive piloté par le
conteneur (un composant peut être étroit même sur desktop : drawer, dialog, panneau).
```js
const el = ref(null); const { width } = useContainerWidth(el)
```

## `SmartActionBar`
Command bar à overflow (3 modes), pilotée par la largeur réelle du conteneur.
```vue
<SmartActionBar :actions="actions" @action="id => …" />
```
**Contrat d'une action** : `{ id, label, icon?, priority?, group?, permission?|anyPermission?, visible?,
disabled?, variant?, color?, danger?, sensitive?, compactable?, tooltip?, to?|handler?, primary?, dataTest? }`
- `primary:true` = action principale (explicite, pas l'ordre d'insertion) ; **toujours visible**.
- `compactable` (déf. `icône && !danger && !sensitive`) : autorise le mode **icône seule** (+ tooltip + aria-label).
- `danger`/`sensitive` : **jamais icône seule** (libellé conservé) ; la **confirmation reste dans le `handler`**.
- `permission` (AND) / `anyPermission` (OR) : filtre via `useCan` (owner bypass).
- Modes auto : COMPLET (icône+texte) · COMPACT (icône+tooltip) · OVERFLOW (menu « Plus »). `compactBelow` (déf. 400).
- Place-la dans un conteneur flex : elle **remplit l'espace** pour mesurer la largeur disponible.

## `AppResponsiveDataView`
Table desktop / liste tactile mobile — mêmes données & règles, présentation variable.
```vue
<AppResponsiveDataView v-model="selected" v-model:options="options" show-select :headers :items
  :items-length :loading item-value="id" @row-click="goTo">
  <template #mobile-item="{ item }"> … ligne compacte (2-3 infos) … </template>
  <template #item.status="{ item }"> … (colonnes desktop, transmises) </template>
  <template #no-data> … </template>
</AppResponsiveDataView>
```
- `header.priority = 'essential' | 'important' | 'secondary' | 'detail'` : desktop tout · tablette
  (600-959) essential+important · mobile (<600) **liste** (`#mobile-item`). Sans priorité → essentiel.
- **DISPONIBILITÉ ≠ PRÉSENCE** : retirer e-mail/tél de la liste mobile, les garder en détail. **Jamais**
  masquer une donnée pour contourner la **confidentialité serveur** (qui reste la règle).
- **Ne pas utiliser** pour les grilles complexes (planning) : adaptation propre.

## `AppSectionNavigation`
Navigation secondaire = **navigation supérieure au-dessus du contenu** (hiérarchie Vuexy). Le parent met
le panneau EN DESSOUS (ne pas enfermer les onglets dans la carte de contenu).
```vue
<AppSectionNavigation v-model="tab" :sections="[{ value, label, icon }]" />
<VCard><VCardText><VWindow v-model="tab">…</VWindow></VCardText></VCard>
```
- Desktop : onglets icône+libellé. Mobile : **icônes seules réparties, sans scroll ni troncature** ;
  « Plus » explicite si trop nombreux. aria-label + tooltip. Sync `v-model` (routes/deep-links préservés par le parent).

## `AppFilterPanel`
Recherche directe toujours visible + filtres avancés (inline desktop / **bottom-sheet mobile**).
```vue
<AppFilterPanel v-model:search="search" :placeholder :active-count="n" @reset="reset">
  <template #filters> … champs de filtres (VRow/VCol) … </template>
</AppFilterPanel>
```
- Les **valeurs sont possédées par le parent** (préservées au changement de présentation/rotation).
- Compteur `active-count` + `@reset`.

## `AppAdaptiveDialog`
Dialogue adaptatif : centré desktop / **plein écran mobile** (en-tête + actions STICKY, non masquées par le clavier).
```vue
<AppAdaptiveDialog v-model="open" :title :max-width="480">
  <VForm ref="formRef">…</VForm>
  <template #actions="{ close }"> <VBtn @click="close">Annuler</VBtn> <VBtn color="primary" @click="submit">…</VBtn> </template>
</AppAdaptiveDialog>
```
- `fullscreenBelow` (déf. 600). Conserve focus/état/erreurs (gérés par le formulaire parent). Valider
  avant soumission (bouton hors `<VForm>` : `await formRef.value.validate()`).
- Pour un VDialog existant complexe, alternative minimale : `:fullscreen="$vuetify.display.smAndDown" scrollable`.

---

## Règles d'adoption
1. Choisir le signal selon le composant : **conteneur** (`useContainerWidth`) pour les barres d'actions/panneaux ;
   **viewport** (`useDisplay`) pour une vue de données pleine page.
2. Privilégier le **natif Vuetify** ; ne pas créer de nouveau moteur de formulaires/table/nav.
3. Garder la **logique métier côté serveur/stores** (API, permissions, règles) — les composants ne sont que
   la présentation (portabilité vers de futures apps iOS/Android : mêmes API/permissions/contrats).
4. Accessibilité obligatoire : aria-label sur les commandes icône seule, cibles tactiles ≥ ~44px, focus visible.
