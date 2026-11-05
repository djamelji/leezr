<script setup>
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'
import { useDsnStore } from '@/modules/company/workforce/dsn.store'
import PayrollRunPicker from '@/components/workforce/PayrollRunPicker.vue'

definePage({ meta: { module: 'workforce_payroll', permission: 'workforce.payroll_prepare' } })

// Embeddable in the "Paie & déclarations" space (hides its own page header).
defineProps({ embedded: { type: Boolean, default: false } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const router = useRouter()
const store = useDsnStore()

// Honest gateway capability — the UI must never present a simulated submit/accept
// as an official filing (ADR-681).
const capability = computed(() => store.capability)
const realTransmission = computed(() => capability.value?.real_transmission === true)
const capabilityBanner = computed(() => {
  const mode = capability.value?.mode
  const map = {
    disabled: { type: 'warning', text: t('dsn.capability.disabled') },
    simulation: { type: 'warning', text: t('dsn.capability.simulation') },
    file: { type: 'info', text: t('dsn.capability.file') },
    sandbox: { type: 'info', text: t('dsn.capability.sandbox') },
    production: { type: 'success', text: t('dsn.capability.production') },
  }
  return map[mode] ?? map.disabled
})
// Suffix transmission-related statuses with "(simulation)" unless truly transmitted.
const statusLabel = status => {
  const base = t(`dsn.statuses.${status}`)
  if (!realTransmission.value && ['submitted', 'accepted'].includes(status)) {
    return `${base} ${t('dsn.capability.simulatedSuffix')}`
  }
  return base
}

// ── Table state ──────────────────────────────────────────────
const statusFilter = ref(null)
const periodMonthFilter = ref(null)
const options = ref({ page: 1, itemsPerPage: 15 })
const loading = ref(false)

const statusOptions = [
  { title: t('dsn.statuses.draft'), value: 'draft' },
  { title: t('dsn.statuses.validated'), value: 'validated' },
  { title: t('dsn.statuses.exported'), value: 'exported' },
  { title: t('dsn.statuses.submitted'), value: 'submitted' },
  { title: t('dsn.statuses.accepted'), value: 'accepted' },
  { title: t('dsn.statuses.rejected'), value: 'rejected' },
]

const headers = computed(() => [
  { title: t('dsn.columns.periodMonth'), key: 'period_month', sortable: false },
  { title: t('dsn.columns.status'), key: 'status', sortable: false, width: 130 },
  { title: t('dsn.columns.gatewayStatus'), key: 'gateway_status', sortable: false, width: 140 },
  { title: t('dsn.columns.submittedAt'), key: 'submitted_at', sortable: false, width: 160 },
  { title: t('dsn.columns.actions'), key: 'actions', sortable: false, width: 80 },
])

// ── Data fetching ────────────────────────────────────────────
const fetchData = async () => {
  loading.value = true
  try {
    await store.fetchDeclarations({
      status: statusFilter.value || undefined,
      periodMonth: periodMonthFilter.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
    })
  }
  finally {
    loading.value = false
  }
}

const fetchStats = async () => {
  try {
    await store.fetchStatistics()
  }
  catch {
    // Statistics are optional
  }
}

watchDebounced([statusFilter, periodMonthFilter], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

onMounted(() => {
  fetchData()
  fetchStats()
  store.fetchCapability()
})

// ── Stats ────────────────────────────────────────────────────
const stats = computed(() => store.statistics)

// ── Format helpers ───────────────────────────────────────────
function formatDate(dateStr) {
  if (!dateStr)
    return '\u2014'

  return new Date(dateStr).toLocaleDateString(locale.value, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)
const exportPayrollRunId = ref(null)
const exportLoading = ref(false)

const submitExport = async () => {
  if (!exportPayrollRunId.value)
    return

  exportLoading.value = true
  try {
    const declaration = await store.exportDsn(exportPayrollRunId.value)

    toast(t('dsn.exported'), 'success')
    isDrawerOpen.value = false
    exportPayrollRunId.value = null
    router.push({ name: 'company-workforce-dsn-id', params: { id: declaration.id } })
  } catch (e) {
    toast((e?.data?.message || e?.response?._data?.message) || e.message, 'error')
  } finally {
    exportLoading.value = false
  }
}

// ── Navigation ───────────────────────────────────────────────
function goToDeclaration(id) {
  router.push({ name: 'company-workforce-dsn-id', params: { id } })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div
      v-if="!embedded"
      class="d-flex align-center justify-space-between mb-6"
    >
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('dsn.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('dsn.subtitle') }}
        </p>
      </div>
      <VBtn
        v-can="'workforce.payroll_export'"
        color="primary"
        prepend-icon="tabler-file-export"
        @click="isDrawerOpen = true"
      >
        {{ $t('dsn.exportDsn') }}
      </VBtn>
    </div>

    <!-- Honest gateway capability banner (zero ambiguity on transmission) -->
    <VAlert
      v-if="capability"
      :type="capabilityBanner.type"
      variant="tonal"
      density="comfortable"
      class="mb-6"
      border="start"
    >
      <template #prepend>
        <VIcon :icon="realTransmission ? 'tabler-cloud-check' : 'tabler-cloud-off'" />
      </template>
      <div class="text-body-2">
        <strong>{{ $t('dsn.capability.title') }}</strong> — {{ capabilityBanner.text }}
      </div>
    </VAlert>

    <!-- Export action in embedded mode (header is hidden) -->
    <div
      v-if="embedded"
      class="d-flex justify-end mb-4"
    >
      <VBtn
        v-can="'workforce.payroll_export'"
        color="primary"
        prepend-icon="tabler-file-export"
        @click="isDrawerOpen = true"
      >
        {{ $t('dsn.exportDsn') }}
      </VBtn>
    </div>

    <!-- Stats Cards -->
    <VRow
      v-if="stats"
      class="card-grid card-grid-xs mb-6"
    >
      <VCol
        cols="6"
        sm="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="primary"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-file-description"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('dsn.stats.total') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.total ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        sm="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="info"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-send"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('dsn.stats.submitted') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.by_status?.submitted ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        sm="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="success"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-circle-check"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('dsn.stats.accepted') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.by_status?.accepted ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        sm="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="error"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-circle-x"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('dsn.stats.rejected') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.by_status?.rejected ?? 0 }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters + Table -->
    <VCard>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="periodMonthFilter"
              :placeholder="$t('dsn.filters.periodMonth')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="$t('dsn.filters.status')"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>

      <!-- Table -->
      <VDataTableServer
        v-model:options="options"
        :headers="headers"
        :items="store.declarations"
        :items-length="store.totalDeclarations"
        :loading="loading"
        class="text-no-wrap"
        @click:row="(_, { item }) => goToDeclaration(item.id)"
      >
        <!-- Period month -->
        <template #item.period_month="{ item }">
          <span class="font-weight-medium">
            {{ item.period_month }}
          </span>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="dsn"
          >
            {{ statusLabel(item.status) }}
          </StatusChip>
        </template>

        <!-- Gateway status -->
        <template #item.gateway_status="{ item }">
          <VChip
            v-if="item.gateway_status"
            size="small"
            variant="tonal"
            color="secondary"
          >
            {{ $t(`dsn.gatewayStatuses.${item.gateway_status}`, item.gateway_status) }}
          </VChip>
          <span
            v-else
            class="text-medium-emphasis"
          >&mdash;</span>
        </template>

        <!-- Submitted at -->
        <template #item.submitted_at="{ item }">
          {{ formatDate(item.submitted_at) }}
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <VBtn
            icon
            variant="text"
            size="small"
            :title="$t('dsn.actions.view')"
            @click.stop="goToDeclaration(item.id)"
          >
            <VIcon icon="tabler-chevron-right" />
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-file-description"
              size="48"
              color="secondary"
              class="mb-4"
            />
            <h6 class="text-h6 mb-2">
              {{ $t('dsn.emptyState.title') }}
            </h6>
            <p class="text-body-1 text-medium-emphasis mb-4">
              {{ $t('dsn.emptyState.description') }}
            </p>
            <VBtn
              v-can="'workforce.payroll_export'"
              color="primary"
              prepend-icon="tabler-file-export"
              @click="isDrawerOpen = true"
            >
              {{ $t('dsn.exportDsn') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Export DSN Drawer -->
    <VNavigationDrawer class="wf-scroll-drawer"
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('dsn.exportDsn') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="submitExport">
          <VRow>
            <VCol cols="12">
              <PayrollRunPicker
                v-model="exportPayrollRunId"
                :label="$t('dsn.fields.selectRun')"
                status-filter="validated"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  v-can="'workforce.payroll_export'"
                  type="submit"
                  color="primary"
                  :loading="exportLoading"
                  :disabled="!exportPayrollRunId"
                >
                  {{ $t('dsn.exportDsn') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>
  </div>
</template>
