<script setup>
// ADR-782 (UX6) — en-tête de page Leezr : titre, phrase de contexte, actions à droite, outil (recherche,
// filtres) en dessous ou à droite. Compact, aligné sur la grille ; aucune carte autour.
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>

<template>
  <header class="app-page-header">
    <div class="app-page-header__main">
      <div class="app-page-header__text">
        <h1 class="app-page-header__title">
          {{ title }}
        </h1>
        <p
          v-if="description"
          class="app-page-header__description"
        >
          {{ description }}
        </p>
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
.app-page-header__title { margin: 0; font-size: 1.5rem; font-weight: 600; line-height: 1.3; letter-spacing: -0.01em; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-page-header__description { margin: 4px 0 0; font-size: 0.9375rem; line-height: 1.5; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); max-inline-size: 68ch; }
.app-page-header__actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.app-page-header__tools { margin-block-start: 16px; }
@media (max-width: 599px) {
  .app-page-header__title { font-size: 1.375rem; }
  .app-page-header__actions { flex: 1 1 100%; }
  .app-page-header__actions > * { flex: 1 1 auto; }
}
</style>
