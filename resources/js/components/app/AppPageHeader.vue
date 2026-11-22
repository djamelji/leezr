<script setup>
// ADR-782 (UX6) — en-tête de page Leezr : titre, phrase de contexte, actions à droite, outil (recherche,
// filtres) en dessous ou à droite. Compact, aligné sur la grille ; aucune carte autour.
// ADR-860 (Leezr 5.0 L7) — THE page header of Platform and Company : V22/V35 typography, an optional `eyebrow`
// (where am I : « Facturation › Encaissements ») so that pages of one family never share the same bare title.
// ADR-861 (L7.7) — detail pages : `back` ({ to, label }) puts the way back in the eyebrow line, `meta` slot carries the
// status chips under the title (was : a bare arrow icon and a hand-made h4/h5 on every detail page).
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
  eyebrow: { type: String, default: '' },
  back: { type: Object, default: null },
})
</script>

<template>
  <header class="app-page-header">
    <div class="app-page-header__main">
      <div class="app-page-header__text">
        <div
          v-if="eyebrow || back"
          class="app-page-header__eyebrow"
          data-test="page-eyebrow"
        >
          <RouterLink
            v-if="back"
            :to="back.to"
            class="app-page-header__back"
            data-test="page-back"
          >
            <VIcon
              icon="tabler-arrow-left"
              size="14"
            />
            {{ back.label }}
          </RouterLink>
          <span
            v-if="back && eyebrow"
            aria-hidden="true"
          > › </span>
          <span v-if="eyebrow">{{ eyebrow }}</span>
        </div>
        <h1 class="app-page-header__title">
          {{ title }}
        </h1>
        <p
          v-if="description"
          class="app-page-header__description"
        >
          {{ description }}
        </p>
        <div
          v-if="$slots.meta"
          class="app-page-header__meta"
        >
          <slot name="meta" />
        </div>
      </div>
      <div
        v-if="$slots.actions"
        class="app-page-header__actions"
      >
        <slot name="actions" />
      </div>
    </div>
    <div
      v-if="$slots.tools"
      class="app-page-header__tools"
    >
      <slot name="tools" />
    </div>
  </header>
</template>

<style scoped>
.app-page-header { margin-block-end: 24px; }
.app-page-header__main { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 24px; }
.app-page-header__text { flex: 1 1 320px; min-inline-size: 0; }
.app-page-header__eyebrow { display: flex; flex-wrap: wrap; align-items: center; gap: 0 4px; margin-block-end: 4px; font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgb(var(--v-theme-primary)); }
.app-page-header__title { margin: 0; font-size: clamp(1.625rem, 2.2vw, 2.125rem); font-weight: 700; line-height: 1.15; letter-spacing: -0.03em; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-page-header__description { margin: 4px 0 0; font-size: 0.9375rem; line-height: 1.5; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); max-inline-size: 68ch; }
.app-page-header__back { display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none; }
.app-page-header__back:hover, .app-page-header__back:focus-visible { text-decoration: underline; }
.app-page-header__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-block-start: 8px; }
.app-page-header__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.app-page-header__tools { margin-block-start: 16px; }
@media (max-width: 599px) {
  .app-page-header__title { font-size: 1.5rem; }
  .app-page-header__actions { flex: 1 1 100%; }
  .app-page-header__actions > * { flex: 1 1 auto; }
}
</style>
