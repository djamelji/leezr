<script setup>
import { useTimesheetsStore } from '@/modules/company/workforce/timesheets.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.timesheet_view' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const router = useRouter()
const store = useTimesheetsStore()
const employeesStore = useEmployeesStore()

// ── Table state ──────────────────────────────────────────────
const statusFilter = ref(null)
const periodStart = ref(null)
const periodEnd = ref(null)
const options = ref({ page: 1, itemsPerPage: 15 })
const loading = ref(false)

const statusOptions = [
  { title: t('workforce.statuses.timesheet.draft'), value: 'draft' },
  { title: t('workforce.statuses.timesheet.submitted'), value: 'submitted' },
  { title: t('workforce.statuses.timesheet.approved'), value: 'approved' },
  { title: t('workforce.statuses.timesheet.rejected'), value: 'rejected' },
  { title: t('workforce.statuses.timesheet.locked'), value: 'locked' },
]

const headers = computed(() => [
  { title: t('timesheets.columns.employee'), key: 'employee', sortable: false },
  { title: t('timesheets.columns.period'), key: 'period', sortable: false },
  { title: t('timesheets.columns.status'), key: 'status', sortable: false, width: 120 },
  { title: t('timesheets.columns.worked'), key: 'total_worked_minutes', sortable: false, width: 120 },
  { title: t('timesheets.columns.overtime'), key: 'total_overtime_minutes', sortable: false, width: 120 },
  { title: t('timesheets.columns.anomalies'), key: 'anomalies_count', sortable: false, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 60 },
])

// ── Data fetching ────────────────────────────────────────────
const fetchData = async () => {
  loading.value = true
  try {
    await store.fetchTimesheets({
      periodStart: periodStart.value || undefined,
      periodEnd: periodEnd.value || undefined,
      status: statusFilter.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
    })
  } finally {
    loading.value = false
  }
}

const fetchStats = async () => {
  try {
    await store.fetchStatistics({
      periodStart: periodStart.value || undefined,
      periodEnd: periodEnd.value || undefined,
    })
  } catch {
    // Statistics are optional — ignore errors
  }
}

