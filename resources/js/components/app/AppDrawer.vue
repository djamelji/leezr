<script setup>
// ADR-782 (UX6.1) — tiroir Leezr : en-tête Vuexy (AppDrawerHeaderSection : titre + fermer), corps qui
// défile, largeur bornée à l'écran. Les boutons du formulaire portent la classe `app-drawer-actions`
// (pied fixe « Annuler · action principale ») et restent DANS le formulaire (soumission inchangée).
import { useDisplay } from 'vuetify'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  width: { type: Number, default: 420 },
})
const emit = defineEmits(['update:modelValue'])
const { width: viewport } = useDisplay()
const drawerWidth = computed(() => Math.min(props.width, viewport.value || props.width))
</script>

<template>
  <VNavigationDrawer
    temporary
    location="end"
    :width="drawerWidth"
    class="app-drawer"
    :model-value="modelValue"
    @update:model-value="v => emit('update:modelValue', v)"
  >
    <AppDrawerHeaderSection
      :title="title"
      @cancel="emit('update:modelValue', false)"
    />
    <p
      v-if="subtitle"
      class="app-drawer__subtitle"
    >
      {{ subtitle }}
    </p>
    <VDivider />
    <div class="app-drawer__body">
      <slot />
    </div>
  </VNavigationDrawer>
</template>

<style scoped>
.app-drawer__subtitle { margin: -8px 24px 12px; font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-drawer__body { block-size: calc(100% - 64px); overflow-y: auto; padding: 20px 24px 0; }
.app-drawer__body :deep(.app-drawer-actions) {
  position: sticky; inset-block-end: 0; z-index: 1;
  display: flex; justify-content: flex-end; gap: 12px;
  margin-inline: -24px; margin-block-start: 8px; padding: 14px 24px;
  background: rgb(var(--v-theme-surface));
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
@media (max-width: 599px) {
  .app-drawer__body { padding: 16px 16px 0; }
  .app-drawer__body :deep(.app-drawer-actions) { margin-inline: -16px; padding: 12px 16px; }
}
</style>
