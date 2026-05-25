<script setup>
import { useLeavesStore } from '@/modules/company/workforce/leaves.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce_leave', permission: 'workforce.leave_request' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = useLeavesStore()
const employeesStore = useEmployeesStore()

// ── Tab navigation ───────────────────────────────────────────
const activeTab = ref('requests')

// ── Shared helpers ───────────────────────────────────────────
const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '\u2014'

const formatDuration = hundredths => {
  if (!hundredths && hundredths !== 0) return '\u2014'
  const days = (hundredths / 100).toFixed(2)

  return `${days} j`
}

const getLeaveTypeColor = leaveType => {
  if (!leaveType) return 'primary'
  const colors = {
    conges_payes: 'success',
    rtt: 'info',
    maladie: 'error',
    sans_solde: 'warning',
    maternite: 'primary',
    paternite: 'primary',
    formation: 'secondary',
  }

  return colors[leaveType.key] || colors[leaveType.code] || 'primary'
}

const employeeItems = computed(() =>
  employeesStore.employees.map(e => ({
    title: `${e.first_name} ${e.last_name}`,
    value: e.id,
  })),
)

const leaveTypeItems = computed(() =>
  store.leaveTypes.map(lt => ({
    title: lt.name,
    value: lt.id,
  })),
)

// ══════════════════════════════════════════════════════════════
// TAB 1 — DEMANDES (Requests)
// ══════════════════════════════════════════════════════════════
const search = ref('')
const statusFilter = ref(null)
const options = ref({ page: 1, itemsPerPage: 15, sortBy: [{ key: 'date_from', order: 'desc' }] })
const loading = ref(false)

const statusOptions = [
  { title: t('workforce.statuses.leave.pending'), value: 'pending' },
  { title: t('workforce.statuses.leave.approved'), value: 'approved' },
  { title: t('workforce.statuses.leave.rejected'), value: 'rejected' },
  { title: t('workforce.statuses.leave.consumed'), value: 'consumed' },
  { title: t('workforce.statuses.leave.cancelled'), value: 'cancelled' },
]

const headers = computed(() => [
  { title: t('leaves.columns.employee'), key: 'employee', sortable: false },
  { title: t('leaves.columns.type'), key: 'leave_type', sortable: false, width: 160 },
  { title: t('leaves.columns.dates'), key: 'dates', sortable: true, width: 200 },
  { title: t('leaves.columns.duration'), key: 'days_count_hundredths', sortable: true, width: 100 },
  { title: t('leaves.columns.status'), key: 'status', sortable: true, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 120 },
])

const fetchData = async () => {
  loading.value = true
  try {
    await store.fetchLeaveRequests({
      status: statusFilter.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
    })
  } finally {
    loading.value = false
  }
}

