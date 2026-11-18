<script setup>
import { useLeavesStore } from '@/modules/company/workforce/leaves.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { formatDays, formatMinutes, daysToHundredths, hundredthsToDays } from '@/utils/workUnits'
import TimeSpaceHeader from '@/components/workforce/TimeSpaceHeader.vue'
import { useDisplay } from 'vuetify'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'
import { $api } from '@/utils/api'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'
import LeaveCalendar from './_LeaveCalendar.vue'
import LeaveRequestDetailDialog from './_LeaveRequestDetailDialog.vue'

// ADR-820 — onglet dans le chemin (/leave/{calendar|config}), nom historique conservé, ancien `?tab=` réécrit.
definePage({ name: 'company-workforce-leave', meta: { module: 'workforce_leave', permission: 'workforce.leave_request', anyPermission: ['workforce.leave_view_all', 'workforce.leave_approve'] } })

const { t, locale } = useI18n()
const { width: viewportWidth } = useDisplay()
const { toast } = useAppToast()
const store = useLeavesStore()
const employeesStore = useEmployeesStore()

// ── Tab navigation ───────────────────────────────────────────
const activeTab = ref('requests')

// ── Shared helpers ───────────────────────────────────────────
const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '\u2014'

// ADR-779 (UX4) — quantités de congés en jours lisibles (« 2,5 jours »), jamais en centièmes.
const formatDuration = hundredths => formatDays(hundredths, t, locale.value)

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

