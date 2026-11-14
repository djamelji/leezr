<script setup>
// ADR-783 (UX6.2) — dialogue Leezr : titre (+ phrase), fermeture accessible (bouton icône étiqueté),
// corps qui défile, (bouton fermer DANS l'en-tête), pied d'actions STABLE à droite (« Annuler · action principale »), plein écran
// sur smartphone. Pour les décisions ponctuelles ; un formulaire long reste un AppDrawer.
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  maxWidth: { type: [Number, String], default: 560 },
  persistent: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue'])
const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
</script>

<template>
  <VDialog
    v-model="open"
    :max-width="maxWidth"
    :fullscreen="$vuetify.display.xs"
    :persistent="persistent"
    scrollable
  >
    <VCard class="app-dialog">
      <VBtn
        icon
        variant="text"
        size="small"
        class="app-dialog__close"
        :aria-label="$t('common.close')"
        data-test="app-dialog-close"
        @click="open = false"
      >
        <VIcon icon="tabler-x" />
      </VBtn>
      <div class="app-dialog__head">
        <h2 class="app-dialog__title">
          {{ title }}
        </h2>
        <p
          v-if="subtitle"
          class="app-dialog__subtitle"
        >
          {{ subtitle }}
        </p>
      </div>
      <VDivider />
      <VCardText class="app-dialog__body">
        <slot />
      </VCardText>
      <template v-if="$slots.actions">
        <VDivider />
        <div class="app-dialog__actions">
          <slot name="actions" />
        </div>
      </template>
    </VCard>
  </VDialog>
</template>

<style scoped>
.app-dialog { position: relative; }
.app-dialog__close { position: absolute; inset-block-start: 12px; inset-inline-end: 12px; z-index: 1; }
.app-dialog__head { padding: 20px 56px 16px 24px; }
.app-dialog__title { margin: 0; font-size: 1.125rem; font-weight: 600; line-height: 1.4; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.app-dialog__subtitle { margin: 4px 0 0; font-size: 0.875rem; line-height: 1.5; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.app-dialog__body { padding: 20px 24px !important; }
.app-dialog__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 12px; padding: 14px 24px; }
@media (max-width: 599px) {
  .app-dialog__head { padding: 16px 52px 12px 16px; }
  .app-dialog__body { padding: 16px !important; }
  .app-dialog__actions { padding: 12px 16px; }
  .app-dialog__actions > * { flex: 1 1 auto; }
}
</style>
