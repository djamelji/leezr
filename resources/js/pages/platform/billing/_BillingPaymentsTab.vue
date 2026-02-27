<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.amount'), key: 'amount', align: 'end' },
  { title: t('platformBilling.status'), key: 'status', width: '130px' },
  { title: t('platformBilling.paymentProvider'), key: 'provider' },
  { title: t('platformBilling.issuedAt'), key: 'created_at' },
])

const statusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.statusSucceeded'), value: 'succeeded' },
  { title: t('platformBilling.statusPending'), value: 'pending' },
  { title: t('platformBilling.statusFailed'), value: 'failed' },
  { title: t('platformBilling.statusRefunded'), value: 'refunded' },
])

const statusColor = status => {
  const colors = { succeeded: 'success', pending: 'warning', failed: 'error', refunded: 'info' }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const map = { succeeded: 'statusSucceeded', pending: 'statusPending', failed: 'statusFailed', refunded: 'statusRefunded' }

  return t(`platformBilling.${map[status] || status}`)
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllPayments({
      page,
      status: statusFilter.value || undefined,
    })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
watch(statusFilter, () => load(1))
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-cash"
        class="me-2"
      />
      {{ t('platformBilling.tabs.payments') }}
      <VSpacer />
      <AppSelect
        v-model="statusFilter"
        :items="statusOptions"
        density="compact"
        style="max-inline-size: 160px;"
      />
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.allPayments.length === 0"
        type="table"
      />

      <div
        v-else-if="store.allPayments.length === 0 && !isLoading"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-cash-off"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('platformBilling.noPayments') }}
        </p>
      </div>

      <VDataTable
        v-else
        :headers="headers"
        :items="store.allPayments"
        :loading="isLoading"
        :items-per-page="store.allPaymentsPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <span class="font-weight-medium">
            {{ item.company?.name || '—' }}
          </span>
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

        <template #item.created_at="{ item }">
          {{ formatDate(item.created_at) }}
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.paymentCount', { count: store.allPaymentsPagination.total }) }}
            </span>
            <VPagination
              v-if="store.allPaymentsPagination.last_page > 1"
              :model-value="store.allPaymentsPagination.current_page"
              :length="store.allPaymentsPagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
