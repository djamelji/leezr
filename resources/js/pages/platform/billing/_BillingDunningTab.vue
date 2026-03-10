<script setup>
import StatusChip from '@/core/components/StatusChip.vue'
import EmptyState from '@/core/components/EmptyState.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const search = ref('')
const actionLoading = ref({})

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.invoiceNumber'), key: 'number' },
  { title: t('platformBilling.status'), key: 'status', width: '140px' },
  { title: t('platformBilling.amountDue'), key: 'amount_due', align: 'end' },
  { title: t('platformBilling.dueAt'), key: 'due_at' },
  { title: t('platformBilling.retryCount'), key: 'retry_count', align: 'center', width: '100px' },
  { title: t('platformBilling.nextRetry'), key: 'next_retry_at' },
  { title: t('platformBilling.actions'), key: 'actions', sortable: false, align: 'center', width: '160px' },
])

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

const filteredDunning = computed(() => {
  if (!search.value) return store.dunningInvoices
  const q = search.value.toLowerCase()

  return store.dunningInvoices.filter(d =>
    d.company?.name?.toLowerCase().includes(q)
    || d.number?.toLowerCase().includes(q),
  )
})

const handleRetry = async item => {
  actionLoading.value = { ...actionLoading.value, [item.id]: 'retry' }
  try {
    await store.retryInvoicePayment(item.id, {})
    toast(t('platformBilling.toasts.retrySuccess'), 'success')
    await load(store.dunningPagination.current_page)
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    delete actionLoading.value[item.id]
  }
}

const handleEscalate = async item => {
  actionLoading.value = { ...actionLoading.value, [item.id]: 'escalate' }
  try {
    await store.forceDunningTransition(item.id, { action: 'escalate' })
    toast(t('platformBilling.toasts.escalateSuccess'), 'success')
    await load(store.dunningPagination.current_page)
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    delete actionLoading.value[item.id]
  }
}

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchDunning({ page })
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center flex-wrap gap-2">
      <VIcon
        icon="tabler-alert-triangle"
        class="me-2"
      />
      {{ t('platformBilling.tabs.dunning') }}
      <VSpacer />
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
        v-if="isLoading && store.dunningInvoices.length === 0"
        type="table"
      />

      <EmptyState
        v-else-if="store.dunningInvoices.length === 0 && !isLoading"
        icon="tabler-mood-happy"
        :title="t('platformBilling.noDunning')"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="filteredDunning"
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
          <span
            v-else
            class="font-weight-medium"
          >—</span>
        </template>

        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="invoice"
          >
            {{ statusLabel(item.status) }}
          </StatusChip>
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

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn
              size="small"
              color="primary"
              variant="tonal"
              :loading="actionLoading[item.id] === 'retry'"
              :disabled="!!actionLoading[item.id]"
              @click="handleRetry(item)"
            >
              {{ t('platformBilling.dunningRetry') }}
            </VBtn>
            <VBtn
              size="small"
              color="error"
              variant="tonal"
              :loading="actionLoading[item.id] === 'escalate'"
              :disabled="!!actionLoading[item.id]"
              @click="handleEscalate(item)"
            >
              {{ t('platformBilling.dunningEscalate') }}
            </VBtn>
          </div>
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
