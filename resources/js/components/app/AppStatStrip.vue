<script setup>
// ADR-782 (UX6.1) — bande d'indicateurs : UNE surface, des cellules séparées par des filets
// (4 colonnes desktop, 2 sur mobile). Remplace les rangées de petites cartes KPI.
defineProps({
  // [{ key, label, value, icon?, tone? ('success' | 'warning' | 'info' | 'error' | 'muted') }]
  items: { type: Array, required: true },
})
</script>

<template>
  <div class="app-stat-strip">
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
        {{ s.label }}
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
  display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); border-radius: 10px;
  background: rgb(var(--v-theme-surface)); overflow: hidden;
}
.app-stat-strip__cell { padding: 14px 18px; min-inline-size: 0; }
.app-stat-strip__cell + .app-stat-strip__cell { border-inline-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.app-stat-strip__label { display: flex; align-items: center; gap: 6px; font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.app-stat-strip__value { margin-block-start: 4px; font-size: 1.25rem; font-weight: 600; line-height: 1.3; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-stat-strip__icon--muted { color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); }
.app-stat-strip__icon--success { color: rgb(var(--v-theme-success)); }
.app-stat-strip__icon--warning { color: rgb(var(--v-theme-warning)); }
.app-stat-strip__icon--info { color: rgb(var(--v-theme-info)); }
@media (max-width: 599px) {
  .app-stat-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .app-stat-strip__cell:nth-child(3) { border-inline-start: 0; }
  .app-stat-strip__cell:nth-child(n+3) { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
  .app-stat-strip__cell { padding: 12px 14px; }
}
</style>
