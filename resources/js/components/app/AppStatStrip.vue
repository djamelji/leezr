<script setup>
// ADR-782 (UX6.1) — bande d'indicateurs : UNE surface, des cellules séparées par des filets
// (4 colonnes desktop, 2 sur mobile). Remplace les rangées de petites cartes KPI.
// ADR-861 (L7.7) : as many columns as cells (2–5 on desktop), optional `help` (AppMetricHelp) and `testid`.
const props = defineProps({
  // [{ key, label, value, icon?, tone? ('success' | 'warning' | 'info' | 'error' | 'muted'), help?, testid? }]
  items: { type: Array, required: true },
})
const cols = computed(() => Math.min(Math.max(props.items.length, 2), 5))
</script>

<template>
  <div
    class="app-stat-strip"
    :style="{ '--app-stat-cols': cols }"
  >
    <div
      v-for="s in items"
      :key="s.key"
      class="app-stat-strip__cell"
      :data-test="`stat-${s.key}`"
    >
      <div class="app-stat-strip__label">
        <VIcon
          v-if="s.icon"
          :icon="s.icon"
          size="16"
          :class="`app-stat-strip__icon--${s.tone || 'muted'}`"
        />
        <span
          class="app-stat-strip__text"
          :data-testid="s.testid"
        >{{ s.label }}</span>
        <AppMetricHelp
          v-if="s.help"
          :text="s.help"
          :label="s.label"
        />
      </div>
      <div
        class="app-stat-strip__value"
        :class="s.tone && s.tone !== 'muted' ? `text-${s.tone}` : ''"
      >
        {{ s.value }}
      </div>
    </div>
  </div>
</template>

<style scoped>
.app-stat-strip {
  display: grid; grid-template-columns: repeat(var(--app-stat-cols, 4), minmax(0, 1fr));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 10px;
  background: rgb(var(--v-theme-surface)); overflow: hidden;
}
.app-stat-strip__cell { padding: 14px 18px; min-inline-size: 0; }
.app-stat-strip__cell + .app-stat-strip__cell { border-inline-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
/* ADR-862 (A3) — the label wraps whenever ITS cell is too narrow (tablet with 5 cells, side panel, phone), never
   « Taux de conf… » : the cell width decides, not a viewport breakpoint. Words break only when a single word is wider. */
.app-stat-strip__label { display: flex; align-items: flex-start; gap: 6px; font-size: 0.8125rem; line-height: 1.3; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-stat-strip__text { min-inline-size: 0; overflow-wrap: break-word; }
.app-stat-strip__label :deep(.v-icon) { margin-block-start: 1px; flex-shrink: 0; }
.app-stat-strip__value { margin-block-start: 4px; font-size: 1.25rem; font-weight: 600; line-height: 1.3; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-stat-strip__icon--muted { color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); }
.app-stat-strip__icon--success { color: rgb(var(--v-theme-success)); }
.app-stat-strip__icon--warning { color: rgb(var(--v-theme-warning)); }
.app-stat-strip__icon--info { color: rgb(var(--v-theme-info)); }
@media (max-width: 599px) {
  .app-stat-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .app-stat-strip__cell:nth-child(odd) { border-inline-start: 0; }
  .app-stat-strip__cell:nth-child(n+3) { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
  .app-stat-strip__cell { padding: 12px 14px; }
  /* an odd last cell spans the row (no lone half-cell) */
  .app-stat-strip__cell:last-child:nth-child(odd) { grid-column: 1 / -1; }
}
</style>
