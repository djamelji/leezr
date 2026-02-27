<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.creditNoteNumber'), key: 'number' },
  { title: t('platformBilling.status'), key: 'status', width: '120px' },
  { title: t('platformBilling.creditNoteAmount'), key: 'amount', align: 'end' },
  { title: t('platformBilling.creditNoteReason'), key: 'reason' },
  { title: t('platformBilling.creditNoteIssuedAt'), key: 'issued_at' },
  { title: t('platformBilling.creditNoteAppliedAt'), key: 'applied_at' },
])

const statusOptions = computed(() => [
  { title: t('platformBilling.filterAll'), value: '' },
  { title: t('platformBilling.creditNoteStatusDraft'), value: 'draft' },
  { title: t('platformBilling.creditNoteStatusIssued'), value: 'issued' },
  { title: t('platformBilling.creditNoteStatusApplied'), value: 'applied' },
])

const statusColor = status => {
  const colors = { draft: 'secondary', issued: 'info', applied: 'success' }

  return colors[status] || 'secondary'
}

const statusLabel = status => {
  const map = { draft: 'creditNoteStatusDraft', issued: 'creditNoteStatusIssued', applied: 'creditNoteStatusApplied' }

  return t(`platformBilling.${map[status] || status}`)
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllCreditNotes({
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
        icon="tabler-receipt-refund"
        class="me-2"
      />
      {{ t('platformBilling.tabs.creditNotes') }}
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
        v-if="isLoading && store.allCreditNotes.length === 0"
        type="table"
      />

      <div
        v-else-if="store.allCreditNotes.length === 0 && !isLoading"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-receipt-refund"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('platformBilling.noCreditNotes') }}
        </p>
      </div>

      <VDataTable
        v-else
        :headers="headers"
        :items="store.allCreditNotes"
        :loading="isLoading"
        :items-per-page="store.allCreditNotesPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <span class="font-weight-medium">
            {{ item.company?.name || '—' }}
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

        <template #item.amount="{ item }">
          {{ formatMoney(item.amount, { currency: item.currency }) }}
        </template>

        <template #item.issued_at="{ item }">
          {{ formatDate(item.issued_at) }}
        </template>

        <template #item.applied_at="{ item }">
          {{ formatDate(item.applied_at) }}
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.creditNoteCount', { count: store.allCreditNotesPagination.total }) }}
            </span>
            <VPagination
              v-if="store.allCreditNotesPagination.last_page > 1"
              :model-value="store.allCreditNotesPagination.current_page"
              :length="store.allCreditNotesPagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
