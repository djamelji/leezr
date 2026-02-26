<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'

const { t } = useI18n()
const store = useCompanyBillingStore()

const isLoading = ref(true)

onMounted(async () => {
  try {
    await store.fetchPayments()
  }
  finally {
    isLoading.value = false
  }
})
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-cash"
        class="me-2"
      />
      {{ t('companyBilling.payments') }}
    </VCardTitle>

    <VCardText>
      <VSkeletonLoader
        v-if="isLoading"
        type="table"
      />

      <div
        v-else
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-cash-off"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('companyBilling.noPayments') }}
        </p>
      </div>
    </VCardText>
  </VCard>
</template>
