<script setup>
import StatusChip from '@/core/components/StatusChip.vue'
import EmptyState from '@/core/components/EmptyState.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const statusFilter = ref('')
const search = ref('')

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

const creditNoteStatusMap = {
  draft: 'secondary',
  issued: 'info',
  applied: 'success',
}

const statusLabel = status => {
  const map = { draft: 'creditNoteStatusDraft', issued: 'creditNoteStatusIssued', applied: 'creditNoteStatusApplied' }

  return t(`platformBilling.${map[status] || status}`)
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const filteredCreditNotes = computed(() => {
  if (!search.value) return store.allCreditNotes
  const q = search.value.toLowerCase()

  return store.allCreditNotes.filter(cn =>
    cn.company?.name?.toLowerCase().includes(q)
    || cn.number?.toLowerCase().includes(q),
  )
})

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllCreditNotes({
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
        icon="tabler-receipt-refund"
        class="me-2"
      />
      {{ t('platformBilling.tabs.creditNotes') }}
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
        v-if="isLoading && store.allCreditNotes.length === 0"
        type="table"
      />

      <EmptyState
        v-else-if="store.allCreditNotes.length === 0 && !isLoading"
        icon="tabler-receipt-refund"
        :title="t('platformBilling.noCreditNotes')"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="filteredCreditNotes"
        :loading="isLoading"
        :items-per-page="store.allCreditNotesPagination.per_page"
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
          <VChip
            :color="creditNoteStatusMap[item.status] || 'secondary'"
            size="small"
            variant="tonal"
          >
            {{ statusLabel(item.status) }}
          </VChip>
        </template>

        <template #item.amount="{ item }">
          <span class="font-weight-medium">
            {{ formatMoney(item.amount, { currency: item.currency }) }}
          </span>
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
