<script setup>
// ADR-782 (UX6) — une ligne de réglage : icône discrète, nom, description courte, état éventuel, chevron.
// Toute la ligne est le lien (cible large, clavier, mobile). Les lignes sont séparées par un filet.
defineProps({
  to: { type: [String, Object], default: null },
  icon: { type: String, default: '' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  // { tone: 'warning' | 'success' | 'info', text }
  status: { type: Object, default: null },
  hint: { type: String, default: '' }, // mention discrète à droite du titre (ex. « Recommandé »)
  statusTest: { type: String, default: undefined }, // sélecteur de recette de l'état
})
</script>

<template>
  <component
    :is="to ? 'RouterLink' : 'div'"
    :to="to || undefined"
    class="app-setting-row"
  >
    <span
      v-if="icon"
      class="app-setting-row__icon"
      aria-hidden="true"
    >
      <VIcon
        :icon="icon"
        size="20"
      />
    </span>
    <span class="app-setting-row__body">
      <span class="app-setting-row__title">
        {{ title }}
        <span
          v-if="hint"
          class="app-setting-row__hint"
        >{{ hint }}</span>
      </span>
      <span
        v-if="description"
        class="app-setting-row__description"
      >{{ description }}</span>
    </span>
    <AppStatusText
      v-if="status"
      :tone="status.tone"
      :text="status.text"
      :data-test="statusTest"
      class="app-setting-row__status"
    />
    <VIcon
      v-if="to"
      icon="tabler-chevron-right"
      size="18"
      class="app-setting-row__chevron"
    />
  </component>
</template>

<style scoped>
.app-setting-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  grid-template-areas: "icon body status chevron";
  align-items: center;
  column-gap: 14px;
  padding: 14px 18px;
  color: inherit;
  text-decoration: none;
  transition: background-color 0.12s ease;
}
.app-setting-row + .app-setting-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
a.app-setting-row:hover { background: rgba(var(--v-theme-on-surface), 0.035); }
a.app-setting-row:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: -2px; }
.app-setting-row__icon {
  grid-area: icon;
  display: inline-flex; align-items: center; justify-content: center;
  inline-size: 36px; block-size: 36px; border-radius: 8px;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.app-setting-row__body { grid-area: body; display: flex; flex-direction: column; min-inline-size: 0; }
.app-setting-row__title { font-size: 0.9375rem; font-weight: 500; line-height: 1.4; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-setting-row__hint { margin-inline-start: 6px; font-size: 0.75rem; font-weight: 500; color: rgb(var(--v-theme-primary)); }
.app-setting-row__description { font-size: 0.8125rem; line-height: 1.45; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-setting-row__status { grid-area: status; }
.app-setting-row__chevron { grid-area: chevron; color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); }
@media (max-width: 599px) {
  .app-setting-row {
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-areas: "icon body chevron" ". status chevron";
    column-gap: 12px; row-gap: 6px; padding: 14px;
    align-items: start;
  }
  .app-setting-row__chevron { align-self: center; }
}
</style>
