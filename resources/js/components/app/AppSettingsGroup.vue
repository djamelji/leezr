<script setup>
// ADR-782 (UX6) — groupe de réglages : un titre, une phrase, puis UNE surface qui contient des lignes
// séparées par des filets (AppSettingRow). Pas une carte par réglage.
defineProps({
  id: { type: String, default: undefined },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  quiet: { type: Boolean, default: false }, // groupe secondaire (administration avancée)
})
</script>

<template>
  <section
    :id="id"
    class="app-settings-group"
    :class="{ 'app-settings-group--quiet': quiet }"
  >
    <div class="app-settings-group__head">
      <h2 class="app-settings-group__title">
        {{ title }}
      </h2>
      <p
        v-if="description"
        class="app-settings-group__description"
      >
        {{ description }}
      </p>
    </div>
    <div class="app-settings-group__list">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.app-settings-group { scroll-margin-block-start: 88px; }
.app-settings-group + .app-settings-group { margin-block-start: 28px; }
.app-settings-group__head { margin-block-end: 8px; }
.app-settings-group__title { margin: 0; font-size: 1rem; font-weight: 600; line-height: 1.4; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-settings-group__description { margin: 2px 0 0; font-size: 0.875rem; line-height: 1.5; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-settings-group__list {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  background: rgb(var(--v-theme-surface));
  overflow: hidden;
}
.app-settings-group--quiet .app-settings-group__title { font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-settings-group--quiet .app-settings-group__list { background: transparent; }
</style>
