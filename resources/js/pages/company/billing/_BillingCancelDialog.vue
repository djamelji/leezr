<script setup>
defineProps({
  isVisible: { type: Boolean, default: false },
  isCancelling: { type: Boolean, default: false },
})

const emit = defineEmits(['update:isVisible', 'confirm'])

const { t } = useI18n()
</script>

<template>
  <VDialog
    :model-value="isVisible"
    max-width="450"
    @update:model-value="emit('update:isVisible', $event)"
  >
    <VCard>
      <VCardTitle class="pt-5 px-5">
        {{ t('companyBilling.overview.cancelDialogTitle') }}
      </VCardTitle>
      <VCardText>
        {{ t('companyBilling.overview.cancelDialogDesc') }}
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="tonal"
          @click="emit('update:isVisible', false)"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          :loading="isCancelling"
          @click="emit('confirm')"
        >
          {{ t('companyBilling.overview.cancelConfirmBtn') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
