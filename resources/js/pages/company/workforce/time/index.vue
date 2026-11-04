<script setup>
import { useTimesheetsStore } from '@/modules/company/workforce/timesheets.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'
import AppSectionNavigation from '@/core/components/AppSectionNavigation.vue'
import AppResponsiveDataView from '@/core/components/AppResponsiveDataView.vue'
import AppAdaptiveDialog from '@/core/components/AppAdaptiveDialog.vue'
import TimeCockpit from './_TimeCockpit.vue'
// PRODUCT-UX-2 (LOT B1) — espace « Temps de travail » UNIFIÉ : Aujourd'hui (cockpit prévu/réel) +
// Planning + Présences + Feuilles de temps, montés en onglets. Le planning réutilise sa page
// existante (store/logique/permissions intacts) via `embedded`. Pas de 2ᵉ moteur planning/pointage.
import PlanningPage from '@/pages/company/workforce/planning/index.vue'
import { useCan } from '@/composables/useCan'

definePage({ meta: { module: 'workforce', anyPermission: ['workforce.timesheet_view', 'workforce.planning_view'] } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const route = useRoute()
const router = useRouter()
const store = useTimesheetsStore()
const employeesStore = useEmployeesStore()
const { can } = useCan()
const canPlanning = computed(() => can('workforce.planning_view'))
// E2 (ADR-690): tab-level gating so the space works for any of its capabilities
// (e.g. a planning-only manager lands on Planning, not a 403 cockpit).
const canTimesheet = computed(() => can('workforce.timesheet_view'))
const tabAccess = computed(() => ({ cockpit: canTimesheet.value, planning: canPlanning.value, entries: canTimesheet.value, timesheets: canTimesheet.value }))

// PRODUCT-UX-2 / LOT C2 — context preservation : when reached from the payroll
// preparation panel (?return=…), offer a one-click way back to the SAME period.
const returnTo = computed(() => (typeof route.query.return === 'string' ? route.query.return : null))
const goBackToPayroll = () => { if (returnTo.value) router.push(returnTo.value) }

// ── Tab navigation (deep-link ?tab=) ─────────────────────────
const TABS = ['cockpit', 'planning', 'entries', 'timesheets']

// E3-B (ADR-697) — 4 activités via AppSectionNavigation (au-dessus du contenu, icônes seules mobile),
// filtrées par les capacités réellement accessibles (tabAccess). Deep-link ?tab= conservé.
const timeSections = computed(() => [
  { value: 'cockpit', label: t('timeWork.tabs.today'), icon: 'tabler-dashboard', show: tabAccess.value.cockpit },
  { value: 'planning', label: t('timeWork.tabs.planning'), icon: 'tabler-calendar', show: tabAccess.value.planning },
  { value: 'entries', label: t('timeWork.tabs.presence'), icon: 'tabler-clock-record', show: tabAccess.value.entries },
  { value: 'timesheets', label: t('timeTracking.tabs.timesheets'), icon: 'tabler-file-analytics', show: tabAccess.value.timesheets },
].filter(s => s.show))

const firstAccessibleTab = () => TABS.find(tb => tabAccess.value[tb]) || 'cockpit'
const activeTab = ref(TABS.includes(route.query.tab) && tabAccess.value[route.query.tab] ? route.query.tab : firstAccessibleTab())
watch(activeTab, v => {
  const q = { ...route.query }
  if (v === 'cockpit') delete q.tab
  else q.tab = v
  router.replace({ query: q })
})

// ══════════════════════════════════════════════════════════════
// TAB 1 — POINTAGES (Time Entries)
// ══════════════════════════════════════════════════════════════
const entriesDateFrom = ref(null)
const entriesDateTo = ref(null)
const entriesEmployeeFilter = ref(null)
const entriesStatusFilter = ref(null)
const entriesOptions = ref({ page: 1, itemsPerPage: 15 })
const entriesLoading = ref(false)

const entryStatusOptions = [
  { title: t('timeTracking.statuses.idle'), value: 'idle' },
  { title: t('timeTracking.statuses.working'), value: 'working' },
  { title: t('timeTracking.statuses.on_break'), value: 'on_break' },
  { title: t('timeTracking.statuses.completed'), value: 'completed' },
]

// Priorité de colonne (AppResponsiveDataView) : tablette = essential+important ; mobile = liste tactile.
const entriesHeaders = computed(() => [
  { title: t('timeTracking.columns.date'), key: 'date', sortable: false, width: 110, priority: 'essential' },
  { title: t('timeTracking.columns.employee'), key: 'employee', sortable: false, priority: 'essential' },
  { title: t('timeTracking.columns.clockIn'), key: 'clock_in_formatted', sortable: false, width: 90, priority: 'important' },
  { title: t('timeTracking.columns.clockOut'), key: 'clock_out_formatted', sortable: false, width: 90, priority: 'important' },
  { title: t('timeTracking.columns.breaks'), key: 'break_count', sortable: false, width: 80, priority: 'secondary' },
  { title: t('timeTracking.columns.total'), key: 'total_worked_display', sortable: false, width: 90, priority: 'important' },
  { title: t('timeTracking.columns.status'), key: 'status', sortable: false, width: 110, priority: 'essential' },
  { title: t('timeTracking.columns.anomalies'), key: 'anomalies', sortable: false, width: 100, priority: 'secondary' },
  { title: '', key: 'actions', sortable: false, width: 80 },
])

const fetchEntries = async () => {
  entriesLoading.value = true
  try {
    await store.fetchCompanyEntries({
      from: entriesDateFrom.value || undefined,
      to: entriesDateTo.value || undefined,
      employeeId: entriesEmployeeFilter.value || undefined,
      status: entriesStatusFilter.value || undefined,
      perPage: entriesOptions.value.itemsPerPage,
      page: entriesOptions.value.page,
    })
  } finally {
    entriesLoading.value = false
  }
}

watchDebounced([entriesDateFrom, entriesDateTo, entriesEmployeeFilter, entriesStatusFilter], () => {
  entriesOptions.value.page = 1
  fetchEntries()
}, { debounce: 300 })

watch(entriesOptions, fetchEntries, { deep: true })

// ── Format helpers ───────────────────────────────────────────
const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '-'

const formatHours = minutes => {
  if (minutes == null) return '0.0h'

  return `${(minutes / 60).toFixed(1)}h`
}

const anomalyIcon = type => {
  const icons = {
    missing_clock_out: 'tabler-clock-off',
    excessive_hours: 'tabler-alert-triangle',
    missing_break: 'tabler-coffee-off',
    zero_worked: 'tabler-clock-x',
  }

  return icons[type] || 'tabler-alert-circle'
}

const anomalyColor = type => {
  const colors = {
    missing_clock_out: 'error',
    excessive_hours: 'warning',
    missing_break: 'warning',
    zero_worked: 'info',
  }

  return colors[type] || 'secondary'
}

const statusColor = status => {
  const colors = {
    idle: 'secondary',
    working: 'success',
    on_break: 'warning',
    completed: 'primary',
  }

  return colors[status] || 'default'
}

// ── Correction (workflow TRACÉ : motif obligatoire, pas de modification silencieuse) ──
// B2 (ADR-698) — la correction passe par correctTimeEntry (envoie `reason`, requis côté serveur) ;
// updateTimeEntry sans motif renvoyait un 422. Le serveur n'autorise que les pointages completed/idle
// et refuse si la feuille couvrante est approuvée/verrouillée.
const CORRECTABLE_STATUSES = ['completed', 'idle']
const canCorrectEntry = entry => can('workforce.time_manage') && CORRECTABLE_STATUSES.includes(entry.status)

const isCorrectionOpen = ref(false)
const correctionEntry = ref(null)
const correctionLoading = ref(false)
const correctionData = ref({
  clockIn: '',
  clockOut: '',
  reason: '',
})

const toLocalInput = v => (v ? String(v).slice(0, 16) : '')

const openCorrection = entry => {
  correctionEntry.value = entry
  correctionData.value = {
    clockIn: toLocalInput(entry.clock_in),
    clockOut: toLocalInput(entry.clock_out),
    reason: '',
  }
  isCorrectionOpen.value = true
}

const submitCorrection = async () => {
  if (!correctionData.value.reason) return
  correctionLoading.value = true
  try {
    await store.correctTimeEntry(correctionEntry.value.id, {
      clockIn: correctionData.value.clockIn ? correctionData.value.clockIn + ':00' : undefined,
      clockOut: correctionData.value.clockOut ? correctionData.value.clockOut + ':00' : undefined,
      reason: correctionData.value.reason,
    })
    toast(t('timeTracking.corrected'), 'success')
    isCorrectionOpen.value = false
    fetchEntries()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    correctionLoading.value = false
  }
}

// ── Delete dialog ────────────────────────────────────────────
const isDeleteOpen = ref(false)
const deleteEntry = ref(null)
const deleteLoading = ref(false)

const openDelete = entry => {
  deleteEntry.value = entry
  isDeleteOpen.value = true
}

const confirmDelete = async () => {
  deleteLoading.value = true
  try {
    await store.deleteTimeEntry(deleteEntry.value.id)
    toast(t('timeTracking.deleted'), 'success')
    isDeleteOpen.value = false
    fetchEntries()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    deleteLoading.value = false
  }
}

// ── Manual entry drawer ──────────────────────────────────────
const isManualOpen = ref(false)
const manualLoading = ref(false)
const manualData = ref({
  employeeId: null,
  date: '',
  clockIn: '',
  clockOut: '',
})

const resetManual = () => {
  manualData.value = { employeeId: null, date: '', clockIn: '', clockOut: '' }
}

const submitManual = async () => {
  manualLoading.value = true
  try {
    await store.createManualEntry(manualData.value)
    toast(t('timeTracking.created'), 'success')
    isManualOpen.value = false
    resetManual()
    fetchEntries()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    manualLoading.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// TAB 2 — FEUILLES DE TEMPS (Timesheets) — unchanged logic
// ══════════════════════════════════════════════════════════════
const tsStatusFilter = ref(null)
const tsPeriodStart = ref(null)
const tsPeriodEnd = ref(null)
const tsOptions = ref({ page: 1, itemsPerPage: 15 })
const tsLoading = ref(false)

const tsStatusOptions = [
  { title: t('workforce.statuses.timesheet.draft'), value: 'draft' },
  { title: t('workforce.statuses.timesheet.submitted'), value: 'submitted' },
  { title: t('workforce.statuses.timesheet.approved'), value: 'approved' },
  { title: t('workforce.statuses.timesheet.rejected'), value: 'rejected' },
  { title: t('workforce.statuses.timesheet.locked'), value: 'locked' },
]

const tsHeaders = computed(() => [
  { title: t('timesheets.columns.employee'), key: 'employee', sortable: false },
  { title: t('timesheets.columns.period'), key: 'period', sortable: false },
  { title: t('timesheets.columns.status'), key: 'status', sortable: false, width: 120 },
  { title: t('timesheets.columns.worked'), key: 'total_worked_minutes', sortable: false, width: 120 },
  { title: t('timesheets.columns.overtime'), key: 'total_overtime_minutes', sortable: false, width: 120 },
  { title: t('timesheets.columns.anomalies'), key: 'anomalies_count', sortable: false, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 140 },
])

const fetchTimesheets = async () => {
  tsLoading.value = true
  try {
    await store.fetchTimesheets({
      periodStart: tsPeriodStart.value || undefined,
      periodEnd: tsPeriodEnd.value || undefined,
      status: tsStatusFilter.value || undefined,
      perPage: tsOptions.value.itemsPerPage,
      page: tsOptions.value.page,
    })
  } finally {
    tsLoading.value = false
  }
}

const fetchStats = async () => {
  try {
    await store.fetchStatistics({
      periodStart: tsPeriodStart.value || undefined,
      periodEnd: tsPeriodEnd.value || undefined,
    })
  } catch {
    // Statistics are optional
  }
}

watchDebounced([tsStatusFilter, tsPeriodStart, tsPeriodEnd], () => {
  tsOptions.value.page = 1
  fetchTimesheets()
  fetchStats()
}, { debounce: 300 })

watch(tsOptions, fetchTimesheets, { deep: true })

const goToTimesheet = id => {
  router.push({ name: 'company-workforce-time-id', params: { id } })
}

// ── Timesheet workflow actions ───────────────────────────────
const showTsApproveDialog = ref(false)
const showTsRejectDialog = ref(false)
const tsApprovalNote = ref('')
const tsRejectionReason = ref('')
const tsActionLoading = ref(false)
const tsActionTargetId = ref(null)

const handleTsSubmit = async id => {
  tsActionLoading.value = true
  try {
    await store.submitTimesheet(id)
    toast(t('timesheets.submitted'), 'success')
    fetchTimesheets()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    tsActionLoading.value = false
  }
}

const openTsApprove = id => {
  tsActionTargetId.value = id
  tsApprovalNote.value = ''
  showTsApproveDialog.value = true
}

const confirmTsApprove = async () => {
  tsActionLoading.value = true
  try {
    await store.approveTimesheet(tsActionTargetId.value, {
      approvalNote: tsApprovalNote.value || undefined,
    })
    toast(t('timesheets.approved'), 'success')
    showTsApproveDialog.value = false
    tsApprovalNote.value = ''
    fetchTimesheets()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    tsActionLoading.value = false
  }
}

const openTsReject = id => {
  tsActionTargetId.value = id
  tsRejectionReason.value = ''
  showTsRejectDialog.value = true
}

const confirmTsReject = async () => {
  tsActionLoading.value = true
  try {
    await store.rejectTimesheet(tsActionTargetId.value, {
      rejectionReason: tsRejectionReason.value,
    })
    toast(t('timesheets.rejected'), 'success')
    showTsRejectDialog.value = false
    tsRejectionReason.value = ''
    fetchTimesheets()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    tsActionLoading.value = false
  }
}

const handleTsLock = async id => {
  tsActionLoading.value = true
  try {
    await store.lockTimesheet(id)
    toast(t('timesheets.locked'), 'success')
    fetchTimesheets()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    tsActionLoading.value = false
  }
}

const handleTsReopen = async id => {
  tsActionLoading.value = true
  try {
    await store.reopenTimesheet(id)
    toast(t('timesheets.reopened'), 'success')
    fetchTimesheets()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    tsActionLoading.value = false
  }
}

// ── Generate timesheet drawer ────────────────────────────────
const isGenerateOpen = ref(false)
const generateLoading = ref(false)
const generateData = ref({
  employeeId: null,
  periodStart: '',
  periodEnd: '',
})

const resetGenerate = () => {
  generateData.value = { employeeId: null, periodStart: '', periodEnd: '' }
}

const submitGenerate = async () => {
  generateLoading.value = true
  try {
    await store.generateTimesheet({
      employeeId: generateData.value.employeeId,
      periodStart: generateData.value.periodStart,
      periodEnd: generateData.value.periodEnd,
    })
    toast(t('timesheets.generated'), 'success')
    isGenerateOpen.value = false
    resetGenerate()
    fetchTimesheets()
    fetchStats()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    generateLoading.value = false
  }
}

// ── Shared ───────────────────────────────────────────────────
const employeeItems = computed(() => {
  return employeesStore.employees.map(e => ({
    title: `${e.first_name} ${e.last_name}`,
    value: e.id,
  }))
})

const stats = computed(() => store.statistics)

// ── Init ─────────────────────────────────────────────────────
onMounted(() => {
  fetchEntries()
  fetchTimesheets()
  fetchStats()
  employeesStore.fetchEmployees({ perPage: 100 })
})
</script>

<template>
  <div>
    <!-- Return to payroll preparation (context preservation, C2) -->
    <VAlert
      v-if="returnTo"
      type="info"
      variant="tonal"
      density="comfortable"
      class="mb-4"
      border="start"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span>{{ $t('timeWork.returnToPayroll') }}</span>
        <VBtn size="small" color="primary" prepend-icon="tabler-arrow-back-up" @click="goBackToPayroll">
          {{ $t('timeWork.backToPayroll') }}
        </VBtn>
      </div>
    </VAlert>

    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('timeWork.spaceTitle') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('timeWork.spaceSubtitle') }}
        </p>
      </div>
    </div>

    <!-- 4 activités : Aujourd'hui · Planning · Présences · Feuilles de temps (navigation supérieure) -->
    <AppSectionNavigation
      v-model="activeTab"
      :sections="timeSections"
      class="mb-5"
    />

    <VWindow v-model="activeTab" :touch="false">
      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: AUJOURD'HUI — cockpit prévu/réel (W-G5)           -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="cockpit">
        <TimeCockpit v-if="activeTab === 'cockpit'" @navigate="p => { activeTab = p.tab }" />
      </VWindowItem>

      <!-- TAB: PLANNING (réutilise la page existante, en-tête masqué) -->
      <VWindowItem v-if="canPlanning" value="planning">
        <PlanningPage v-if="activeTab === 'planning'" embedded />
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: POINTAGES                                         -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="entries">
        <!-- Action bar -->
        <div class="d-flex justify-end mb-4">
          <VBtn
            v-can="'workforce.time_manage'"
            color="primary"
            prepend-icon="tabler-clock-plus"
            @click="isManualOpen = true"
          >
            {{ $t('timeTracking.addEntry') }}
          </VBtn>
        </div>

        <VCard>
          <!-- Filters -->
          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="3"
              >
                <AppDateTimePicker
                  v-model="entriesDateFrom"
                  :label="$t('timeTracking.filters.from')"
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
                  v-model="entriesDateTo"
                  :label="$t('timeTracking.filters.to')"
                  :config="{ dateFormat: 'Y-m-d' }"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol
                cols="12"
                md="3"
              >
                <AppAutocomplete
                  v-model="entriesEmployeeFilter"
                  :items="employeeItems"
                  :label="$t('timeTracking.filters.employee')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol
                cols="12"
                md="3"
              >
                <AppSelect
                  v-model="entriesStatusFilter"
                  :items="entryStatusOptions"
                  :label="$t('timeTracking.filters.status')"
                  clearable
                  density="compact"
                />
              </VCol>
            </VRow>
          </VCardText>

          <!-- Table (desktop/tablette) + liste tactile (mobile) — présentation adaptative, logique partagée -->
          <AppResponsiveDataView
            v-model:options="entriesOptions"
            :headers="entriesHeaders"
            :items="store.companyEntries"
            :items-length="store.totalCompanyEntries"
            :loading="entriesLoading"
          >
            <!-- Mobile : nom + statut, date, pointage, total, indicateur anomalie -->
            <template #mobile-item="{ item }">
              <div class="d-flex align-center justify-space-between gap-2">
                <span class="font-weight-medium text-truncate">{{ item.employee?.first_name }} {{ item.employee?.last_name }}</span>
                <VChip :color="statusColor(item.status)" size="x-small" variant="tonal">
                  {{ $t(`timeTracking.statuses.${item.status}`) }}
                </VChip>
              </div>
              <div class="d-flex align-center flex-wrap gap-x-3 text-caption text-medium-emphasis mt-1">
                <span>{{ formatDate(item.date) }}</span>
                <span>
                  <VIcon icon="tabler-clock-record" size="13" class="me-1" />
                  {{ item.clock_in_formatted || '—' }}–{{ item.clock_out_formatted || '…' }}
                </span>
                <span class="font-weight-medium">{{ item.total_worked_display }}</span>
                <VIcon v-if="item.anomalies?.length" icon="tabler-alert-circle" color="warning" size="16" />
              </div>
            </template>
            <template #mobile-append="{ item }">
              <VMenu v-if="can('workforce.time_manage') && ['completed', 'idle'].includes(item.status)" location="bottom end">
                <template #activator="{ props: menuProps }">
                  <VBtn v-bind="menuProps" icon="tabler-dots-vertical" variant="text" size="small" @click.stop />
                </template>
                <VList density="compact">
                  <VListItem
                    v-if="canCorrectEntry(item)"
                    prepend-icon="tabler-edit"
                    :title="$t('timeTracking.correct')"
                    @click="openCorrection(item)"
                  />
                  <VListItem
                    prepend-icon="tabler-trash"
                    :title="$t('common.delete')"
                    class="text-error"
                    @click="openDelete(item)"
                  />
                </VList>
              </VMenu>
            </template>

            <!-- Date -->
            <template #item.date="{ item }">
              {{ formatDate(item.date) }}
            </template>

            <!-- Employee -->
            <template #item.employee="{ item }">
              <div class="d-flex align-center gap-2 py-2">
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

            <!-- Clock In -->
            <template #item.clock_in_formatted="{ item }">
              <span class="font-weight-medium text-success">
                {{ item.clock_in_formatted || '-' }}
              </span>
            </template>

            <!-- Clock Out -->
            <template #item.clock_out_formatted="{ item }">
              <span :class="['font-weight-medium', item.clock_out_formatted ? 'text-error' : 'text-medium-emphasis']">
                {{ item.clock_out_formatted || '-' }}
              </span>
            </template>

            <!-- Breaks -->
            <template #item.break_count="{ item }">
              <VChip
                v-if="item.break_count > 0"
                size="small"
                variant="tonal"
                color="info"
              >
                {{ item.break_count }} ({{ item.total_break_display }})
              </VChip>
              <span
                v-else
                class="text-medium-emphasis"
              >-</span>
            </template>

            <!-- Total worked -->
            <template #item.total_worked_display="{ item }">
              <span class="font-weight-bold">
                {{ item.total_worked_display }}
              </span>
            </template>

            <!-- Status -->
            <template #item.status="{ item }">
              <VChip
                :color="statusColor(item.status)"
                size="small"
                variant="tonal"
              >
                {{ $t(`timeTracking.statuses.${item.status}`) }}
              </VChip>
            </template>

            <!-- Anomalies -->
            <template #item.anomalies="{ item }">
              <template v-if="item.anomalies?.length">
                <VTooltip
                  v-for="(anomaly, i) in item.anomalies"
                  :key="i"
                  :text="$t(`timeTracking.anomalies.${anomaly.type}`, anomaly.message)"
                  location="top"
                >
                  <template #activator="{ props: tooltipProps }">
                    <VIcon
                      v-bind="tooltipProps"
                      :icon="anomalyIcon(anomaly.type)"
                      :color="anomalyColor(anomaly.type)"
                      size="20"
                      class="me-1"
                    />
                  </template>
                </VTooltip>
              </template>
              <span
                v-else
                class="text-medium-emphasis"
              >-</span>
            </template>

            <!-- Actions -->
            <template #item.actions="{ item }">
              <div class="d-flex gap-1">
                <VBtn
                  v-if="canCorrectEntry(item)"
                  icon
                  variant="text"
                  size="x-small"
                  color="primary"
                  data-test="entry-correct"
                  @click.stop="openCorrection(item)"
                >
                  <VIcon
                    icon="tabler-edit"
                    size="18"
                  />
                  <VTooltip
                    activator="parent"
                    :text="$t('timeTracking.correct')"
                    location="top"
                  />
                </VBtn>
                <VBtn
                  v-if="item.status === 'completed' || item.status === 'idle'"
                  v-can="'workforce.time_manage'"
                  icon
                  variant="text"
                  size="x-small"
                  color="error"
                  @click.stop="openDelete(item)"
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
                  icon="tabler-clock"
                  size="48"
                  color="secondary"
                  class="mb-4"
                />
                <h6 class="text-h6 mb-2">
                  {{ $t('timeTracking.emptyState.title') }}
                </h6>
                <p class="text-body-1 text-medium-emphasis mb-4">
                  {{ $t('timeTracking.emptyState.description') }}
                </p>
              </div>
            </template>
          </AppResponsiveDataView>
        </VCard>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: FEUILLES DE TEMPS                                 -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="timesheets">
        <!-- Action bar -->
        <div class="d-flex justify-end mb-4">
          <VBtn
            v-can="'workforce.time_manage'"
            color="primary"
            data-test="ts-generate-open"
            prepend-icon="tabler-clock-plus"
            @click="isGenerateOpen = true"
          >
            {{ $t('timesheets.generateTimesheet') }}
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

        <VCard>
          <!-- Filters -->
          <VCardText>
            <VRow>
              <VCol
                cols="12"
                md="3"
              >
                <AppDateTimePicker
                  v-model="tsPeriodStart"
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
                  v-model="tsPeriodEnd"
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
                  v-model="tsStatusFilter"
                  :items="tsStatusOptions"
                  :placeholder="$t('timesheets.filters.status')"
                  clearable
                  density="compact"
                />
              </VCol>
            </VRow>
          </VCardText>

          <!-- Table -->
          <VDataTableServer
            v-model:options="tsOptions"
            :headers="tsHeaders"
            :items="store.periods"
            :items-length="store.totalPeriods"
            :loading="tsLoading"
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
              <div class="d-flex align-center gap-1">
                <!-- Draft → Submit -->
                <VBtn
                  v-if="item.status === 'draft'"
                  v-can="'workforce.time_manage'"
                  icon
                  variant="text"
                  size="small"
                  color="primary"
                  :loading="tsActionLoading"
                  @click.stop="handleTsSubmit(item.id)"
                >
                  <VIcon icon="tabler-send" />
                  <VTooltip
                    activator="parent"
                    :text="$t('timesheets.actions.submit')"
                    location="top"
                  />
                </VBtn>

                <!-- Submitted → Approve -->
                <VBtn
                  v-if="item.status === 'submitted'"
                  v-can="'workforce.time_manage'"
                  icon
                  variant="text"
                  size="small"
                  color="success"
                  @click.stop="openTsApprove(item.id)"
                >
                  <VIcon icon="tabler-check" />
                  <VTooltip
                    activator="parent"
                    :text="$t('timesheets.actions.approve')"
                    location="top"
                  />
                </VBtn>

                <!-- Submitted → Reject -->
                <VBtn
                  v-if="item.status === 'submitted'"
                  v-can="'workforce.time_manage'"
                  icon
                  variant="text"
                  size="small"
                  color="error"
                  @click.stop="openTsReject(item.id)"
                >
                  <VIcon icon="tabler-x" />
                  <VTooltip
                    activator="parent"
                    :text="$t('timesheets.actions.reject')"
                    location="top"
                  />
                </VBtn>

                <!-- Approved → Lock -->
                <VBtn
                  v-if="item.status === 'approved'"
                  v-can="'workforce.time_manage'"
                  icon
                  variant="text"
                  size="small"
                  color="warning"
                  :loading="tsActionLoading"
                  @click.stop="handleTsLock(item.id)"
                >
                  <VIcon icon="tabler-lock" />
                  <VTooltip
                    activator="parent"
                    :text="$t('timesheets.actions.lock')"
                    location="top"
                  />
                </VBtn>

                <!-- Rejected → Reopen -->
                <VBtn
                  v-if="item.status === 'rejected'"
                  v-can="'workforce.time_manage'"
                  icon
                  variant="text"
                  size="small"
                  color="info"
                  :loading="tsActionLoading"
                  @click.stop="handleTsReopen(item.id)"
                >
                  <VIcon icon="tabler-refresh" />
                  <VTooltip
                    activator="parent"
                    :text="$t('timesheets.actions.reopen')"
                    location="top"
                  />
                </VBtn>

                <!-- View (always) -->
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  @click.stop="goToTimesheet(item.id)"
                >
                  <VIcon icon="tabler-chevron-right" />
                </VBtn>
              </div>
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
                  @click="isGenerateOpen = true"
                >
                  {{ $t('timesheets.generateTimesheet') }}
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

    <!-- Manual Entry Drawer -->
    <VNavigationDrawer
      v-model="isManualOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('timeTracking.addEntry') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isManualOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="submitManual">
          <VRow>
            <VCol cols="12">
              <AppAutocomplete
                v-model="manualData.employeeId"
                :items="employeeItems"
                :label="$t('timeTracking.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="manualData.date"
                :label="$t('timeTracking.fields.date')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="manualData.clockIn"
                :label="$t('timeTracking.fields.clockIn')"
                :config="{ dateFormat: 'Y-m-d H:i', enableTime: true }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="manualData.clockOut"
                :label="$t('timeTracking.fields.clockOut')"
                :config="{ dateFormat: 'Y-m-d H:i', enableTime: true }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isManualOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="manualLoading"
                >
                  {{ $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Correction (dialogue adaptatif : plein écran mobile, motif OBLIGATOIRE, workflow tracé) -->
    <AppAdaptiveDialog
      v-model="isCorrectionOpen"
      :title="$t('timeTracking.correctEntry')"
      :max-width="460"
    >
      <VAlert
        type="info"
        variant="tonal"
        density="comfortable"
        class="mb-4"
      >
        {{ $t('timeTracking.correctionHint') }}
      </VAlert>
      <div
        v-if="correctionEntry"
        class="text-body-2 mb-4"
      >
        <span class="font-weight-medium">{{ correctionEntry.employee?.first_name }} {{ correctionEntry.employee?.last_name }}</span>
        <span class="text-medium-emphasis"> · {{ formatDate(correctionEntry.date) }}</span>
      </div>
      <VForm @submit.prevent="submitCorrection">
        <AppTextField
          v-model="correctionData.clockIn"
          :label="$t('timeTracking.fields.clockIn')"
          type="datetime-local"
          data-test="entry-correct-in"
          class="mb-3"
        />
        <AppTextField
          v-model="correctionData.clockOut"
          :label="$t('timeTracking.fields.clockOut')"
          type="datetime-local"
          data-test="entry-correct-out"
          class="mb-3"
        />
        <VTextarea
          v-model="correctionData.reason"
          :label="$t('timeCockpit.reason') + ' *'"
          :rules="[v => !!v || $t('validation.required')]"
          rows="2"
          auto-grow
          data-test="entry-correct-reason"
        />
      </VForm>
      <template #actions="{ close }">
        <VSpacer />
        <VBtn variant="outlined" @click="close">
          {{ $t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="correctionLoading"
          :disabled="!correctionData.reason"
          data-test="entry-correct-submit"
          @click="submitCorrection"
        >
          {{ $t('timeTracking.applyCorrection') }}
        </VBtn>
      </template>
    </AppAdaptiveDialog>

    <!-- Generate Timesheet Drawer -->
    <VNavigationDrawer
      v-model="isGenerateOpen"
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
          @click="isGenerateOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="submitGenerate">
          <VRow>
            <VCol cols="12">
              <AppAutocomplete
                v-model="generateData.employeeId"
                data-test="ts-gen-employee"
                :items="employeeItems"
                :label="$t('timesheets.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="generateData.periodStart"
                data-test="ts-gen-start"
                :label="$t('timesheets.fields.periodStart')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="generateData.periodEnd"
                data-test="ts-gen-end"
                :label="$t('timesheets.fields.periodEnd')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isGenerateOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  data-test="ts-gen-submit"
                  :loading="generateLoading"
                >
                  {{ $t('timesheets.generate') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Delete Confirmation Dialog -->
    <VDialog
      v-model="isDeleteOpen"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('timeTracking.deleteConfirm.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-1">
            {{ $t('timeTracking.deleteConfirm.message') }}
          </p>
          <div
            v-if="deleteEntry"
            class="d-flex gap-4 mt-3 pa-3 bg-light rounded"
          >
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timeTracking.columns.date') }}
              </div>
              <div class="font-weight-medium">
                {{ formatDate(deleteEntry.date) }}
              </div>
            </div>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timeTracking.columns.clockIn') }}
              </div>
              <div class="font-weight-medium">
                {{ deleteEntry.clock_in_formatted || '-' }}
              </div>
            </div>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('timeTracking.columns.clockOut') }}
              </div>
              <div class="font-weight-medium">
                {{ deleteEntry.clock_out_formatted || '-' }}
              </div>
            </div>
          </div>
        </VCardText>
        <VCardActions class="justify-end pa-4">
          <VBtn
            variant="outlined"
            @click="isDeleteOpen = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="deleteLoading"
            @click="confirmDelete"
          >
            {{ $t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Timesheet Approve Dialog -->
    <VDialog
      v-model="showTsApproveDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('timesheets.dialogs.approve.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-1 mb-4">
            {{ $t('timesheets.dialogs.approve.message') }}
          </p>
          <AppTextField
            v-model="tsApprovalNote"
            :label="$t('timesheets.dialogs.approve.noteLabel')"
            :placeholder="$t('timesheets.dialogs.approve.notePlaceholder')"
          />
        </VCardText>
        <VCardActions class="justify-end pa-4">
          <VBtn
            variant="outlined"
            @click="showTsApproveDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="success"
            :loading="tsActionLoading"
            @click="confirmTsApprove"
          >
            {{ $t('timesheets.actions.approve') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Timesheet Reject Dialog -->
    <VDialog
      v-model="showTsRejectDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('timesheets.dialogs.reject.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-1 mb-4">
            {{ $t('timesheets.dialogs.reject.message') }}
          </p>
          <AppTextField
            v-model="tsRejectionReason"
            :label="$t('timesheets.dialogs.reject.reasonLabel')"
            :placeholder="$t('timesheets.dialogs.reject.reasonPlaceholder')"
            :rules="[v => !!v || $t('validation.required')]"
          />
        </VCardText>
        <VCardActions class="justify-end pa-4">
          <VBtn
            variant="outlined"
            @click="showTsRejectDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="tsActionLoading"
            :disabled="!tsRejectionReason"
            @click="confirmTsReject"
          >
            {{ $t('timesheets.actions.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