watch([statusFilter, periodStart, periodEnd], () => {
  options.value.page = 1
  fetchData()
  fetchStats()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

onMounted(() => {
  fetchData()
  fetchStats()
  employeesStore.fetchEmployees({ perPage: 100 })
})

// ── Format helpers ───────────────────────────────────────────
const formatHours = minutes => {
  if (minutes == null) return '0.0h'

  return `${(minutes / 60).toFixed(1)}h`
}

const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '-'

// ── Stats ────────────────────────────────────────────────────
const stats = computed(() => store.statistics)

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)
const formData = ref({
  employeeId: null,
  periodStart: '',
  periodEnd: '',
})
const formLoading = ref(false)

const resetForm = () => {
  formData.value = {
    employeeId: null,
    periodStart: '',
    periodEnd: '',
  }
}

const employeeItems = computed(() => {
  return employeesStore.employees.map(e => ({
    title: `${e.first_name} ${e.last_name}`,
    value: e.id,
  }))
})

const submitGenerate = async () => {
  formLoading.value = true
  try {
    await store.generateTimesheet({
      employeeId: formData.value.employeeId,
      periodStart: formData.value.periodStart,
      periodEnd: formData.value.periodEnd,
    })
    toast(t('timesheets.generated'), 'success')
    isDrawerOpen.value = false
    resetForm()
    fetchData()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    formLoading.value = false
  }
}

// ── Navigation ───────────────────────────────────────────────
const goToTimesheet = id => {
  router.push({ name: 'company-workforce-time-id', params: { id } })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('workforce.modules.time.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('workforce.modules.time.description') }}
        </p>
      </div>
      <VBtn
        v-can="'workforce.time_manage'"
        color="primary"
        prepend-icon="tabler-clock-plus"
        @click="isDrawerOpen = true"
      >
        {{ $t('timesheets.generateTimesheet') }}
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
            >
              <VIcon icon="tabler-file-analytics" />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timesheets.stats.totalPeriods') }}
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
              color="warning"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-clock-pause" />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timesheets.stats.pending') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ (stats.by_status?.draft ?? 0) + (stats.by_status?.submitted ?? 0) }}
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
            >
              <VIcon icon="tabler-check" />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timesheets.stats.approved') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ (stats.by_status?.approved ?? 0) + (stats.by_status?.locked ?? 0) }}
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
            >
              <VIcon icon="tabler-percentage" />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timesheets.stats.completionRate') }}
              </div>
              <div class="text-h5 font-weight-bold">
                {{ stats.completion_rate != null ? `${stats.completion_rate}%` : '-' }}
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
              v-model="periodStart"
              :placeholder="$t('timesheets.filters.periodStart')"
              :config="{ dateFormat: 'Y-m' }"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppDateTimePicker
              v-model="periodEnd"
              :placeholder="$t('timesheets.filters.periodEnd')"
              :config="{ dateFormat: 'Y-m' }"
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
              :placeholder="$t('timesheets.filters.status')"
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
        :items="store.periods"
        :items-length="store.totalPeriods"
        :loading="loading"
        class="text-no-wrap"
        @click:row="(_, { item }) => goToTimesheet(item.id)"
      >
        <!-- Employee -->
        <template #item.employee="{ item }">
          <div class="d-flex align-center gap-3 py-2">
            <VAvatar
              color="primary"
              variant="tonal"
              size="38"
            >
              <span class="text-sm font-weight-medium">
                {{ (item.employee?.first_name?.[0] || '') + (item.employee?.last_name?.[0] || '') }}
              </span>
            </VAvatar>
            <div class="font-weight-medium">
              {{ item.employee?.first_name }} {{ item.employee?.last_name }}
            </div>
          </div>
        </template>

        <!-- Period -->
        <template #item.period="{ item }">
          {{ formatDate(item.period_start) }} — {{ formatDate(item.period_end) }}
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="timesheet"
          >
            {{ $t(`workforce.statuses.timesheet.${item.status}`) }}
          </StatusChip>
        </template>

        <!-- Worked hours -->
        <template #item.total_worked_minutes="{ item }">
          {{ formatHours(item.total_worked_minutes) }}
        </template>

        <!-- Overtime -->
        <template #item.total_overtime_minutes="{ item }">
          <span :class="item.total_overtime_minutes > 0 ? 'text-warning' : ''">
            {{ formatHours(item.total_overtime_minutes) }}
          </span>
        </template>

        <!-- Anomalies -->
        <template #item.anomalies_count="{ item }">
          <VChip
            v-if="item.anomalies_count > 0"
            size="small"
            color="error"
            variant="tonal"
          >
            {{ item.anomalies_count }}
          </VChip>
          <span
            v-else
            class="text-medium-emphasis"
          >0</span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <VBtn
            icon
            variant="text"
            size="small"
            @click.stop="goToTimesheet(item.id)"
          >
            <VIcon icon="tabler-chevron-right" />
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-clock"
              size="48"
              color="secondary"
              class="mb-4"
            />
            <h6 class="text-h6 mb-2">
              {{ $t('timesheets.emptyState.title') }}
            </h6>
            <p class="text-body-1 text-medium-emphasis mb-4">
              {{ $t('timesheets.emptyState.description') }}
            </p>
            <VBtn
              v-can="'workforce.time_manage'"
              color="primary"
              prepend-icon="tabler-clock-plus"
              @click="isDrawerOpen = true"
            >
              {{ $t('timesheets.generateTimesheet') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Generate Timesheet Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('timesheets.generateTimesheet') }}
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
        <VForm @submit.prevent="submitGenerate">
          <VRow>
            <VCol cols="12">
              <AppSelect
                v-model="formData.employeeId"
                :items="employeeItems"
                :label="$t('timesheets.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="formData.periodStart"
                :label="$t('timesheets.fields.periodStart')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="formData.periodEnd"
                :label="$t('timesheets.fields.periodEnd')"
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
                  {{ $t('timesheets.generate') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>
  </div>
</template>
