<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'

const { t } = useI18n()
const store = useCompanyBillingStore()

const isLoading = ref(true)

onMounted(async () => {
  try {
    await store.fetchPaymentMethods()
  }
  finally {
    isLoading.value = false
  }
})

const methodIcon = methodKey => {
  const icons = {
    card: 'tabler-credit-card',
    sepa_debit: 'tabler-building-bank',
    manual: 'tabler-file-invoice',
  }

  return icons[methodKey] || 'tabler-credit-card'
}
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-credit-card"
        class="me-2"
      />
      {{ t('companyBilling.paymentMethods') }}
    </VCardTitle>

    <VCardText>
      <VSkeletonLoader
        v-if="isLoading"
        type="card"
      />

      <div
        v-else-if="store.paymentMethods.length === 0"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-credit-card-off"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('companyBilling.noPaymentMethods') }}
        </p>
      </div>

      <VList v-else>
        <VListItem
          v-for="method in store.paymentMethods"
          :key="method.method_key"
        >
          <template #prepend>
            <VAvatar
              size="40"
              variant="tonal"
              color="primary"
              class="me-3"
            >
              <VIcon :icon="methodIcon(method.method_key)" />
            </VAvatar>
          </template>

          <VListItemTitle>{{ method.method_key }}</VListItemTitle>
          <VListItemSubtitle>{{ method.provider_key }}</VListItemSubtitle>
        </VListItem>
      </VList>
    </VCardText>
  </VCard>
</template>
