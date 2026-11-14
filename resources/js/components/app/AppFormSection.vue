<script setup>
// ADR-782 (UX6) — section de formulaire : intitulé et explication à gauche, champs à droite ; empilés sur
// mobile. Les sections se suivent dans UNE surface, séparées par un filet (pas une carte par groupe).
defineProps({
  title: { type: String, required: true },
  description: { type: String, default: '' },
})
</script>

<template>
  <section class="app-form-section">
    <div class="app-form-section__aside">
      <h3 class="app-form-section__title">
        {{ title }}
      </h3>
      <p
        v-if="description"
        class="app-form-section__description"
      >
        {{ description }}
      </p>
      <slot name="aside" />
    </div>
    <div class="app-form-section__fields">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.app-form-section { display: grid; grid-template-columns: minmax(200px, 280px) minmax(0, 1fr); gap: 16px 40px; padding: 24px; }
.app-form-section + .app-form-section { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.app-form-section__title { margin: 0; font-size: 0.9375rem; font-weight: 600; line-height: 1.4; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-form-section__description { margin: 4px 0 0; font-size: 0.8125rem; line-height: 1.5; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-form-section__fields { min-inline-size: 0; }
@media (max-width: 959px) {
  .app-form-section { grid-template-columns: minmax(0, 1fr); gap: 12px; padding: 20px 16px; }
}
</style>
