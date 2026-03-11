<script setup>
import StatusChip from '@/core/components/StatusChip.vue'
import EmptyState from '@/core/components/EmptyState.vue'
import { formatMoney } from '@/utils/money'
import { $api } from '@/utils/api'

const { t } = useI18n()
const { toast } = useAppToast()

const isLoading = ref(true)
const items = ref([])
const pagination = ref({ current_page: 1, per_page: 25, total: 0, last_page: 1 })
const statusFilter = ref(null)
const search = ref('')

const headers = computed(() => [
  { title: t('platformBilling.scheduledDebits.company'), key: 'company', sortable: false },
  { title: t('platformBilling.scheduledDebits.amount'), key: 'amount', align: 'end' },
  { title: t('platformBilling.scheduledDebits.debitDate'), key: 'debit_date' },
  { title: t('platformBilling.scheduledDebits.paymentMethod'), key: 'payment_profile', sortable: false },
  { title: t('platformBilling.scheduledDebits.invoice'), key: 'invoice', sortable: false },
  { title: t('platformBilling.scheduledDebits.status'), key: 'status', width: '130px' },
])

const statusOptions = [
  { title: 'Pending', value: 'pending' },
  { title: 'Processing', value: 'processing' },
  { title: 'Collected', value: 'collected' },
  { title: 'Failed', value: 'failed' },
  { title: 'Cancelled', value: 'cancelled' },
]

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const filteredItems = computed(() => {
  if (!search.value) return items.value
  const q = search.value.toLowerCase()

  return items.value.filter(d =>
    d.company?.name?.toLowerCase().includes(q)
    || d.invoice?.number?.toLowerCase().includes(q),
  )
})

const load = async (page = 1) => {
  isLoading.value = true
  try {
    const params = new URLSearchParams({ page, per_page: 25 })

    if (statusFilter.value)
      params.append('status', statusFilter.value)

    const data = await $api(`/platform/billing/scheduled-debits?${params}`)

    items.value = data.data
    pagination.value = {
      current_page: data.current_page,
      per_page: data.per_page,
      total: data.total,
      last_page: data.last_page,
    }
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

watch(statusFilter, () => load())

onMounted(() => load())
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center flex-wrap gap-2">
      <VIcon
        icon="tabler-calendar-event"
        class="me-2"
      />
      {{ t('platformBilling.tabs.scheduledDebits') }}
      <VSpacer />
      <AppSelect
        v-model="statusFilter"
        :items="statusOptions"
        :label="t('platformBilling.status')"
        density="compact"
        clearable
        style="max-inline-size: 160px;"
      />
      <AppTextField
        v-model="search"
        :placeholder="t('common.search')"
        density="compact"
        prepend-inner-icon="tabler-search"
        style="max-inline-size: 220px;"
        clearable
      />
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && items.length === 0"
        type="table"
      />

      <EmptyState
        v-else-if="items.length === 0 && !isLoading"
        icon="tabler-calendar-off"
        :title="t('platformBilling.scheduledDebits.empty')"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="filteredItems"
        :loading="isLoading"
        :items-per-page="pagination.per_page"
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

        <template #item.debit_date="{ item }">
          {{ formatDate(item.debit_date) }}
        </template>

        <template #item.payment_profile="{ item }">
          <template v-if="item.payment_profile">
            <VIcon
              :icon="item.payment_profile.method_key === 'sepa_debit' ? 'tabler-building-bank' : 'tabler-credit-card'"
              size="16"
              class="me-1"
            />
            {{ item.payment_profile.label || item.payment_profile.method_key }}
          </template>
          <span v-else>—</span>
        </template>

        <template #item.invoice="{ item }">
          <span v-if="item.invoice?.number">{{ item.invoice.number }}</span>
          <span v-else>—</span>
        </template>

        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="scheduledDebit"
          >
            {{ item.status }}
          </StatusChip>
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.scheduledDebits.count', { count: pagination.total }) }}
            </span>
            <VPagination
              v-if="pagination.last_page > 1"
              :model-value="pagination.current_page"
              :length="pagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
