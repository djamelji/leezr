<script setup>
import StatusChip from '@/core/components/StatusChip.vue'
import EmptyState from '@/core/components/EmptyState.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const statusFilter = ref('')
const search = ref('')

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.amount'), key: 'amount', align: 'end' },
  { title: t('platformBilling.status'), key: 'status', width: '130px' },
  { title: t('platformBilling.paymentProvider'), key: 'provider' },
  { title: t('platformBilling.paymentMethod'), key: 'method', sortable: false },
  { title: t('platformBilling.issuedAt'), key: 'created_at' },
])

const statusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.statusSucceeded'), value: 'succeeded' },
  { title: t('platformBilling.statusPending'), value: 'pending' },
  { title: t('platformBilling.statusFailed'), value: 'failed' },
  { title: t('platformBilling.statusRefunded'), value: 'refunded' },
])

const statusLabel = status => {
  const map = { succeeded: 'statusSucceeded', pending: 'statusPending', failed: 'statusFailed', refunded: 'statusRefunded' }

  return t(`platformBilling.${map[status] || status}`)
}

const filteredPayments = computed(() => {
  if (!search.value) return store.allPayments
  const q = search.value.toLowerCase()

  return store.allPayments.filter(p =>
    p.company?.name?.toLowerCase().includes(q)
    || p.provider_payment_id?.toLowerCase().includes(q),
  )
})

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllPayments({
      page,
      status: statusFilter.value || undefined,
    })
  }
  catch {
    toast(t('common.loadError'), 'error')
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
    <VCardTitle class="d-flex align-center flex-wrap gap-2">
      <VIcon
        icon="tabler-cash"
        class="me-2"
      />
      {{ t('platformBilling.tabs.payments') }}
      <VSpacer />
      <AppTextField
        v-model="search"
        :placeholder="t('common.search')"
        density="compact"
        prepend-inner-icon="tabler-search"
        style="max-inline-size: 220px;"
        clearable
      />
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

      <EmptyState
        v-else-if="store.allPayments.length === 0 && !isLoading"
        icon="tabler-cash-off"
        :title="t('platformBilling.noPayments')"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="filteredPayments"
        :loading="isLoading"
        :items-per-page="store.allPaymentsPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <RouterLink
            v-if="item.company?.id"
            :to="{ path: `/platform/companies/${item.company.id}`, query: { tab: 'billing' } }"
            class="font-weight-medium text-high-emphasis text-decoration-none"
          >
            {{ item.company.name }}
          </RouterLink>
          <span
            v-else
            class="font-weight-medium"
          >—</span>
        </template>

        <template #item.amount="{ item }">
          <span class="font-weight-medium">
            {{ formatMoney(item.amount, { currency: item.currency }) }}
          </span>
        </template>

        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="payment"
          >
            {{ statusLabel(item.status) }}
          </StatusChip>
        </template>

        <template #item.provider="{ item }">
          {{ item.provider || '—' }}
        </template>

        <template #item.method="{ item }">
          <span v-if="item.payment_method_brand || item.payment_method_last4">
            {{ item.payment_method_brand || '' }} •••• {{ item.payment_method_last4 || '' }}
          </span>
          <span
            v-else
            class="text-disabled"
          >—</span>
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
