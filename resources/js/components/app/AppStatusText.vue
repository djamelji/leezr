<script setup>
// ADR-782 (UX6) — état discret : une pastille + un texte. Remplace les puces colorées en série.
defineProps({
  tone: { type: String, default: 'info' }, // warning | success | info | error
  text: { type: String, required: true },
})
</script>

<template>
  <span
    class="app-status-text"
    :class="`app-status-text--${tone}`"
  >
    <span
      class="app-status-text__dot"
      aria-hidden="true"
    />
    <span class="app-status-text__label">{{ text }}</span>
  </span>
</template>

<style scoped>
.app-status-text { display: inline-flex; align-items: center; gap: 8px; font-size: 0.8125rem; line-height: 1.4; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-status-text__dot { flex: none; inline-size: 8px; block-size: 8px; border-radius: 50%; background: rgb(var(--v-theme-info)); }
.app-status-text--warning .app-status-text__dot { background: rgb(var(--v-theme-warning)); }
.app-status-text--success .app-status-text__dot { background: rgb(var(--v-theme-success)); }
.app-status-text--error .app-status-text__dot { background: rgb(var(--v-theme-error)); }
.app-status-text--warning .app-status-text__label { color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
</style>
