<script setup>
import { useAppToast } from '@/composables/useAppToast'
import { usePayrollStore } from '@/modules/company/workforce/payroll.store'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce_payroll', permission: 'workforce.payroll_prepare' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const router = useRouter()
const store = usePayrollStore()

// ── Table state ──────────────────────────────────────────────
const statusFilter = ref(null)
const periodStartFilter = ref(null)
const periodEndFilter = ref(null)
const options = ref({ page: 1, itemsPerPage: 15 })
const loading = ref(false)

const statusOptions = [
  { title: t('payroll.statuses.draft'), value: 'draft' },
  { title: t('payroll.statuses.computed'), value: 'computed' },
  { title: t('payroll.statuses.validated'), value: 'validated' },
  { title: t('payroll.statuses.exported'), value: 'exported' },
]

const headers = computed(() => [
  { title: t('payroll.columns.period'), key: 'period', sortable: false },
  { title: t('payroll.columns.status'), key: 'status', sortable: false, width: 120 },
  { title: t('payroll.columns.employees'), key: 'employee_count', sortable: false, width: 120 },
  { title: t('payroll.columns.grossTotal'), key: 'total_gross_cents', sortable: false, width: 150 },
  { title: t('payroll.columns.anomalies'), key: 'anomaly_count', sortable: false, width: 120 },
  { title: t('payroll.columns.actions'), key: 'actions', sortable: false, width: 100 },
])

// ── Data fetching ────────────────────────────────────────────
const fetchData = async () => {
  loading.value = true
  try {
    await store.fetchRuns({
      status: statusFilter.value || undefined,
      periodStart: periodStartFilter.value || undefined,
      periodEnd: periodEndFilter.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
    })
  } finally {
    loading.value = false
  }
}

const fetchStats = async () => {
  try {
    await store.fetchStatistics()
  } catch {
    // Statistics are optional — ignore errors
  }
}

watch([statusFilter, periodStartFilter, periodEndFilter], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

onMounted(() => {
  fetchData()
  fetchStats()
})

// ── Stats ────────────────────────────────────────────────────
const stats = computed(() => store.statistics)

// ── Format helpers ───────────────────────────────────────────
const formatCents = cents => {
  if (cents == null) return '—'

  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(cents / 100)
}

const formatPeriod = run => {
  const start = new Date(run.period_start).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' })
  const end = new Date(run.period_end).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' })

  return `${start} — ${end}`
}

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)
const formData = ref({
  periodStart: '',
  periodEnd: '',
})
const formLoading = ref(false)

const resetForm = () => {
  formData.value = {
    periodStart: '',
    periodEnd: '',
  }
}

const submitCreateRun = async () => {
  formLoading.value = true
  try {
    const run = await store.createRun({
      periodStart: formData.value.periodStart,
      periodEnd: formData.value.periodEnd,
    })

    toast(t('payroll.created'), 'success')
    isDrawerOpen.value = false
    resetForm()
    router.push({ name: 'company-workforce-payroll-id', params: { id: run.id } })
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  } finally {
    formLoading.value = false
  }
}

// ── Delete confirmation ──────────────────────────────────────
const confirmDialog = ref(false)
const confirmTargetId = ref(null)

const openDeleteConfirm = item => {
  confirmTargetId.value = item.id
  confirmDialog.value = true
}

const executeDelete = async () => {
  try {
    await store.deleteRun(confirmTargetId.value)
    toast(t('payroll.deleted'), 'success')
    confirmDialog.value = false
    fetchData()
    fetchStats()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
    confirmDialog.value = false
  }
}