watch([search, statusFilter], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

// ── Stats ────────────────────────────────────────────────────
const pendingCount = computed(() => store.statistics?.pending ?? 0)
const approvedCount = computed(() => store.statistics?.approved ?? 0)
const rejectedCount = computed(() => store.statistics?.rejected ?? 0)

// ── Drawer (create request) ─────────────────────────────────
const isDrawerOpen = ref(false)
const formData = ref({
  employee_id: null,
  leave_type_id: null,
  date_from: '',
  date_to: '',
  days_count_hundredths: null,
  reason: '',
})
const formLoading = ref(false)

const resetForm = () => {
  formData.value = {
    employee_id: null,
    leave_type_id: null,
    date_from: '',
    date_to: '',
    days_count_hundredths: null,
    reason: '',
  }
}

const submitLeaveRequest = async () => {
  formLoading.value = true
  try {
    await store.createLeaveRequest({
      employeeId: formData.value.employee_id,
      leaveTypeId: formData.value.leave_type_id,
      dateFrom: formData.value.date_from,
      dateTo: formData.value.date_to,
      daysCountHundredths: formData.value.days_count_hundredths,
      reason: formData.value.reason,
    })
    toast(t('leaves.created'), 'success')
    isDrawerOpen.value = false
    resetForm()
    fetchData()
    store.fetchStatistics()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    formLoading.value = false
  }
}

// ── Approve / Reject / Cancel ────────────────────────────────
const confirmDialog = ref(false)
const confirmAction = ref(null)
const confirmTargetId = ref(null)
const confirmTitle = ref('')
const confirmText = ref('')

const openConfirm = (action, item) => {
  confirmAction.value = action
  confirmTargetId.value = item.id
  if (action === 'approve') {
    confirmTitle.value = t('leaves.confirm.approveTitle')
    confirmText.value = t('leaves.confirm.approveText', { name: item.employee?.first_name })
  } else if (action === 'reject') {
    confirmTitle.value = t('leaves.confirm.rejectTitle')
    confirmText.value = t('leaves.confirm.rejectText', { name: item.employee?.first_name })
  } else if (action === 'cancel') {
    confirmTitle.value = t('leaves.confirm.cancelTitle')
    confirmText.value = t('leaves.confirm.cancelText')
  }
  confirmDialog.value = true
}

const executeConfirmAction = async () => {
  try {
    if (confirmAction.value === 'approve') {
      await store.approveLeaveRequest(confirmTargetId.value)
      toast(t('leaves.approved'), 'success')
    } else if (confirmAction.value === 'reject') {
      await store.rejectLeaveRequest(confirmTargetId.value)
      toast(t('leaves.rejected'), 'success')
    } else if (confirmAction.value === 'cancel') {
      await store.cancelLeaveRequest(confirmTargetId.value)
      toast(t('leaves.cancelled'), 'success')
    }
    confirmDialog.value = false
    fetchData()
    store.fetchStatistics()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    confirmDialog.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// TAB 2 — CALENDRIER (Calendar)
// ══════════════════════════════════════════════════════════════
const calendarMonth = ref(new Date())
const calendarLoading = ref(false)

const calendarMonthLabel = computed(() => {
  const d = calendarMonth.value

  return d.toLocaleDateString(locale.value, { month: 'long', year: 'numeric' })
})

const calendarFrom = computed(() => {
  const d = new Date(calendarMonth.value.getFullYear(), calendarMonth.value.getMonth(), 1)

  return d.toISOString().slice(0, 10)
})

const calendarTo = computed(() => {
  const d = new Date(calendarMonth.value.getFullYear(), calendarMonth.value.getMonth() + 1, 0)

  return d.toISOString().slice(0, 10)
})

const calendarHeaders = computed(() => [
  { title: t('leaves.columns.employee'), key: 'employee', sortable: false },
  { title: t('leaves.columns.type'), key: 'leave_type', sortable: false, width: 160 },
  { title: t('leaves.calendar.from'), key: 'date_from', sortable: true, width: 130 },
  { title: t('leaves.calendar.to'), key: 'date_to', sortable: true, width: 130 },
  { title: t('leaves.columns.duration'), key: 'days_count_hundredths', sortable: false, width: 100 },
  { title: t('leaves.columns.status'), key: 'status', sortable: false, width: 120 },
])

const fetchCalendar = async () => {
  calendarLoading.value = true
  try {
    await store.fetchCalendar({ from: calendarFrom.value, to: calendarTo.value })
  } finally {
    calendarLoading.value = false
  }
}

const prevMonth = () => {
  const d = new Date(calendarMonth.value)
  d.setMonth(d.getMonth() - 1)
  calendarMonth.value = d
}

const nextMonth = () => {
  const d = new Date(calendarMonth.value)
  d.setMonth(d.getMonth() + 1)
  calendarMonth.value = d
}

watch(calendarMonth, () => fetchCalendar())

// ══════════════════════════════════════════════════════════════
// TAB 3 — SOLDES (Balances)
// ══════════════════════════════════════════════════════════════
const balancesEmployee = ref(null)
const balancesYear = ref(new Date().getFullYear())
const balancesLoading = ref(false)

const yearOptions = computed(() => {
  const currentYear = new Date().getFullYear()

  return [
    { title: String(currentYear - 1), value: currentYear - 1 },
    { title: String(currentYear), value: currentYear },
    { title: String(currentYear + 1), value: currentYear + 1 },
  ]
})

const balancesHeaders = computed(() => [
  { title: t('leaves.balances.leaveType'), key: 'leave_type', sortable: false },
  { title: t('leaves.balances.accrued'), key: 'accrued', sortable: false, width: 120 },
  { title: t('leaves.balances.consumed'), key: 'consumed', sortable: false, width: 120 },
  { title: t('leaves.balances.pending'), key: 'pending', sortable: false, width: 120 },
  { title: t('leaves.balances.available'), key: 'available', sortable: false, width: 140 },
])

const fetchBalances = async () => {
  if (!balancesEmployee.value) return
  balancesLoading.value = true
  try {
    await store.fetchBalances(balancesEmployee.value, { year: balancesYear.value })
  } finally {
    balancesLoading.value = false
  }
}

watch([balancesEmployee, balancesYear], () => {
  if (balancesEmployee.value) fetchBalances()
})

// ══════════════════════════════════════════════════════════════
// TAB 4 — CONFIGURATION (Leave Types)
// ══════════════════════════════════════════════════════════════
const configLoading = ref(false)
const configOptions = ref({ page: 1, itemsPerPage: 15 })

const accrualModeOptions = [
  { title: t('leaves.config.accrualModes.monthly'), value: 'monthly' },
  { title: t('leaves.config.accrualModes.annual'), value: 'annual' },
  { title: t('leaves.config.accrualModes.none'), value: 'none' },
  { title: t('leaves.config.accrualModes.custom'), value: 'custom' },
]

const configHeaders = computed(() => [
  { title: t('leaves.config.columns.code'), key: 'code', sortable: false, width: 120 },
  { title: t('leaves.config.columns.name'), key: 'name', sortable: false },
  { title: t('leaves.config.columns.accrualMode'), key: 'accrual_mode', sortable: false, width: 150 },
  { title: t('leaves.config.columns.annualDays'), key: 'annual_entitlement_hundredths', sortable: false, width: 130 },
  { title: t('leaves.config.columns.requiresApproval'), key: 'requires_approval', sortable: false, width: 130 },
  { title: t('leaves.config.columns.isPaid'), key: 'is_paid', sortable: false, width: 100 },
  { title: t('leaves.config.columns.active'), key: 'is_active', sortable: false, width: 100 },
  { title: '', key: 'actions', sortable: false, width: 100 },
])

const fetchAllLeaveTypes = async () => {
  configLoading.value = true
  try {
    await store.fetchLeaveTypes({ all: true, force: true })
  } finally {
    configLoading.value = false
  }
}

// ── Leave Type Drawer (create/edit) ─────────────────────────
const isTypeDrawerOpen = ref(false)
const editingType = ref(null)
const typeFormLoading = ref(false)
const typeFormData = ref({
  code: '',
  name: '',
  description: '',
  accrual_mode: 'monthly',
  annual_entitlement_days: null,
  max_balance_hundredths: null,
  carry_over_hundredths: null,
  requires_approval: true,
  is_paid: true,
  sort_order: 0,
})

const isEditMode = computed(() => !!editingType.value)

const resetTypeForm = () => {
  editingType.value = null
  typeFormData.value = {
    code: '',
    name: '',
    description: '',
    accrual_mode: 'monthly',
    annual_entitlement_days: null,
    max_balance_hundredths: null,
    carry_over_hundredths: null,
    requires_approval: true,
    is_paid: true,
    sort_order: 0,
  }
}

const openCreateType = () => {
  resetTypeForm()
  isTypeDrawerOpen.value = true
}

const openEditType = leaveType => {
  editingType.value = leaveType
  typeFormData.value = {
    code: leaveType.code || '',
    name: leaveType.name || '',
    description: leaveType.description || '',
    accrual_mode: leaveType.accrual_mode || 'monthly',
    annual_entitlement_days: leaveType.annual_entitlement_hundredths != null
      ? (leaveType.annual_entitlement_hundredths / 100)
      : null,
    max_balance_hundredths: leaveType.max_balance_hundredths ?? null,
    carry_over_hundredths: leaveType.carry_over_hundredths ?? null,
    requires_approval: leaveType.requires_approval ?? true,
    is_paid: leaveType.is_paid ?? true,
    sort_order: leaveType.sort_order ?? 0,
  }
  isTypeDrawerOpen.value = true
}

const submitLeaveType = async () => {
  typeFormLoading.value = true
  try {
    const payload = {
      code: typeFormData.value.code,
      name: typeFormData.value.name,
      description: typeFormData.value.description || null,
      accrual_mode: typeFormData.value.accrual_mode,
      annual_entitlement_hundredths: typeFormData.value.annual_entitlement_days != null
        ? Math.round(typeFormData.value.annual_entitlement_days * 100)
        : null,
      max_balance_hundredths: typeFormData.value.max_balance_hundredths,
      carry_over_hundredths: typeFormData.value.carry_over_hundredths,
      requires_approval: typeFormData.value.requires_approval,
      is_paid: typeFormData.value.is_paid,
      sort_order: typeFormData.value.sort_order,
    }

    if (isEditMode.value) {
      await store.updateLeaveType(editingType.value.id, payload)
      toast(t('leaves.config.updated'), 'success')
    } else {
      await store.createLeaveType(payload)
      toast(t('leaves.config.created'), 'success')
    }
    isTypeDrawerOpen.value = false
    resetTypeForm()
    fetchAllLeaveTypes()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    typeFormLoading.value = false
  }
}

// ── Delete leave type ────────────────────────────────────────
const deleteTypeDialog = ref(false)
const deleteTypeTarget = ref(null)

const openDeleteType = leaveType => {
  deleteTypeTarget.value = leaveType
  deleteTypeDialog.value = true
}

const confirmDeleteType = async () => {
  try {
    await store.deleteLeaveType(deleteTypeTarget.value.id)
    toast(t('leaves.config.deleted'), 'success')
    deleteTypeDialog.value = false
    deleteTypeTarget.value = null
    fetchAllLeaveTypes()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    deleteTypeDialog.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
onMounted(async () => {
  await Promise.all([
    fetchData(),
    store.fetchLeaveTypes(),
    store.fetchStatistics(),
    employeesStore.fetchEmployees({ perPage: 200 }),
  ])
})

// Lazy-load tab data on first visit
const calendarLoaded = ref(false)
const configLoaded = ref(false)

watch(activeTab, tab => {
  if (tab === 'calendar' && !calendarLoaded.value) {
    calendarLoaded.value = true
    fetchCalendar()
  } else if (tab === 'config' && !configLoaded.value) {
    configLoaded.value = true
    fetchAllLeaveTypes()
  }
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('leaves.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('leaves.subtitle') }}
        </p>
      </div>
      <VBtn
        v-if="activeTab === 'requests'"
        v-can="'workforce.leave_request'"
        color="primary"
        prepend-icon="tabler-calendar-plus"
        @click="isDrawerOpen = true"
      >
        {{ $t('leaves.newRequest') }}
      </VBtn>
      <VBtn
        v-if="activeTab === 'config'"
        v-can="'workforce.leave_request'"
        color="primary"
        prepend-icon="tabler-plus"
        @click="openCreateType"
      >
        {{ $t('leaves.config.newType') }}
      </VBtn>
    </div>

    <!-- Tabs -->
    <VTabs
      v-model="activeTab"
      class="mb-6"
    >
      <VTab value="requests">
        <VIcon
          icon="tabler-list"
          class="me-2"
        />
        {{ $t('leaves.tabs.requests') }}
      </VTab>
      <VTab value="calendar">
        <VIcon
          icon="tabler-calendar"
          class="me-2"
        />
        {{ $t('leaves.tabs.calendar') }}
      </VTab>
      <VTab value="balances">
        <VIcon
          icon="tabler-chart-bar"
          class="me-2"
        />
        {{ $t('leaves.tabs.balances') }}
      </VTab>
      <VTab value="config">
        <VIcon
          icon="tabler-settings"
          class="me-2"
        />
        {{ $t('leaves.tabs.config') }}
      </VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: DEMANDES                                          -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="requests">
        <!-- Stats Row -->
        <VRow class="mb-6">
          <VCol
            cols="12"
            md="4"
          >
            <VCard
              variant="outlined"
              class="text-center pa-4"
            >
              <VIcon
                icon="tabler-clock"
                size="32"
                color="warning"
                class="mb-2"
              />
              <div class="text-h5 font-weight-bold">
                {{ pendingCount }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('leaves.stats.pending') }}
              </div>
            </VCard>
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <VCard
              variant="outlined"
              class="text-center pa-4"
            >
              <VIcon
                icon="tabler-check"
                size="32"
                color="success"
                class="mb-2"
              />
              <div class="text-h5 font-weight-bold">
                {{ approvedCount }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('leaves.stats.approved') }}
              </div>
            </VCard>
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <VCard
              variant="outlined"
              class="text-center pa-4"
            >
              <VIcon
                icon="tabler-x"
                size="32"
                color="error"
                class="mb-2"
              />
              <div class="text-h5 font-weight-bold">
                {{ rejectedCount }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('leaves.stats.rejected') }}
              </div>
            </VCard>
          </VCol>
        </VRow>

        <!-- Filters + Table -->
        <VCard>
          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="search"
                  :placeholder="$t('leaves.searchPlaceholder')"
                  prepend-inner-icon="tabler-search"
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
                  :placeholder="$t('leaves.filterStatus')"
                  clearable
                  density="compact"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDataTableServer
            v-model:options="options"
            :headers="headers"
            :items="store.leaveRequests"
            :items-length="store.totalRequests"
            :loading="loading"
            class="text-no-wrap"
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

            <!-- Leave Type -->
            <template #item.leave_type="{ item }">
              <VChip
                :color="getLeaveTypeColor(item.leave_type)"
                size="small"
                variant="tonal"
              >
                {{ item.leave_type?.name || '\u2014' }}
              </VChip>
            </template>

            <!-- Dates -->
            <template #item.dates="{ item }">
              {{ formatDate(item.date_from) }} &rarr; {{ formatDate(item.date_to) }}
            </template>

            <!-- Duration -->
            <template #item.days_count_hundredths="{ item }">
              {{ formatDuration(item.days_count_hundredths) }}
            </template>

            <!-- Status -->
            <template #item.status="{ item }">
              <StatusChip
                :status="item.status"
                domain="leave"
              >
                {{ $t(`workforce.statuses.leave.${item.status}`) }}
              </StatusChip>
            </template>

            <!-- Actions -->
            <template #item.actions="{ item }">
              <div class="d-flex gap-1">
                <VBtn
                  v-if="item.status === 'pending'"
                  v-can="'workforce.leave_approve'"
                  icon
                  variant="text"
                  size="x-small"
                  color="success"
                  :title="$t('leaves.actions.approve')"
                  @click.stop="openConfirm('approve', item)"
                >
                  <VIcon
                    icon="tabler-check"
                    size="18"
                  />
                </VBtn>
                <VBtn
                  v-if="item.status === 'pending'"
                  v-can="'workforce.leave_approve'"
                  icon
                  variant="text"
                  size="x-small"
                  color="error"
                  :title="$t('leaves.actions.reject')"
                  @click.stop="openConfirm('reject', item)"
                >
                  <VIcon
                    icon="tabler-x"
                    size="18"
                  />
                </VBtn>
                <VBtn
                  v-if="item.status === 'pending' || item.status === 'approved'"
                  icon
                  variant="text"
                  size="x-small"
                  color="secondary"
                  :title="$t('leaves.actions.cancel')"
                  @click.stop="openConfirm('cancel', item)"
                >
                  <VIcon
                    icon="tabler-ban"
                    size="18"
                  />
                </VBtn>
              </div>
            </template>

            <!-- Empty state -->
            <template #no-data>
              <div class="text-center pa-8">
                <VIcon
                  icon="tabler-beach"
                  size="48"
                  color="success"
                  class="mb-4"
                />
                <h6 class="text-h6 mb-2">
                  {{ $t('workforce.emptyStates.leave.title') }}
                </h6>
                <p class="text-body-1 text-medium-emphasis mb-4">
                  {{ $t('workforce.emptyStates.leave.description') }}
                </p>
                <VBtn
                  v-can="'workforce.leave_request'"
                  color="primary"
                  prepend-icon="tabler-calendar-plus"
                  @click="isDrawerOpen = true"
                >
                  {{ $t('leaves.newRequest') }}
                </VBtn>
              </div>
            </template>
          </VDataTableServer>
        </VCard>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: CALENDRIER                                        -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="calendar">
        <VCard>
          <!-- Month navigation -->
          <VCardText>
            <div class="d-flex align-center justify-center gap-4">
              <VBtn
                icon
                variant="text"
                size="small"
                @click="prevMonth"
              >
                <VIcon icon="tabler-chevron-left" />
              </VBtn>
              <h5 class="text-h5 font-weight-medium text-capitalize">
                {{ calendarMonthLabel }}
              </h5>
              <VBtn
                icon
                variant="text"
                size="small"
                @click="nextMonth"
              >
                <VIcon icon="tabler-chevron-right" />
              </VBtn>
            </div>
          </VCardText>

          <VDivider />

          <!-- Calendar table -->
          <VDataTableServer
            :headers="calendarHeaders"
            :items="store.calendar"
            :items-length="store.calendar.length"
            :loading="calendarLoading"
            :items-per-page="-1"
            class="text-no-wrap"
            hide-default-footer
          >
            <!-- Employee -->
            <template #item.employee="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <VAvatar
                  color="primary"
                  variant="tonal"
                  size="32"
                >
                  <span class="text-xs font-weight-medium">
                    {{ (item.employee?.first_name?.[0] || '') + (item.employee?.last_name?.[0] || '') }}
                  </span>
                </VAvatar>
                <span class="font-weight-medium">
                  {{ item.employee?.first_name }} {{ item.employee?.last_name }}
                </span>
              </div>
            </template>

            <!-- Leave Type -->
            <template #item.leave_type="{ item }">
              <VChip
                :color="getLeaveTypeColor(item.leave_type)"
                size="small"
                variant="tonal"
              >
                {{ item.leave_type?.name || '\u2014' }}
              </VChip>
            </template>

            <!-- From -->
            <template #item.date_from="{ item }">
              {{ formatDate(item.date_from) }}
            </template>

            <!-- To -->
            <template #item.date_to="{ item }">
              {{ formatDate(item.date_to) }}
            </template>

            <!-- Duration -->
            <template #item.days_count_hundredths="{ item }">
              {{ formatDuration(item.days_count_hundredths) }}
            </template>

            <!-- Status -->
            <template #item.status="{ item }">
              <StatusChip
                :status="item.status"
                domain="leave"
              >
                {{ $t(`workforce.statuses.leave.${item.status}`) }}
              </StatusChip>
            </template>

            <!-- Empty state -->
            <template #no-data>
              <div class="text-center pa-8">
                <VIcon
                  icon="tabler-calendar-off"
                  size="48"
                  color="secondary"
                  class="mb-4"
                />
                <h6 class="text-h6 mb-2">
                  {{ $t('leaves.calendar.empty') }}
                </h6>
                <p class="text-body-1 text-medium-emphasis">
                  {{ $t('leaves.calendar.emptyDescription') }}
                </p>
              </div>
            </template>
          </VDataTableServer>
        </VCard>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: SOLDES                                            -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="balances">
        <VCard>
          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppAutocomplete
                  v-model="balancesEmployee"
                  :items="employeeItems"
                  :label="$t('leaves.balances.selectEmployee')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol
                cols="12"
                md="3"
              >
                <AppSelect
                  v-model="balancesYear"
                  :items="yearOptions"
                  :label="$t('leaves.balances.year')"
                  density="compact"
                />
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <template v-if="balancesEmployee">
            <VDataTableServer
              :headers="balancesHeaders"
              :items="store.balances"
              :items-length="store.balances.length"
              :loading="balancesLoading"
              :items-per-page="-1"
              class="text-no-wrap"
              hide-default-footer
            >
              <!-- Leave Type -->
              <template #item.leave_type="{ item }">
                <VChip
                  :color="getLeaveTypeColor(item.leave_type || item)"
                  size="small"
                  variant="tonal"
                >
                  {{ item.leave_type?.name || item.name || '\u2014' }}
                </VChip>
              </template>

              <!-- Accrued -->
              <template #item.accrued="{ item }">
                <span class="font-weight-medium">
                  {{ formatDuration(item.accrued_hundredths ?? item.accrued) }}
                </span>
              </template>

              <!-- Consumed -->
              <template #item.consumed="{ item }">
                <span class="text-error">
                  {{ formatDuration(item.consumed_hundredths ?? item.consumed) }}
                </span>
              </template>

              <!-- Pending -->
              <template #item.pending="{ item }">
                <span class="text-warning">
                  {{ formatDuration(item.pending_hundredths ?? item.pending) }}
                </span>
              </template>

              <!-- Available -->
              <template #item.available="{ item }">
                <VChip
                  :color="(item.available_hundredths ?? item.available ?? 0) > 0 ? 'success' : 'error'"
                  size="small"
                  variant="tonal"
                >
                  {{ formatDuration(item.available_hundredths ?? item.available) }}
                </VChip>
              </template>

              <!-- Empty state -->
              <template #no-data>
                <div class="text-center pa-8">
                  <VIcon
                    icon="tabler-chart-bar-off"
                    size="48"
                    color="secondary"
                    class="mb-4"
                  />
                  <h6 class="text-h6 mb-2">
                    {{ $t('leaves.balances.empty') }}
                  </h6>
                  <p class="text-body-1 text-medium-emphasis">
                    {{ $t('leaves.balances.emptyDescription') }}
                  </p>
                </div>
              </template>
            </VDataTableServer>
          </template>

          <template v-else>
            <div class="text-center pa-8">
              <VIcon
                icon="tabler-user-search"
                size="48"
                color="secondary"
                class="mb-4"
              />
              <h6 class="text-h6 mb-2">
                {{ $t('leaves.balances.selectPrompt') }}
              </h6>
              <p class="text-body-1 text-medium-emphasis">
                {{ $t('leaves.balances.selectPromptDescription') }}
              </p>
            </div>
          </template>
        </VCard>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: CONFIGURATION                                     -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="config">
        <VCard>
          <VDataTableServer
            v-model:options="configOptions"
            :headers="configHeaders"
            :items="store.allLeaveTypes"
            :items-length="store.allLeaveTypes.length"
            :loading="configLoading"
            class="text-no-wrap"
          >
            <!-- Code -->
            <template #item.code="{ item }">
              <span class="font-weight-medium text-primary">
                {{ item.code }}
              </span>
            </template>

            <!-- Name -->
            <template #item.name="{ item }">
              <div>
                <span class="font-weight-medium">{{ item.name }}</span>
                <div
                  v-if="item.description"
                  class="text-body-2 text-medium-emphasis"
                >
                  {{ item.description }}
                </div>
              </div>
            </template>

            <!-- Accrual Mode -->
            <template #item.accrual_mode="{ item }">
              <VChip
                size="small"
                variant="tonal"
                color="info"
              >
                {{ $t(`leaves.config.accrualModes.${item.accrual_mode}`) }}
              </VChip>
            </template>

            <!-- Annual Days -->
            <template #item.annual_entitlement_hundredths="{ item }">
              {{ item.annual_entitlement_hundredths != null ? formatDuration(item.annual_entitlement_hundredths) : '\u2014' }}
            </template>

            <!-- Requires Approval -->
            <template #item.requires_approval="{ item }">
              <VIcon
                :icon="item.requires_approval ? 'tabler-check' : 'tabler-x'"
                :color="item.requires_approval ? 'success' : 'secondary'"
                size="20"
              />
            </template>

            <!-- Is Paid -->
            <template #item.is_paid="{ item }">
              <VIcon
                :icon="item.is_paid ? 'tabler-check' : 'tabler-x'"
                :color="item.is_paid ? 'success' : 'secondary'"
                size="20"
              />
            </template>

            <!-- Active -->
            <template #item.is_active="{ item }">
              <VChip
                :color="item.is_active !== false ? 'success' : 'error'"
                size="small"
                variant="tonal"
              >
                {{ item.is_active !== false ? $t('common.active') : $t('common.inactive') }}
              </VChip>
            </template>

            <!-- Actions -->
            <template #item.actions="{ item }">
              <div class="d-flex gap-1">
                <VBtn
                  icon
                  variant="text"
                  size="x-small"
                  color="primary"
                  :disabled="item.is_system"
                  @click.stop="openEditType(item)"
                >
                  <VIcon
                    icon="tabler-edit"
                    size="18"
                  />
                  <VTooltip
                    activator="parent"
                    :text="$t('common.edit')"
                    location="top"
                  />
                </VBtn>
                <VBtn
                  icon
                  variant="text"
                  size="x-small"
                  color="error"
                  :disabled="item.is_system"
                  @click.stop="openDeleteType(item)"
                >
                  <VIcon
                    icon="tabler-trash"
                    size="18"
                  />
                  <VTooltip
                    activator="parent"
                    :text="$t('common.delete')"
                    location="top"
                  />
                </VBtn>
              </div>
            </template>

            <!-- Empty state -->
            <template #no-data>
              <div class="text-center pa-8">
                <VIcon
                  icon="tabler-settings"
                  size="48"
                  color="secondary"
                  class="mb-4"
                />
                <h6 class="text-h6 mb-2">
                  {{ $t('leaves.config.empty') }}
                </h6>
                <p class="text-body-1 text-medium-emphasis mb-4">
                  {{ $t('leaves.config.emptyDescription') }}
                </p>
                <VBtn
                  color="primary"
                  prepend-icon="tabler-plus"
                  @click="openCreateType"
                >
                  {{ $t('leaves.config.newType') }}
                </VBtn>
              </div>
            </template>
          </VDataTableServer>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- DRAWERS & DIALOGS                                       -->
    <!-- ═══════════════════════════════════════════════════════ -->

    <!-- Create Leave Request Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('leaves.newRequest') }}
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
        <VForm @submit.prevent="submitLeaveRequest">
          <VRow>
            <VCol cols="12">
              <AppAutocomplete
                v-model="formData.employee_id"
                :items="employeeItems"
                :label="$t('leaves.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="formData.leave_type_id"
                :items="leaveTypeItems"
                :label="$t('leaves.fields.leaveType')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.date_from"
                :label="$t('leaves.fields.dateFrom')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.date_to"
                :label="$t('leaves.fields.dateTo')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="formData.days_count_hundredths"
                :label="$t('leaves.fields.duration')"
                :hint="$t('leaves.fields.durationHint')"
                persistent-hint
                type="number"
                :rules="[v => (v !== null && v > 0) || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.reason"
                :label="$t('leaves.fields.reason')"
                type="textarea"
                rows="3"
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

    <!-- Leave Type Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isTypeDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ isEditMode ? $t('leaves.config.editType') : $t('leaves.config.newType') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isTypeDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="submitLeaveType">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="typeFormData.code"
                :label="$t('leaves.config.fields.code')"
                :rules="[v => !!v || $t('validation.required')]"
                :disabled="isEditMode"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="typeFormData.name"
                :label="$t('leaves.config.fields.name')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="typeFormData.description"
                :label="$t('leaves.config.fields.description')"
                type="textarea"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="typeFormData.accrual_mode"
                :items="accrualModeOptions"
                :label="$t('leaves.config.fields.accrualMode')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="typeFormData.annual_entitlement_days"
                :label="$t('leaves.config.fields.annualDays')"
                :hint="$t('leaves.config.fields.annualDaysHint')"
                persistent-hint
                type="number"
                step="0.5"
                min="0"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="typeFormData.max_balance_hundredths"
                :label="$t('leaves.config.fields.maxBalance')"
                :hint="$t('leaves.config.fields.maxBalanceHint')"
                persistent-hint
                type="number"
                min="0"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="typeFormData.carry_over_hundredths"
                :label="$t('leaves.config.fields.carryOver')"
                :hint="$t('leaves.config.fields.carryOverHint')"
                persistent-hint
                type="number"
                min="0"
              />
            </VCol>
            <VCol cols="6">
              <VSwitch
                v-model="typeFormData.requires_approval"
                :label="$t('leaves.config.fields.requiresApproval')"
                color="primary"
              />
            </VCol>
            <VCol cols="6">
              <VSwitch
                v-model="typeFormData.is_paid"
                :label="$t('leaves.config.fields.isPaid')"
                color="primary"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="typeFormData.sort_order"
                :label="$t('leaves.config.fields.sortOrder')"
                type="number"
                min="0"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isTypeDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="typeFormLoading"
                >
                  {{ isEditMode ? $t('common.save') : $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Confirm Dialog (approve/reject/cancel request) -->
    <VDialog
      v-model="confirmDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ confirmTitle }}
        </VCardTitle>
        <VCardText>
          {{ confirmText }}
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
            :color="confirmAction === 'approve' ? 'success' : confirmAction === 'reject' ? 'error' : 'secondary'"
            @click="executeConfirmAction"
          >
            {{ $t('common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Leave Type Dialog -->
    <VDialog
      v-model="deleteTypeDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('leaves.config.deleteTitle') }}
        </VCardTitle>
        <VCardText>
          {{ $t('leaves.config.deleteText', { name: deleteTypeTarget?.name }) }}
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="deleteTypeDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="confirmDeleteType"
          >
            {{ $t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
