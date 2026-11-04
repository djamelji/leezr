<script setup>
// SMART RESPONSIVE UX 2026 (ADR-696) — dialogue adaptatif à la complexité & à l'espace.
//   • desktop/tablette → VDialog centré (max-width selon la complexité) ;
//   • mobile (< fullscreenBelow) → vue PLEIN ÉCRAN, en-tête + actions STICKY (jamais masquées par le
//     clavier virtuel), contenu scrollable. Conserve focus/état/erreurs (géré par le formulaire parent).
// Natif Vuetify (VDialog scrollable + fullscreen) — PAS un nouveau moteur de formulaires. Générique.
import { computed } from 'vue'
import { useDisplay } from 'vuetify'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  maxWidth: { type: [Number, String], default: 560 },
  persistent: { type: Boolean, default: false },
  fullscreenBelow: { type: Number, default: 600 },
})
const emit = defineEmits(['update:modelValue'])

const { width } = useDisplay()
const isFullscreen = computed(() => width.value < props.fullscreenBelow)
const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
</script>

<template>
  <VDialog
    v-model="open"
    :fullscreen="isFullscreen"
    :max-width="isFullscreen ? undefined : maxWidth"
    :persistent="persistent"
    scrollable
    :transition="isFullscreen ? 'dialog-bottom-transition' : undefined"
  >
    <VCard class="aad-card">
      <VCardItem class="aad-header flex-grow-0">
        <VCardTitle class="text-truncate">
          <slot name="title">{{ title }}</slot>
        </VCardTitle>
        <template #append>
          <VBtn
            icon="tabler-x"
            variant="text"
            size="small"
            aria-label="Fermer"
            data-test="aad-close"
            @click="open = false"
          />
        </template>
      </VCardItem>
      <VDivider />

      <VCardText class="aad-content">
        <slot />
      </VCardText>

      <template v-if="$slots.actions">
        <VDivider />
        <VCardActions class="aad-actions px-4 py-3">
          <slot name="actions" :close="() => (open = false)" />
        </VCardActions>
      </template>
    </VCard>
  </VDialog>
</template>

<style lang="scss">
.aad-card {
  display: flex;
  flex-direction: column;

  // Plein écran mobile : en-tête + actions fixes, contenu scrollable (actions accessibles clavier ouvert).
  .aad-content {
    flex: 1 1 auto;
    overflow-y: auto;
    // Respecte la safe area basse (home indicator) quand les actions sont en bas.
    padding-block-end: max(16px, env(safe-area-inset-bottom));
  }
  .aad-actions {
    flex: 0 0 auto;
    gap: 8px;
  }
}
</style>
