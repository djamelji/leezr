# Audit Bundle Frontend — Février 2026

> **Statut** : Audit terminé, aucun fix appliqué
> **Build baseline** : `pnpm build` — 9.22s, zéro erreur
> **Date** : 2026-02-13

---

## Métriques actuelles (production build)

| Métrique | Valeur |
|----------|--------|
| **Build total** | 5.4 MB (public/build/) |
| **JS total** | 1.2 MB (dont main.js = 454 KB / 158 KB gzip) |
| **CSS total** | 3.3 MB (dont main.css = 3,208 KB / 407 KB gzip) |
| **Chunks JS** | ~80 fichiers |
| **Chunks CSS** | ~30 fichiers |
| **Build time** | 9.22s |

### Top 5 chunks JS (gzip)
| Chunk | Raw | Gzip |
|-------|-----|------|
| main.js | 454 KB | 158 KB |
| DynamicFormRenderer.js | 59 KB | 18 KB |
| NavSearchBar.js | 56 KB | 20 KB |
| PlatformLayoutWithVerticalNav.js | 44 KB | 12 KB |
| VDataTable.js | 38 KB | 12 KB |

### Problème critique CSS
| Fichier | Raw | Gzip |
|---------|-----|------|
| **main.css** | **3,208 KB** | **407 KB** |

> Le CSS unique pèse 70% du build total. La cause principale : `icons.css` (2.85 MB) embarque les **5000+ icônes Tabler** en CSS inline alors que l'app n'en utilise que **~180**.

---

## LOT-BUNDLE-01 — Icons CSS (CRITIQUE)

**Problème** : `build-icons.js` importe **l'intégralité** de `@iconify-json/tabler/icons.json` (ligne 46) sans filtrage. Résultat : 2.85 MB de CSS pour ~5000 icônes dont ~180 sont utilisées.

**Impact estimé** : -2.5 MB CSS raw / -300 KB gzip

**Action** :
1. Lister les ~180 icônes réellement utilisées (audit fait, liste disponible)
2. Dans `build-icons.js`, remplacer l'import complet par un import filtré :
   ```js
   // AVANT (ligne 46)
   require.resolve('@iconify-json/tabler/icons.json'),
   // APRÈS
   {
     filename: require.resolve('@iconify-json/tabler/icons.json'),
     icons: ['check', 'x', 'home', 'user', ...], // ~180 noms
   },
   ```
3. Régénérer `icons.css` : `node resources/js/plugins/iconify/build-icons.js`
4. Rebuild → vérifier CSS < 500 KB

**Dépendances** : Aucune. Le fichier `build-icons.js` supporte déjà le filtrage (voir lignes 48-59, pattern mdi/fa).

**Risque** : Faible — si une icône manque, elle apparaît comme carré vide, facile à détecter.

---

## LOT-BUNDLE-02 — Dépendances production supprimables

Packages jamais importés dans le code applicatif :

| Package | Version | Raison |
|---------|---------|--------|
| `apexcharts` | 3.54.1 | Zero import dans pages/. VueApexCharts auto-resolver présent mais jamais consommé |
| `vue3-apexcharts` | 1.5.3 | Idem, aucun chart apex dans l'app |
| `prismjs` | 1.30.0 | Zero import. `AppCardCode.vue` (@core) l'utilise mais aucune page ne consomme AppCardCode |
| `vue-prism-component` | 2.0.0 | Idem, dépendance de prismjs |
| `jwt-decode` | 4.0.0 | Zero import. Auth utilise cookies, pas JWT |
| `roboto-fontface` | 0.10.0 | Zero import. L'app utilise Public Sans via webfontloader |
| `unplugin-vue-define-options` | 1.5.5 | Remplacé par `defineOptions()` natif (Vue 3.3+) |
| `@tiptap/extension-highlight` | ^2.27.2 | Importé uniquement dans `@core/components/TiptapEditor.vue`, qui n'est consommé par aucune page |
| `eslint-plugin-regexp` | 2.10.0 | **Mal classé** : c'est un dev dep, pas une prod dep |

**Impact estimé** : -0 KB bundle (tree-shaking les élimine déjà), mais -15 MB node_modules et install plus rapide

**Action** : `pnpm remove apexcharts vue3-apexcharts prismjs vue-prism-component jwt-decode roboto-fontface unplugin-vue-define-options @tiptap/extension-highlight` + déplacer `eslint-plugin-regexp` vers devDependencies

---

## LOT-BUNDLE-03 — VueApexCharts auto-resolver

**Problème** : `vite.config.js` lignes 59-63 enregistrent un resolver auto-import pour `VueApexCharts`. Ce resolver cause potentiellement l'inclusion d'apexcharts dans le bundle même sans import explicite.

**Impact estimé** : -0 à -200 KB JS (à vérifier post-suppression)

