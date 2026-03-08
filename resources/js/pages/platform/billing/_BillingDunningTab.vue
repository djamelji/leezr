<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.invoiceNumber'), key: 'number' },
  { title: t('platformBilling.status'), key: 'status', width: '140px' },
  { title: t('platformBilling.amountDue'), key: 'amount_due', align: 'end' },
  { title: t('platformBilling.dueAt'), key: 'due_at' },
  { title: t('platformBilling.retryCount'), key: 'retry_count', align: 'center', width: '100px' },
  { title: t('platformBilling.nextRetry'), key: 'next_retry_at' },
])

const statusColor = status => {
  return status === 'overdue' ? 'error' : 'warning'
}

const statusLabel = status => {
  const map = { overdue: 'statusOverdue', uncollectible: 'statusUncollectible' }

  return t(`platformBilling.${map[status] || status}`)
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const formatDateTime = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchDunning({ page })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-alert-triangle"
        class="me-2"
      />
      {{ t('platformBilling.tabs.dunning') }}
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.dunningInvoices.length === 0"
        type="table"
      />

      <div
        v-else-if="store.dunningInvoices.length === 0 && !isLoading"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-mood-happy"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('platformBilling.noDunning') }}
        </p>
      </div>

      <VDataTable
        v-else
        :headers="headers"
        :items="store.dunningInvoices"
        :loading="isLoading"
        :items-per-page="store.dunningPagination.per_page"
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
          <span v-else class="font-weight-medium">—</span>
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="statusColor(item.status)"
            size="small"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.amount_due="{ item }">
          <span class="text-error font-weight-medium">
            {{ formatMoney(item.amount_due, { currency: item.currency }) }}
          </span>
        </template>

        <template #item.due_at="{ item }">
          {{ formatDate(item.due_at) }}
        </template>

        <template #item.retry_count="{ item }">
          <VChip
            size="small"
            :color="item.retry_count >= 3 ? 'error' : 'warning'"
            variant="tonal"
          >
            {{ item.retry_count }}
          </VChip>
        </template>

        <template #item.next_retry_at="{ item }">
          {{ formatDateTime(item.next_retry_at) }}
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.dunningCount', { count: store.dunningPagination.total }) }}
            </span>
            <VPagination
              v-if="store.dunningPagination.last_page > 1"
              :model-value="store.dunningPagination.current_page"
              :length="store.dunningPagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
