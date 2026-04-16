<script setup>
import { formatDate } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'

defineProps({
  isVisible: { type: Boolean, default: false },
  isCancelling: { type: Boolean, default: false },
  cancelPreview: { type: Object, default: null },
})

const emit = defineEmits(['update:isVisible', 'confirm'])

const { t } = useI18n()

</script>

<template>
  <VDialog
    :model-value="isVisible"
    max-width="480"
    @update:model-value="emit('update:isVisible', $event)"
  >
    <VCard>
      <VCardTitle class="pt-5 px-5">
        {{ t('companyBilling.overview.cancelDialogTitle') }}
      </VCardTitle>
      <VCardText>
        {{ t('companyBilling.overview.cancelDialogDesc') }}

        <!-- ADR-340: Cancel preview info -->
        <template v-if="cancelPreview">
          <VDivider class="my-3" />

          <!-- Timing policy -->
          <div class="d-flex align-center gap-2 mb-2">
            <VIcon
              icon="tabler-calendar"
              size="18"
            />
            <span class="text-body-2">
              {{ cancelPreview.timing === 'immediate'
                ? t('companyBilling.overview.cancelImmediate')
                : t('companyBilling.overview.cancelAtPeriodEnd', { date: formatDate(cancelPreview.period_end) })
              }}
            </span>
          </div>

          <!-- Active addons impact -->
          <VAlert
            v-if="cancelPreview.active_addons?.length > 0"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-2"
          >
            {{ t('companyBilling.overview.cancelAddonsImpact', { count: cancelPreview.active_addons.length }) }}
          </VAlert>

          <!-- Wallet balance info -->
          <div
            v-if="cancelPreview.wallet_balance > 0"
            class="d-flex align-center gap-2 mt-3 text-body-2 text-medium-emphasis"
          >
            <VIcon
              icon="tabler-wallet"
              size="18"
            />
            {{ t('companyBilling.overview.cancelWalletInfo', { amount: formatMoney(cancelPreview.wallet_balance) }) }}
          </div>
        </template>
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
          v-can="'billing.manage'"
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