**Action** :
1. Supprimer le resolver dans `Components({ resolvers: [...] })` :
   ```diff
   - resolvers: [
   -   componentName => {
   -     if (componentName === 'VueApexCharts')
   -       return { name: 'default', from: 'vue3-apexcharts', as: 'VueApexCharts' }
   -   },
   - ],
   + resolvers: [],
   ```
2. Supprimer la règle ESLint `no-restricted-imports` pour `vue3-apexcharts` (`.eslintrc.cjs` ligne 103-105)
3. Supprimer `isCustomElement` pour swiper dans `vue()` config (lignes 32) — swiper non utilisé
4. Rebuild + diff taille

**Dépendance** : LOT-BUNDLE-02 (retirer les packages d'abord)

---

## LOT-BUNDLE-04 — Dépendances dev supprimables

Packages jamais utilisés dans le code ni la config :

| Package | Raison |
|---------|--------|
| `@fullcalendar/core` | Zero import hors presets/fake-api |
| `@fullcalendar/daygrid` | Idem |
| `@fullcalendar/interaction` | Idem |
| `@fullcalendar/list` | Idem |
| `@fullcalendar/timegrid` | Idem |
| `@fullcalendar/vue3` | Idem |
| `@tiptap/extension-character-count` | Zero import hors @core |
| `@tiptap/extension-subscript` | Idem |
| `@tiptap/extension-superscript` | Idem |
| `@antfu/eslint-config-vue` | Non référencé dans .eslintrc.cjs |
| `eslint-config-airbnb-base` | Non référencé dans .eslintrc.cjs |
| `eslint-plugin-unicorn` | Commenté dans .eslintrc.cjs (ligne 15) |
| `postcss-html` | Pas de config PostCSS qui l'utilise |
| `postcss-scss` | Idem |
| `@stylistic/stylelint-config` | Pas de .stylelintrc trouvé |
| `@stylistic/stylelint-plugin` | Idem |
| `stylelint` | Idem |
| `stylelint-config-idiomatic-order` | Idem |
| `stylelint-config-standard-scss` | Idem |
| `stylelint-use-logical-spec` | Idem |
| `@intlify/unplugin-vue-i18n` | Non utilisé dans vite.config.js |
| `@iconify-json/fa` | 1 seule icône (`fa:circle`), utilisée uniquement par le build script icons |
| `@formkit/drag-and-drop` | Zero import dans les pages |
| `mapbox-gl` | Utilisé uniquement dans 1 preset (`presets/pages/templates/apps/logistics/fleet.vue`) |
| `swiper` | Zero import (custom elements déclarés mais jamais utilisés) |
| `chart.js` + `vue-chartjs` | Wrappers dans @core mais zéro page ne les consomme |
| `shepherd.js` + `vue-shepherd` | Utilisé uniquement dans `NavSearchBar.vue` (tour feature) — garder si feature prévue |

**Impact estimé** : -0 KB bundle, -50 MB+ node_modules, install 2-3x plus rapide

**Action** : `pnpm remove @fullcalendar/core @fullcalendar/daygrid @fullcalendar/interaction @fullcalendar/list @fullcalendar/timegrid @fullcalendar/vue3 @tiptap/extension-character-count @tiptap/extension-subscript @tiptap/extension-superscript @antfu/eslint-config-vue eslint-config-airbnb-base eslint-plugin-unicorn postcss-html postcss-scss @stylistic/stylelint-config @stylistic/stylelint-plugin stylelint stylelint-config-idiomatic-order stylelint-config-standard-scss stylelint-use-logical-spec @intlify/unplugin-vue-i18n @formkit/drag-and-drop`

**Décision requise** :
- `mapbox-gl` : garder si fleet/logistics prévu, sinon supprimer (-2.8 MB node_modules)
- `swiper` : garder si carousel prévu, sinon supprimer (-3.9 MB node_modules)
- `chart.js` + `vue-chartjs` : garder si dashboards avec charts prévus, sinon supprimer
- `shepherd.js` + `vue-shepherd` : garder si tour/onboarding prévu

---

## LOT-BUNDLE-05 — i18n lazy loading

**Problème** : Les 3 locales (en, fr, ar) sont chargées eagerly au boot via `import.meta.glob('./locales/*.json', { eager: true })`. L'utilisateur n'a besoin que d'une locale à la fois.

**Impact estimé** : -16 KB JS initial (2 locales × ~8 KB chaque)

**Action** :
1. Passer en lazy loading :
   ```js
   // AVANT
   const messages = Object.fromEntries(
     Object.entries(import.meta.glob('./locales/*.json', { eager: true }))
       .map(([key, value]) => [key.slice(10, -5), value.default])
   )

   // APRÈS
   import { createI18n } from 'vue-i18n'
   import en from './locales/en.json'

   const i18n = createI18n({
     locale: cookieLocale || 'fr',
     fallbackLocale: 'en',
     messages: { en },
   })

   // Lazy-load la locale active
   export async function loadLocale(locale) {
     if (i18n.global.availableLocales.includes(locale)) return
     const messages = await import(`./locales/${locale}.json`)
     i18n.global.setLocaleMessage(locale, messages.default)
   }
   ```
2. Appeler `loadLocale()` dans le runtime boot ou au changement de langue

**Dépendance** : Aucune

---

## LOT-BUNDLE-06 — Build optimizations (vite.config.js)

### 6a — Chunk splitting

**Problème** : `chunkSizeWarningLimit: 5000` masque un main.js de 454 KB. Aucun `manualChunks` configuré.

**Action** :
```js
build: {
  chunkSizeWarningLimit: 500, // Rétablir un seuil utile
  rollupOptions: {
    output: {
      manualChunks: {
        'vendor-vue': ['vue', 'vue-router', 'pinia', 'vue-i18n'],
        'vendor-vuetify': ['vuetify'],
        'vendor-vueuse': ['@vueuse/core'],
      },
    },
  },
},
```

**Impact estimé** : Meilleur cache navigateur (vendor change rarement), main.js < 200 KB

### 6b — Auto-import cleanup

**Problème** : `@vueuse/math` déclaré dans AutoImport mais jamais utilisé dans le code app.

**Action** : Retirer `'@vueuse/math'` de la liste AutoImport (ligne 68)

### 6c — `casl-BV0fBggu.js` ghost chunk

**Statut** : **ATTENDU** — Ce chunk de 54 bytes est le stub `@layouts/plugins/casl.js` qui exporte `can = () => true`. Les composants de navigation `@layouts` l'importent et on ne peut pas modifier `@layouts`. Coût négligeable (54 bytes), pas d'action requise.

---

## LOT-BUNDLE-07 — Fake API cleanup

**Problème** : `index.js.disabled` mais les 54 handler files + `msw` (Mock Service Worker) sont toujours installés. Ils ne sont pas dans le bundle (le plugin est disabled) mais polluent node_modules.

**Impact estimé** : -0 KB bundle, significatif en install time

**Action** :
- Garder si besoin de mocking futur
- Sinon : `pnpm remove msw` + archiver les handlers

**Décision requise** : La fake-api sert-elle encore en dev ?

---

## LOT-BUNDLE-08 — `@iconify-json/fa` et `@iconify-json/mdi` minimalisation

**Problème** : Deux icon sets supplémentaires installés pour respectivement 1 icône (`fa:circle`) et 3 icônes (`mdi:close-circle`, `mdi:language-javascript`, `mdi:language-typescript`). Ces icônes sont déjà dans `icons.css` (embarquées via le build script).

**Impact estimé** : -0 KB bundle (déjà dans icons.css), -20 MB node_modules

**Action** : Après LOT-BUNDLE-01 (filtrage tabler), vérifier si les 4 icônes mdi/fa sont toujours nécessaires. Si oui, les garder dans `build-icons.js` et supprimer les packages. Si non, les retirer aussi du build script.

---

## Résumé impact par LOT

| LOT | Impact Bundle | Impact node_modules | Risque | Indépendant |
|-----|--------------|---------------------|--------|-------------|
| **01 — Icons CSS** | **-2.5 MB CSS / -300 KB gzip** | 0 | Faible | Oui |
| 02 — Prod deps | ~0 (tree-shaken) | -15 MB | Nul | Oui |
| 03 — Apex resolver | 0 à -200 KB JS | 0 | Nul | Après 02 |
| 04 — Dev deps | 0 | -50 MB+ | Nul | Oui |
| 05 — i18n lazy | -16 KB JS initial | 0 | Faible | Oui |
| 06 — Build config | Meilleur caching | 0 | Faible | Oui |
| 07 — Fake API | 0 | -5 MB | Nul | Oui |
| 08 — Icon sets | 0 | -20 MB | Nul | Après 01 |

### Projection

| Métrique | Avant | Après tous LOTs |
|----------|-------|----------------|
| main.css | 3,208 KB (407 KB gz) | ~700 KB (~90 KB gz) |
| main.js | 454 KB (158 KB gz) | ~250 KB (~90 KB gz) * |
| node_modules | ~300 MB | ~210 MB |
| Install time | ~30s | ~15s |

\* Avec chunk splitting, main.js est réparti en vendor-vue + vendor-vuetify + app

---

## Fichiers d'audit générés

- `dist/stats.html` — Treemap interactif (rollup-plugin-visualizer)
- Ce document (`docs/bmad/audit-bundle-2026-02.md`)

## Prochaine étape

Valider les décisions requises (LOT-04, LOT-07) puis implémenter par lot, en commençant par **LOT-BUNDLE-01** (impact maximal, risque minimal).