watchDebounced([search, statusFilter], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

// ── Stats ────────────────────────────────────────────────────
// Backend renvoie { [status]: { count, total_days_hundredths } } (LeaveRequestReadModel::statistics).
const pendingCount = computed(() => store.statistics?.pending?.count ?? 0)
const approvedCount = computed(() => store.statistics?.approved?.count ?? 0)
const rejectedCount = computed(() => store.statistics?.rejected?.count ?? 0)

// ── Drawer (create request) ─────────────────────────────────
const isDrawerOpen = ref(false)
const emptyForm = () => ({
  employee_id: null,
  leave_type_id: null,
  date_from: '',
  date_to: '',
  days_count_hundredths: null,
  reason: '',
  // ADR-717 — absence partielle (heures) sur une seule journée.
  partial: false,
  start_time: '',
  end_time: '',
})
const formData = ref(emptyForm())
const formLoading = ref(false)
const deductionHint = ref(null)

const resetForm = () => {
  formData.value = emptyForm()
  deductionHint.value = null
  formBalances.value = []
}

// ADR-779 (UX4) — la durée se lit et se saisit en JOURS (pas de 0,5) ; l'API garde les centièmes
// exacts. La valeur proposée vient du moteur (LeaveDeductionCalculator) ; elle reste modifiable et
// c'est la valeur transmise qui fait foi (règle ADR-717), le contrôle de solde restant serveur.
const durationDays = computed({
  get: () => hundredthsToDays(formData.value.days_count_hundredths),
  set: v => { formData.value.days_count_hundredths = daysToHundredths(v) },
})
const formBalances = ref([])
const loadFormBalances = async () => {
  formBalances.value = []
  if (!formData.value.employee_id) return
  try {
    const res = await store.fetchBalances(formData.value.employee_id)
    formBalances.value = res?.balances ?? res?.data ?? (Array.isArray(res) ? res : [])
  } catch { formBalances.value = [] }
}
const selectedBalance = computed(() => formBalances.value.find(b => b.leave_type_id === formData.value.leave_type_id) || null)
const balanceShort = computed(() => selectedBalance.value && formData.value.days_count_hundredths
  && selectedBalance.value.available < formData.value.days_count_hundredths)
// Le moteur compte les jours calendaires : on signale une période qui contient un samedi ou un dimanche.
const rangeHasWeekend = computed(() => {
  const { date_from: a, date_to: b } = formData.value
  if (!a || !b || a === b) return false
  for (let d = new Date(`${a}T00:00:00`), end = new Date(`${b}T00:00:00`), i = 0; d <= end && i < 400; d.setDate(d.getDate() + 1), i++)
    if (d.getDay() === 0 || d.getDay() === 6) return true

  return false
})
const computedLabel = computed(() => deductionHint.value
  ? t('timeSpace.leave.computed', { days: formatDays(deductionHint.value.days_count_hundredths, t, locale.value), hours: formatMinutes(Math.round(deductionHint.value.hours_count_hundredths * 0.6)) })
  : '')

// Suggestion de décompte (jours/heures) selon le contrat — ne force rien, pré-remplit seulement.
let deductionSeq = 0 // ADR-779 : seule la dernière demande de calcul fait foi
const computeDeduction = async () => {
  if (!formData.value.employee_id || !formData.value.date_from || !formData.value.date_to) return
  if (formData.value.partial && (!formData.value.start_time || !formData.value.end_time)) return
  const seq = ++deductionSeq
  try {
    const res = await store.deductionPreview({
      employeeId: formData.value.employee_id,
      dateFrom: formData.value.date_from,
      dateTo: formData.value.date_to,
      startTime: formData.value.partial ? formData.value.start_time : null,
      endTime: formData.value.partial ? formData.value.end_time : null,
    })
    if (seq !== deductionSeq) return
    formData.value.days_count_hundredths = res.days_count_hundredths
    deductionHint.value = res
  } catch { /* suggestion indisponible → saisie manuelle */ }
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
      startTime: formData.value.partial ? formData.value.start_time : null,
      endTime: formData.value.partial ? formData.value.end_time : null,
      hoursCountHundredths: formData.value.partial ? deductionHint.value?.hours_count_hundredths : null,
    })
    toast(t('leaves.created'), 'success')
    isDrawerOpen.value = false
    resetForm()
    fetchData()
    store.fetchStatistics()
  } catch (error) {
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
const confirmLoading = ref(false)

const impactedShifts = ref([])
const confirmItem = ref(null)
const openConfirm = async (action, item) => {
  confirmItem.value = item
  confirmAction.value = action
  confirmTargetId.value = item.id
  impactedShifts.value = []
  if (action === 'approve') {
    confirmTitle.value = t('leaves.confirm.approveTitle')
    confirmText.value = t('leaves.confirm.approveText', { name: item.employee?.first_name })
    // §7 — montrer au décideur les shifts publiés impactés AVANT d'approuver (aucun bannissement auto).
    try { impactedShifts.value = (await store.fetchImpactedShifts(item.id)).shifts ?? [] } catch { impactedShifts.value = [] }
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
  confirmLoading.value = true
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
    confirmDialog.value = false
  } finally {
    confirmLoading.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// TAB 2 — CALENDRIER (W-G3 : vrai calendrier visuel, scope + filtres serveur)
// ══════════════════════════════════════════════════════════════
const calendarRef = ref()
const calDepartment = ref(null)
const calLeaveType = ref(null)
const calEmployee = ref(null)
const calStatuses = ref(['pending', 'approved', 'consumed'])

const calendarFilters = computed(() => ({
  departmentId: calDepartment.value || undefined,
  leaveTypeId: calLeaveType.value || undefined,
  employeeId: calEmployee.value || undefined,
  statuses: calStatuses.value?.length ? calStatuses.value : undefined,
}))

const calStatusOptions = [
  { title: t('workforce.statuses.leave.pending'), value: 'pending' },
  { title: t('workforce.statuses.leave.approved'), value: 'approved' },
  { title: t('workforce.statuses.leave.consumed'), value: 'consumed' },
  { title: t('workforce.statuses.leave.rejected'), value: 'rejected' },
  { title: t('workforce.statuses.leave.cancelled'), value: 'cancelled' },
]
const calDepartmentOptions = computed(() => employeesStore.departments.map(d => ({ title: d.name, value: d.id })))
const calLeaveTypeOptions = computed(() => store.leaveTypes.map(lt => ({ title: lt.name, value: lt.id })))

const selectedRequest = ref(null)
const detailOpen = ref(false)
const weekStartDay = ref(null) // W-G4B : préférence Company (premier jour de semaine)
const onSelectEvent = ev => { selectedRequest.value = ev; detailOpen.value = true }
const onCalendarUpdated = () => calendarRef.value?.refetch()
const resetCalFilters = () => { calDepartment.value = null; calLeaveType.value = null; calEmployee.value = null }

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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
    deleteTypeDialog.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
const route = useRoute()
const router = useRouter()

onMounted(async () => {
  // W-G3 : deep-link depuis la fiche collaborateur (?tab=calendar&employee=<id>).
  if (route.params.tab || route.query.tab) activeTab.value = String(route.params.tab || route.query.tab)
  if (route.query.employee) calEmployee.value = Number(route.query.employee)

  await Promise.all([
    fetchData(),
    store.fetchLeaveTypes(),
    store.fetchStatistics(),
    employeesStore.fetchEmployees({ perPage: 200 }),
  ])
})

// Lazy-load tab data on first visit
const calendarLoaded = ref(false)
// ADR-779 (UX4) — plafond et report saisis en JOURS (l'API garde des centièmes exacts).
const typeMoreOpen = ref(false)
const maxBalanceDays = computed({
  get: () => hundredthsToDays(typeFormData.value.max_balance_hundredths),
  set: v => { typeFormData.value.max_balance_hundredths = daysToHundredths(v) },
})
const carryOverDays = computed({
  get: () => hundredthsToDays(typeFormData.value.carry_over_hundredths),
  set: v => { typeFormData.value.carry_over_hundredths = daysToHundredths(v) },
})
const configLoaded = ref(false)

watch(activeTab, tab => {
  const { tab: _, ...query } = route.query
  const param = tab === 'requests' ? undefined : tab
  if (route.params.tab !== param || route.query.tab !== undefined) router.replace({ name: 'company-workforce-leave', params: { tab: param }, query })
  if (tab === 'calendar' && !calendarLoaded.value) {
    calendarLoaded.value = true
    // Options de filtre (le calendrier se charge lui-même via datesSet, scope serveur).
    employeesStore.fetchDepartments()
    store.fetchLeaveTypes({ all: true })
    $api('/workforce/config/week-start').then(r => { weekStartDay.value = r.week_start_day }).catch(() => {})
  } else if (tab === 'config' && !configLoaded.value) {
    configLoaded.value = true
    fetchAllLeaveTypes()
  }
})
</script>

<template>
  <div>
    <SettingsReturnLink /> <!-- ADR-781 -->
    <!-- ADR-779 (UX4) — en-tête commun « Planning & temps » (vue Absences) -->
    <TimeSpaceHeader
      active="leave"
      :subtitle="$t('leaves.subtitle')"
    >
      <template #actions>
      <VBtn
        v-if="activeTab === 'requests'"
        v-can="'workforce.leave_request'"
        color="primary"
        prepend-icon="tabler-calendar-plus"
        data-test="leave-new"
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
      </template>
    </TimeSpaceHeader>

    <!-- Tabs -->
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill mb-6"
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
        <!-- ADR-822 — indicateurs compacts et CLIQUABLES : un clic filtre la liste (la file « à traiter »
             compte toutes les demandes en attente ; approuvées / refusées = année en cours). -->
        <VCard
          class="mb-6"
          data-test="leave-stats"
        >
          <VRow
            no-gutters
            class="text-center"
          >
            <VCol
              v-for="st in [{ key: 'pending', n: pendingCount, color: 'warning', icon: 'tabler-hourglass' }, { key: 'approved', n: approvedCount, color: 'success', icon: 'tabler-check' }, { key: 'rejected', n: rejectedCount, color: 'error', icon: 'tabler-x' }]"
              :key="st.key"
              cols="4"
              class="border-e"
            >
              <VBtn
                variant="text"
                block
                height="auto"
                class="py-4 rounded-0"
                :color="statusFilter === st.key ? st.color : undefined"
                :active="statusFilter === st.key"
                :data-test="`leave-stat-${st.key}`"
                @click="statusFilter = statusFilter === st.key ? null : st.key"
              >
                <div class="d-flex flex-column flex-sm-row align-center gap-2">
                  <VAvatar
                    :color="st.color"
                    variant="tonal"
                    rounded
                    size="36"
                  >
                    <VIcon :icon="st.icon" />
                  </VAvatar>
                  <div class="text-sm-start">
                    <div class="text-h5 font-weight-medium">
                      {{ st.n }}
                    </div>
                    <div class="text-caption text-medium-emphasis text-none">
                      {{ $t(`leaves.stats.${st.key}`) }}<span v-if="st.key !== 'pending'"> · {{ new Date().getFullYear() }}</span>
                    </div>
                  </div>
                </div>
              </VBtn>
            </VCol>
          </VRow>
        </VCard>

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
                  :aria-label="$t('leaves.actions.approve')"
                  :data-test="`leave-approve-${item.id}`"
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
                  :aria-label="$t('leaves.actions.reject')"
                  :data-test="`leave-reject-${item.id}`"
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
                  :aria-label="$t('leaves.actions.cancel')"
                  :data-test="`leave-cancel-${item.id}`"
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
          <!-- Filtres (serveur) + l\u00e9gende -->
          <VCardText>
            <VRow>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="calDepartment"
                  :items="calDepartmentOptions"
                  :placeholder="$t('employees.filters.department')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="calLeaveType"
                  :items="calLeaveTypeOptions"
                  :placeholder="$t('leaves.columns.type')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <EmployeePicker
                  v-model="calEmployee"
                  :label="$t('employees.filters.manager') ? $t('leaves.columns.employee') : ''"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="calStatuses"
                  :items="calStatusOptions"
                  :placeholder="$t('leaves.columns.status')"
                  multiple
                  chips
                  closable-chips
                  density="compact"
                />
              </VCol>
            </VRow>
            <!-- L\u00e9gende statuts -->
            <div class="d-flex gap-4 flex-wrap mt-1 text-caption align-center">
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="background:#ff9f43" />{{ $t('workforce.statuses.leave.pending') }}</span>
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="background:#28c76f" />{{ $t('workforce.statuses.leave.approved') }}</span>
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="background:#00cfe8" />{{ $t('workforce.statuses.leave.consumed') }}</span>
              <VSpacer />
              <VBtn
                variant="text"
                size="small"
                prepend-icon="tabler-filter-off"
                @click="resetCalFilters"
              >
                {{ $t('employees.filters.reset') }}
              </VBtn>
            </div>
          </VCardText>

          <VDivider />

          <VCardText>
            <LeaveCalendar
              ref="calendarRef"
              :filters="calendarFilters"
              :first-day="weekStartDay"
              @select="onSelectEvent"
            />
          </VCardText>
        </VCard>

        <LeaveRequestDetailDialog
          v-model="detailOpen"
          :request="selectedRequest"
          :all-events="store.calendar"
          @updated="onCalendarUpdated"
        />
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
    <AppDrawer
      v-model="isDrawerOpen"
      :title="$t('leaves.newRequest')"
    >
        <VForm @submit.prevent="submitLeaveRequest">
          <VRow>
            <VCol cols="12">
              <AppAutocomplete
                v-model="formData.employee_id"
                :items="employeeItems"
                :label="$t('leaves.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
                data-test="leave-employee"
                @update:model-value="computeDeduction(); loadFormBalances()"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="formData.leave_type_id"
                :items="leaveTypeItems"
                :label="$t('leaves.fields.leaveType')"
                :rules="[v => !!v || $t('validation.required')]"
                data-test="leave-type"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="formData.date_from"
                data-test="leave-from"
                :label="$t('leaves.fields.dateFrom')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
                @update:model-value="computeDeduction"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="formData.date_to"
                data-test="leave-to"
                :label="$t('leaves.fields.dateTo')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
                @update:model-value="computeDeduction"
              />
            </VCol>
            <!-- ADR-717 — absence partielle (quelques heures / demi-journée) sur une seule journée -->
            <VCol cols="12">
              <VSwitch
                v-model="formData.partial"
                :label="$t('leaves.fields.partial')"
                color="primary"
                density="compact"
                hide-details
                @update:model-value="computeDeduction"
              />
            </VCol>
            <template v-if="formData.partial">
              <VCol cols="6">
                <AppTextField
                  v-model="formData.start_time"
                  :label="$t('leaves.fields.startTime')"
                  type="time"
                  @update:model-value="computeDeduction"
                />
              </VCol>
              <VCol cols="6">
                <AppTextField
                  v-model="formData.end_time"
                  :label="$t('leaves.fields.endTime')"
                  type="time"
                  @update:model-value="computeDeduction"
                />
              </VCol>
            </template>
            <VCol cols="12">
              <AppTextField
                v-model.number="durationDays"
                :label="$t('leaves.fields.duration')"
                :hint="computedLabel || $t('leaves.fields.durationHint')"
                persistent-hint
                type="number"
                step="0.5"
                min="0.5"
                :suffix="$t('timeSpace.leave.daysSuffix')"
                data-test="leave-duration"
                :rules="[v => (v !== null && v > 0) || $t('validation.required')]"
              />
              <div
                v-if="rangeHasWeekend"
                class="text-caption text-warning mt-2"
                data-test="leave-weekend-note"
              >
                {{ $t('timeSpace.leave.weekendNote') }}
              </div>
              <div
                v-if="selectedBalance"
                class="text-body-2 mt-2"
                :class="balanceShort ? 'text-error' : 'text-medium-emphasis'"
                data-test="leave-balance"
              >
                {{ balanceShort ? $t('timeSpace.leave.insufficient', { days: formatDuration(selectedBalance.available) }) : $t('timeSpace.leave.available', { days: formatDuration(selectedBalance.available) }) }}
              </div>
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.reason"
                :label="$t('leaves.fields.reason')"
                type="textarea"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <div class="app-drawer-actions">
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
                  data-test="leave-submit"
                >
                  {{ $t('timeSpace.leave.send') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
    </AppDrawer>

    <!-- Leave Type Create/Edit Drawer -->
    <AppDrawer
      v-model="isTypeDrawerOpen"
      :title="isEditMode ? $t('leaves.config.editType') : $t('leaves.config.newType')"
    >
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
            <!-- ADR-782 (UX6.1) : options rares repliées (rien n'est retiré) -->
            <VCol cols="12" class="py-0">
              <VBtn variant="text" size="small" class="px-0" :prepend-icon="typeMoreOpen ? 'tabler-chevron-up' : 'tabler-chevron-down'" data-test="leave-type-more" @click="typeMoreOpen = !typeMoreOpen">
                {{ $t('settingsHub.moreOptions') }}
              </VBtn>
            </VCol>
            <template v-if="typeMoreOpen">
            <VCol cols="12">
              <AppTextField
                v-model.number="maxBalanceDays"
                step="0.5"
                :suffix="$t('timeSpace.leave.daysSuffix')"
                :label="$t('leaves.config.fields.maxBalance')"
                :hint="$t('leaves.config.fields.maxBalanceHint')"
                persistent-hint
                type="number"
                min="0"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="carryOverDays"
                step="0.5"
                :suffix="$t('timeSpace.leave.daysSuffix')"
                :label="$t('leaves.config.fields.carryOver')"
                :hint="$t('leaves.config.fields.carryOverHint')"
                persistent-hint
                type="number"
                min="0"
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
            </template>
            <VCol cols="12">
              <div class="app-drawer-actions">
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
    </AppDrawer>

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
          <!-- ADR-779 (UX4) — la décision se prend en voyant la demande : type, dates, durée. -->
          <div
            v-if="confirmItem"
            class="mt-3 pa-3 rounded border text-body-2"
            data-test="leave-confirm-summary"
          >
            <div class="font-weight-medium">
              {{ confirmItem.leave_type?.name || '' }}
            </div>
            <div class="text-medium-emphasis">
              {{ formatDate(confirmItem.date_from) }} → {{ formatDate(confirmItem.date_to) }} · {{ formatDuration(confirmItem.days_count_hundredths) }}
            </div>
          </div>
          <!-- §7 — shifts publiés impactés (avertissement, pas un blocage) -->
          <VAlert
            v-if="confirmAction === 'approve' && impactedShifts.length"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            <div class="font-weight-medium mb-1">
              {{ $t('leaves.impacted.title', { n: impactedShifts.length }) }}
            </div>
            <ul class="ps-4 mb-0">
              <li
                v-for="s in impactedShifts"
                :key="s.id"
                class="text-caption"
              >
                {{ new Date(s.date + 'T00:00:00').toLocaleDateString(locale) }}
              </li>
            </ul>
            <div class="text-caption mt-1">
              {{ $t('leaves.impacted.hint') }}
            </div>
          </VAlert>
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
            :loading="confirmLoading"
            data-test="leave-confirm"
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

<style scoped>
.legend-dot {
  display: inline-block;
  inline-size: 10px;
  block-size: 10px;
  border-radius: 50%;
}
</style>
