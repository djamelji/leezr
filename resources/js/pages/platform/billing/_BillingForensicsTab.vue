<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { $platformApi } from '@/utils/platformApi'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

// ── Audit export ────────────────────────────────────────
const showExportDialog = ref(false)
const exportStartDate = ref('')
const exportEndDate = ref('')
const exportFormat = ref('csv')
const exportLoading = ref(false)

const doExport = async () => {
  if (!exportStartDate.value || !exportEndDate.value) return

  exportLoading.value = true
  try {
    const params = new URLSearchParams({
      start_date: exportStartDate.value,
      end_date: exportEndDate.value,
      format: exportFormat.value,
    })

    const response = await fetch(`/api/platform/billing/audit-export?${params}`, {
      headers: { 'Accept': exportFormat.value === 'csv' ? 'text/csv' : 'application/json' },
    })

    if (!response.ok) throw new Error('Export failed')

    const blob = await response.blob()
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `audit-export-${exportStartDate.value}.${exportFormat.value}`
    a.click()
    window.URL.revokeObjectURL(url)
    showExportDialog.value = false
  }
  finally {
    exportLoading.value = false
  }
}

// ── Company selector ───────────────────────────────────
const companyId = ref(null)
const companySearch = ref('')
const companyOptions = ref([])
const companySearchLoading = ref(false)

let searchTimeout = null

const searchCompanies = async query => {
  if (!query || query.length < 2) {
    companyOptions.value = []

    return
  }
  companySearchLoading.value = true
  try {
    const data = await $platformApi('/companies', { params: { search: query } })

    companyOptions.value = (data.data || []).map(c => ({ id: c.id, name: c.name, slug: c.slug }))
  }
  finally {
    companySearchLoading.value = false
  }
}

watch(companySearch, val => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => searchCompanies(val), 300)
})

// ── Timeline ───────────────────────────────────────────
const timelineDays = ref(30)
const entityTypeFilter = ref('')
const timelineLoading = ref(false)

const daysOptions = [
  { title: t('platformBilling.forensics.days7'), value: 7 },
  { title: t('platformBilling.forensics.days14'), value: 14 },
  { title: t('platformBilling.forensics.days30'), value: 30 },
  { title: t('platformBilling.forensics.days60'), value: 60 },
  { title: t('platformBilling.forensics.days90'), value: 90 },
]

const entityTypes = [
  { title: t('platformBilling.forensics.filterAll'), value: '' },
  { title: t('platformBilling.forensics.filterInvoice'), value: 'invoice' },
  { title: t('platformBilling.forensics.filterPayment'), value: 'payment' },
  { title: t('platformBilling.forensics.filterCreditNote'), value: 'credit_note' },
  { title: t('platformBilling.forensics.filterWallet'), value: 'wallet_transaction' },
  { title: t('platformBilling.forensics.filterSnapshot'), value: 'snapshot' },
]

const loadTimeline = async () => {
  if (!companyId.value) return
  timelineLoading.value = true
  try {
    await store.fetchTimeline({
      company_id: companyId.value,
      days: timelineDays.value,
      entity_type: entityTypeFilter.value || undefined,
    })
  }
  finally {
    timelineLoading.value = false
  }
}

// ── Snapshots ──────────────────────────────────────────
const snapshotsLoading = ref(false)
const expandedSnapshots = ref([])

const loadSnapshots = async (page = 1) => {
  if (!companyId.value) return
  snapshotsLoading.value = true
  try {
    await store.fetchSnapshots(companyId.value, page)
  }
  finally {
    snapshotsLoading.value = false
  }
}

const snapshotHeaders = computed(() => [
  { title: t('platformBilling.forensics.snapshotDate'), key: 'created_at', width: '160px' },
  { title: t('platformBilling.forensics.trigger'), key: 'trigger', width: '120px' },
  { title: t('platformBilling.forensics.driftType'), key: 'drift_type', width: '140px' },
  { title: t('platformBilling.forensics.entityType'), key: 'entity_type', width: '120px' },
  { title: t('platformBilling.forensics.entityId'), key: 'entity_id', width: '100px' },
  { title: '', key: 'data-table-expand', width: '50px' },
])

const onSnapshotPageChange = page => {
  loadSnapshots(page)
}

// ── Load on company change ─────────────────────────────
watch(companyId, val => {
  if (val) {
    loadTimeline()
    loadSnapshots()
  }
})

