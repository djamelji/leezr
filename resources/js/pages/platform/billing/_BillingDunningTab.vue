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
const dunningStatusFilter = ref('')
const actionLoading = ref({})

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.invoiceNumber'), key: 'number' },
  { title: t('platformBilling.status'), key: 'status', width: '140px' },
  { title: t('platformBilling.amountDue'), key: 'amount_due', align: 'end' },
  { title: t('platformBilling.dueAt'), key: 'due_at' },
  { title: t('platformBilling.dunning.daysOverdue'), key: 'days_overdue', width: '100px', align: 'center' },
  { title: t('platformBilling.retryCount'), key: 'retry_count', align: 'center', width: '100px' },
  { title: t('platformBilling.nextRetry'), key: 'next_retry_at' },
  { title: t('platformBilling.actions'), key: 'actions', sortable: false, align: 'center', width: '200px' },
])

const dunningStatusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.dunning.filterOverdue'), value: 'overdue' },
  { title: t('platformBilling.dunning.filterUncollectible'), value: 'uncollectible' },
])

const statusLabel = status => {
  const map = { overdue: 'statusOverdue', uncollectible: 'statusUncollectible' }

  return t(`platformBilling.${map[status] || status}`)
}

const daysOverdue = dueAt => {
  if (!dueAt) return 0
  const diff = Date.now() - new Date(dueAt).getTime()

  return Math.max(0, Math.floor(diff / (1000 * 60 * 60 * 24)))
}

const dunningStats = computed(() => {
  const invoices = store.dunningInvoices || []
  const total = invoices.reduce((sum, inv) => sum + (inv.amount_due || 0), 0)

  return {
    count: invoices.length,
    totalAtRisk: total,
    currency: invoices[0]?.currency || 'EUR',
  }
})

const filteredInvoices = computed(() => {
  let items = store.dunningInvoices || []
  if (dunningStatusFilter.value)
    items = items.filter(i => i.status === dunningStatusFilter.value)

  if (search.value) {
    const q = search.value.toLowerCase()

    items = items.filter(i =>
      i.company?.name?.toLowerCase().includes(q)
      || i.number?.toLowerCase().includes(q),
    )
  }

  return items
})

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

const handleRetry = async item => {
  actionLoading.value = { ...actionLoading.value, [item.id]: 'retry' }
  try {
    await store.retryInvoicePayment(item.id, {
      idempotency_key: `ui-retry-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    })
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
    await store.forceDunningTransition(item.id, {
      target_status: 'uncollectible',
      idempotency_key: `ui-escalate-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    })
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

// Write-off dialog
const writeOffTarget = ref(null)
const isWriteOffDialogOpen = ref(false)

const confirmWriteOff = item => {
  writeOffTarget.value = item
  isWriteOffDialogOpen.value = true
}

const handleWriteOff = async () => {
  const item = writeOffTarget.value
  if (!item) return

  isWriteOffDialogOpen.value = false
  actionLoading.value = { ...actionLoading.value, [item.id]: 'writeoff' }
  try {
    await store.writeOffInvoice(item.id, {
      idempotency_key: `ui-writeoff-${Date.now()}-${Math.random().toString(36).slice(2)}`,
    })
    toast(t('platformBilling.toasts.writeOffSuccess'), 'success')
    await load(store.dunningPagination.current_page)
  }
  catch {
    toast(t('platformBilling.errorGeneric'), 'error')
  }
  finally {
    delete actionLoading.value[item.id]
    writeOffTarget.value = null
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
  <VAlert
    type="info"
    variant="tonal"
    density="compact"
    class="mb-4"
  >
    <VAlertTitle>
      <VIcon
        icon="tabler-alert-triangle"
        size="20"
        class="me-2"
      />
      {{ t('platformBilling.dunning.headerTitle') }}
    </VAlertTitle>
    {{ t('platformBilling.dunning.headerDesc') }}
  </VAlert>

  <!-- Summary card — total at risk -->
  <VCard
    v-if="dunningStats.count > 0"
    class="mb-4"
    variant="tonal"
    color="error"
  >
    <VCardText class="d-flex align-center gap-4 py-3">
      <VAvatar
        color="error"
        variant="tonal"
        size="42"
        rounded
      >
        <VIcon icon="tabler-alert-triangle" />
      </VAvatar>
      <div>
        <div class="text-h6">
          {{ formatMoney(dunningStats.totalAtRisk, { currency: dunningStats.currency }) }}
        </div>
        <div class="text-body-2">
          {{ t('platformBilling.dunning.totalAtRisk') }} · {{ t('platformBilling.dunning.invoiceCount', { count: dunningStats.count }) }}
        </div>
      </div>
    </VCardText>
  </VCard>

  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-alert-triangle"
        class="me-2"
      />
      {{ t('platformBilling.tabs.dunning') }}
    </VCardTitle>

    <VCardText class="pb-0">
      <VRow>
        <VCol
          cols="12"
          md="3"
        >
          <AppSelect
            v-model="dunningStatusFilter"
            :items="dunningStatusOptions"
            :label="t('platformBilling.status')"
            density="compact"
          />
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <AppTextField
            v-model="search"
            :label="t('common.search')"
            density="compact"
            prepend-inner-icon="tabler-search"
            clearable
          />
        </VCol>
      </VRow>
    </VCardText>

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
        :items="filteredInvoices"
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

        <template #item.days_overdue="{ item }">
          <VChip
            :color="daysOverdue(item.due_at) > 30 ? 'error' : 'warning'"
            size="small"
          >
            {{ daysOverdue(item.due_at) }}j
          </VChip>
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
              v-if="item.status === 'overdue'"
              size="x-small"
              color="primary"
              variant="tonal"
              icon="tabler-refresh"
              :loading="actionLoading[item.id] === 'retry'"
              :disabled="!!actionLoading[item.id]"
              @click="handleRetry(item)"
            />
            <VBtn
              v-if="item.status === 'overdue'"
              size="x-small"
              color="warning"
              variant="tonal"
              icon="tabler-arrow-up"
              :loading="actionLoading[item.id] === 'escalate'"
              :disabled="!!actionLoading[item.id]"
              @click="handleEscalate(item)"
            />
            <VBtn
              v-if="item.status === 'overdue'"
              size="x-small"
              color="error"
              variant="text"
              icon="tabler-file-off"
              :disabled="!!actionLoading[item.id]"
              @click="confirmWriteOff(item)"
            />
            <VBtn
              v-if="item.company?.id"
              size="x-small"
              variant="text"
              icon="tabler-building"
              :to="{ path: `/platform/companies/${item.company.id}`, query: { tab: 'billing' } }"
            />
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

  <!-- Write-off Confirm Dialog -->
  <VDialog
    v-model="isWriteOffDialogOpen"
    max-width="460"
  >
    <VCard>
      <VCardTitle class="text-h5 pa-5">
        {{ t('platformBilling.dunning.writeOffTitle') }}
      </VCardTitle>
      <VCardText>
        {{ t('platformBilling.dunning.writeOffConfirm') }}
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          variant="text"
          @click="isWriteOffDialogOpen = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          variant="elevated"
          @click="handleWriteOff"
        >
          {{ t('platformBilling.dunning.writeOff') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
