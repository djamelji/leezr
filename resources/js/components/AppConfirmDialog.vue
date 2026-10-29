<script setup>
// W-G1 (ADR-641) — confirmation d'action réutilisable (suppression, action sensible).
// Affiche le nom de l'élément, l'explication, les conséquences, et remonte l'erreur serveur
// de façon lisible (ex. 422 « département avec collaborateurs »). Style Vuexy (VDialog/VCard),
// pas de second design system.
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  message: { type: String, default: '' },
  itemName: { type: String, default: '' },
  consequence: { type: String, default: '' },
  confirmText: { type: String, default: '' },
  cancelText: { type: String, default: '' },
  confirmColor: { type: String, default: 'error' },
  confirmIcon: { type: String, default: 'tabler-trash' },
  loading: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'confirm'])

const { t } = useI18n()

const isOpen = computed({
  get: () => props.modelValue,
  set: v => emit('update:modelValue', v),
})
</script>

<template>
  <VDialog
    v-model="isOpen"
    max-width="460"
    persistent
  >
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            :color="confirmColor"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-alert-triangle" />
          </VAvatar>
        </template>
        <VCardTitle>{{ title || t('common.areYouSure') }}</VCardTitle>
      </VCardItem>

      <VCardText>
        <p class="mb-2">
          {{ message }}
          <strong v-if="itemName">{{ itemName }}</strong>
        </p>
        <p
          v-if="consequence"
          class="text-body-2 text-medium-emphasis mb-0"
        >
          {{ consequence }}
        </p>
        <VAlert
          v-if="error"
          type="error"
          variant="tonal"
          density="compact"
          class="mt-3"
        >
          {{ error }}
        </VAlert>
      </VCardText>

      <VCardText class="d-flex justify-end gap-3 pt-0">
        <VBtn
          variant="outlined"
          color="secondary"
          :disabled="loading"
          @click="isOpen = false"
        >
          {{ cancelText || t('common.cancel') }}
        </VBtn>
        <VBtn
          :color="confirmColor"
          :prepend-icon="confirmIcon"
          :loading="loading"
          @click="emit('confirm')"
        >
          {{ confirmText || t('common.delete') }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