// ── Helpers ────────────────────────────────────────────
const formatShortDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const eventColor = type => {
  const map = {
    invoice: 'primary',
    payment: 'success',
    credit_note: 'warning',
    wallet_transaction: 'secondary',
    snapshot: 'info',
  }

  return map[type] || 'default'
}

const eventIcon = type => {
  const map = {
    invoice: 'tabler-file-invoice',
    payment: 'tabler-cash',
    credit_note: 'tabler-note',
    wallet_transaction: 'tabler-wallet',
    snapshot: 'tabler-camera',
  }

  return map[type] || 'tabler-point'
}

const actionColor = action => {
  if (!action) return 'default'
  if (action.includes('paid') || action.includes('succeeded') || action.includes('created')) return 'success'
  if (action.includes('refund') || action.includes('issued')) return 'warning'
  if (action.includes('uncollectible') || action.includes('voided') || action.includes('failed')) return 'error'
  if (action.includes('updated')) return 'info'

  return 'default'
}

const formatAmount = (amount, currency) => {
  if (amount == null) return ''
  const val = Number(amount)
  if (val === 0) return ''

  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: currency || 'EUR',
    minimumFractionDigits: 2,
  }).format(val / 100)
}
</script>

<template>
  <div>
    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      <VAlertTitle>
        <VIcon
          icon="tabler-history"
          size="20"
          class="me-2"
        />
        {{ t('platformBilling.forensics.headerTitle') }}
      </VAlertTitle>
      {{ t('platformBilling.forensics.headerDesc') }}
    </VAlert>

    <!-- Export button -->
    <div class="d-flex justify-end mb-4">
      <VBtn
        prepend-icon="tabler-download"
        variant="tonal"
        @click="showExportDialog = true"
      >
        {{ t('platformBilling.forensics.export') }}
      </VBtn>
    </div>

    <!-- Export dialog -->
    <VDialog
      v-model="showExportDialog"
      max-width="450"
    >
      <VCard :title="t('platformBilling.forensics.exportTitle')">
        <VCardText>
          <VRow>
            <VCol cols="6">
              <AppTextField
                v-model="exportStartDate"
                :label="t('platformBilling.forensics.startDate')"
                type="date"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="exportEndDate"
                :label="t('platformBilling.forensics.endDate')"
                type="date"
              />
            </VCol>
            <VCol cols="12">
              <VBtnToggle
                v-model="exportFormat"
                mandatory
                variant="outlined"
                density="compact"
              >
                <VBtn value="csv">
                  CSV
                </VBtn>
                <VBtn value="json">
                  JSON
                </VBtn>
              </VBtnToggle>
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="showExportDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="exportLoading"
            :disabled="!exportStartDate || !exportEndDate"
            @click="doExport"
          >
            {{ t('platformBilling.forensics.export') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Company selector -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppAutocomplete
              v-model="companyId"
              v-model:search="companySearch"
              :items="companyOptions"
              item-title="name"
              item-value="id"
              :label="t('platformBilling.forensics.searchCompany')"
              :placeholder="t('platformBilling.forensics.searchCompanyPlaceholder')"
              :loading="companySearchLoading"
              density="compact"
              clearable
              no-filter
            >
              <template #item="{ props: itemProps, item }">
                <VListItem v-bind="itemProps">
                  <template #subtitle>
                    {{ item.raw.slug }}
                  </template>
                </VListItem>
              </template>
            </AppAutocomplete>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ═══ Timeline ═══ -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-timeline"
          class="me-2"
        />
        {{ t('platformBilling.forensics.timeline') }}
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.forensics.selectCompanyFirst') }}
        </div>
        <template v-else>
          <!-- Filters -->
          <VRow class="mb-4">
            <VCol
              cols="12"
              md="3"
            >
              <AppSelect
                v-model="timelineDays"
                :label="t('platformBilling.forensics.period')"
                :items="daysOptions"
                density="compact"
                @update:model-value="loadTimeline"
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
            >
              <AppSelect
                v-model="entityTypeFilter"
                :label="t('platformBilling.forensics.entityType')"
                :items="entityTypes"
                density="compact"
                clearable
                @update:model-value="loadTimeline"
              />
            </VCol>
            <VCol
              cols="12"
              md="3"
              class="d-flex align-end"
            >
              <VBtn
                variant="tonal"
                color="primary"
                :loading="timelineLoading"
                @click="loadTimeline"
              >
                <VIcon
                  icon="tabler-refresh"
                  class="me-1"
                />
                {{ t('platformBilling.forensics.refresh') }}
              </VBtn>
            </VCol>
          </VRow>

          <!-- Timeline vertical -->
          <div
            v-if="store.timeline.length === 0 && !timelineLoading"
            class="text-center pa-6 text-disabled"
          >
            <VIcon
              icon="tabler-clock-off"
              size="32"
              class="mb-2"
            />
            <p class="text-body-1 mb-0">
              {{ t('platformBilling.forensics.noEvents') }}
            </p>
          </div>

          <VTimeline
            v-else
            density="compact"
            side="end"
            truncate-line="both"
          >
            <VTimelineItem
              v-for="(event, idx) in store.timeline"
              :key="idx"
              :dot-color="eventColor(event.entity_type)"
              size="small"
            >
              <template #icon>
                <VIcon
                  :icon="eventIcon(event.entity_type)"
                  size="14"
                  color="white"
                />
              </template>
              <VCard
                variant="tonal"
                density="compact"
              >
                <VCardText class="pa-3">
                  <div class="d-flex align-center gap-2 mb-1">
                    <VChip
                      :color="eventColor(event.entity_type)"
                      size="x-small"
                    >
                      {{ event.entity_type }}
                    </VChip>
                    <VChip
                      :color="actionColor(event.action)"
                      size="x-small"
                      variant="outlined"
                    >
                      {{ event.action }}
                    </VChip>
                    <span class="text-caption text-disabled ms-auto">
                      {{ formatShortDate(event.timestamp) }}
                    </span>
                  </div>
                  <div class="d-flex gap-3 text-caption text-disabled">
                    <span v-if="event.entity_id">ID: {{ event.entity_id }}</span>
                    <span v-if="event.amount">{{ formatAmount(event.amount, event.currency) }}</span>
                  </div>
                </VCardText>
              </VCard>
            </VTimelineItem>
          </VTimeline>

          <VProgressLinear
            v-if="timelineLoading"
            indeterminate
            class="mt-4"
          />
        </template>
      </VCardText>
    </VCard>

    <!-- ═══ Snapshots ═══ -->
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-camera"
          class="me-2"
        />
        {{ t('platformBilling.forensics.snapshots') }}
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.forensics.selectCompanyFirst') }}
        </div>
        <template v-else>
          <VDataTable
            v-model:expanded="expandedSnapshots"
            :headers="snapshotHeaders"
            :items="store.snapshots"
            :loading="snapshotsLoading"
            density="compact"
            :items-per-page="store.snapshotsPagination.per_page"
            show-expand
            item-value="id"
          >
            <template #item.created_at="{ item }">
              {{ formatDate(item.created_at) }}
            </template>
            <template #item.trigger="{ item }">
              <VChip
                color="info"
                size="small"
              >
                {{ item.trigger }}
              </VChip>
            </template>
            <template #item.drift_type="{ item }">
              <VChip
                v-if="item.drift_type"
                color="warning"
                size="small"
                variant="outlined"
              >
                {{ item.drift_type }}
              </VChip>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </template>
            <template #item.entity_type="{ item }">
              <VChip
                :color="eventColor(item.entity_type)"
                size="small"
              >
                {{ item.entity_type }}
              </VChip>
            </template>
            <template #expanded-row="{ columns, item }">
              <tr>
                <td :colspan="columns.length">
                  <div class="pa-4">
                    <h6 class="text-subtitle-2 mb-2">
                      {{ t('platformBilling.forensics.snapshotData') }}
                    </h6>
                    <pre class="text-caption bg-surface pa-3 rounded" style="max-height: 200px; overflow: auto;">{{ JSON.stringify(item.snapshot_data || {}, null, 2) }}</pre>
                    <div
                      v-if="item.correlation_id"
                      class="mt-2"
                    >
                      <span class="text-subtitle-2">{{ t('platformBilling.forensics.correlationId') }}:</span>
                      <code class="ms-2 text-caption">{{ item.correlation_id }}</code>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <template #bottom>
              <VDivider />
              <div class="d-flex align-center justify-space-between pa-4">
                <span class="text-body-2 text-disabled">
                  {{ t('platformBilling.forensics.totalSnapshots', { total: store.snapshotsPagination.total }) }}
                </span>
                <VPagination
                  v-if="store.snapshotsPagination.last_page > 1"
                  :model-value="store.snapshotsPagination.current_page"
                  :length="store.snapshotsPagination.last_page"
                  :total-visible="5"
                  density="compact"
                  @update:model-value="onSnapshotPageChange"
                />
              </div>
            </template>
          </VDataTable>
        </template>
      </VCardText>
    </VCard>
  </div>
</template>
