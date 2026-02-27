<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = useCompanyBillingStore()

const isLoading = ref(true)

const headers = computed(() => [
  { title: t('companyBilling.paymentDate'), key: 'created_at' },
  { title: t('companyBilling.paymentAmount'), key: 'amount', align: 'end' },
  { title: t('companyBilling.paymentStatus'), key: 'status', width: '130px' },
  { title: t('companyBilling.paymentProvider'), key: 'provider' },
])

const statusColor = status => {
  const colors = {
    succeeded: 'success',
    pending: 'warning',
    failed: 'error',
    refunded: 'info',
  }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const labels = {
    succeeded: t('companyBilling.paymentStatusSucceeded'),
    pending: t('companyBilling.paymentStatusPending'),
    failed: t('companyBilling.paymentStatusFailed'),
    refunded: t('companyBilling.paymentStatusRefunded'),
  }

  return labels[status] || status
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const loadPayments = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchPayments({ page })
  }
  finally {
    isLoading.value = false
  }
}

const onPageChange = page => {
  loadPayments(page)
}

onMounted(() => loadPayments())
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

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.payments.length === 0"
        type="table"
      />

      <div
        v-else-if="store.payments.length === 0 && !isLoading"
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

      <VDataTable
        v-else
        :headers="headers"
        :items="store.payments"
        :loading="isLoading"
        :items-per-page="store.paymentPagination.per_page"
        hide-default-footer
      >
        <template #item.created_at="{ item }">
          {{ formatDate(item.created_at) }}
        </template>

        <template #item.amount="{ item }">
          <span class="font-weight-medium">
            {{ formatMoney(item.amount) }}
          </span>
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="statusColor(item.status)"
            size="small"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.provider="{ item }">
          {{ item.provider || '—' }}
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('companyBilling.paymentCount', { count: store.paymentPagination.total }) }}
            </span>
            <VPagination
              v-if="store.paymentPagination.last_page > 1"
              :model-value="store.paymentPagination.current_page"
              :length="store.paymentPagination.last_page"
              :total-visible="5"
              @update:model-value="onPageChange"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