// ── Navigation ───────────────────────────────────────────────
const goToRun = id => {
  router.push({ name: 'company-workforce-payroll-id', params: { id } })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('payroll.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('payroll.subtitle') }}
        </p>
      </div>
      <VBtn
        v-can="'workforce.payroll_prepare'"
        color="primary"
        prepend-icon="tabler-receipt-2"
        @click="isDrawerOpen = true"
      >
        {{ $t('payroll.createRun') }}
      </VBtn>
    </div>

    <!-- Stats Cards -->
    <VRow
      v-if="stats"
      class="mb-6"
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
                icon="tabler-receipt"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('payroll.stats.totalRuns') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.total_runs ?? 0 }}
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
              color="secondary"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-file-pencil"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('payroll.stats.draft') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.by_status?.draft ?? 0 }}
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
                icon="tabler-calculator"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('payroll.stats.computed') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.by_status?.computed ?? 0 }}
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
                {{ $t('payroll.stats.validated') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.by_status?.validated ?? 0 }}
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
            md="3"
          >
            <AppDateTimePicker
              v-model="periodStartFilter"
              :placeholder="$t('payroll.filters.periodStart')"
              :config="{ dateFormat: 'Y-m-d' }"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppDateTimePicker
              v-model="periodEndFilter"
              :placeholder="$t('payroll.filters.periodEnd')"
              :config="{ dateFormat: 'Y-m-d' }"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="$t('payroll.filters.status')"
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
        :items="store.runs"
        :items-length="store.totalRuns"
        :loading="loading"
        class="text-no-wrap"
        @click:row="(_, { item }) => goToRun(item.id)"
      >
        <!-- Period -->
        <template #item.period="{ item }">
          {{ formatPeriod(item) }}
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="payrollRun"
          >
            {{ $t(`payroll.statuses.${item.status}`) }}
          </StatusChip>
        </template>

        <!-- Employees -->
        <template #item.employee_count="{ item }">
          <div class="d-flex align-center gap-2">
            <VIcon
              icon="tabler-users"
              size="18"
              color="primary"
            />
            {{ item.employee_count ?? 0 }}
          </div>
        </template>

        <!-- Gross Total -->
        <template #item.total_gross_cents="{ item }">
          {{ formatCents(item.total_gross_cents) }}
        </template>

        <!-- Anomalies -->
        <template #item.anomaly_count="{ item }">
          <VChip
            v-if="item.anomaly_count > 0"
            size="small"
            color="warning"
            variant="tonal"
          >
            <VIcon
              icon="tabler-alert-triangle"
              size="14"
              class="me-1"
            />
            {{ item.anomaly_count }}
          </VChip>
          <span
            v-else
            class="text-medium-emphasis"
          >0</span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn
              icon
              variant="text"
              size="small"
              :title="$t('payroll.actions.view')"
              @click.stop="goToRun(item.id)"
            >
              <VIcon icon="tabler-chevron-right" />
            </VBtn>
            <VBtn
              v-if="item.status === 'draft'"
              v-can="'workforce.payroll_prepare'"
              icon
              variant="text"
              size="x-small"
              color="error"
              :title="$t('payroll.actions.delete')"
              @click.stop="openDeleteConfirm(item)"
            >
              <VIcon
                icon="tabler-trash"
                size="18"
              />
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-receipt"
              size="48"
              color="secondary"
              class="mb-4"
            />
            <h6 class="text-h6 mb-2">
              {{ $t('payroll.emptyState.title') }}
            </h6>
            <p class="text-body-1 text-medium-emphasis mb-4">
              {{ $t('payroll.emptyState.description') }}
            </p>
            <VBtn
              v-can="'workforce.payroll_prepare'"
              color="primary"
              prepend-icon="tabler-receipt-2"
              @click="isDrawerOpen = true"
            >
              {{ $t('payroll.createRun') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Create Payroll Run Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('payroll.createRun') }}
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
        <VForm @submit.prevent="submitCreateRun">
          <VRow>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="formData.periodStart"
                :label="$t('payroll.fields.periodStart')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="formData.periodEnd"
                :label="$t('payroll.fields.periodEnd')"
                :config="{ dateFormat: 'Y-m-d' }"
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
                  type="submit"
                  color="primary"
                  :loading="formLoading"
                >
                  {{ $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Delete Confirm Dialog -->
    <VDialog
      v-model="confirmDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('payroll.confirm.deleteTitle') }}
        </VCardTitle>
        <VCardText>
          {{ $t('payroll.confirm.deleteText') }}
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="confirmDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="executeDelete"
          >
            {{ $t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
