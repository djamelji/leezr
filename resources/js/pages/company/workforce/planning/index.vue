<script setup>
import { usePlanningStore } from '@/modules/company/workforce/planning.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { module: 'workforce_planning', permission: 'workforce.planning_view' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = usePlanningStore()
const employeesStore = useEmployeesStore()

// ── Tab navigation ───────────────────────────────────────────
const activeTab = ref('planning')

// ── Shared helpers ───────────────────────────────────────────
const employeeItems = computed(() =>
  employeesStore.employees.map(e => ({
    title: `${e.first_name} ${e.last_name}`,
    value: e.id,
  })),
)

const locationItems = computed(() =>
  store.allLocations.map(l => ({
    title: l.name,
    value: l.id,
  })),
)

const templateItems = computed(() =>
  store.allTemplates.map(t => ({
    title: t.name,
    value: t.id,
    raw: t,
  })),
)

const dayNames = computed(() => {
  const days = []
  const base = new Date(2024, 0, 1) // Monday 2024-01-01
  for (let i = 0; i < 7; i++) {
    const d = new Date(base)
    d.setDate(d.getDate() + i)
    days.push(d.toLocaleDateString(locale.value, { weekday: 'short' }))
  }

  return days
})

const dayNamesLong = computed(() => {
  const days = []
  const base = new Date(2024, 0, 1)
  for (let i = 0; i < 7; i++) {
    const d = new Date(base)
    d.setDate(d.getDate() + i)
    days.push(d.toLocaleDateString(locale.value, { weekday: 'long' }))
  }

  return days
})

const formatTime = timeStr => {
  if (!timeStr) return ''
  const parts = timeStr.split(':')

  return `${parseInt(parts[0])}h${parts[1] !== '00' ? parts[1] : ''}`
}

const formatShiftRange = shift => {
  if (!shift) return ''

  return `${formatTime(shift.start_at)}-${formatTime(shift.end_at)}`
}

// ══════════════════════════════════════════════════════════════
// TAB 1 — PLANNING (Weekly Grid)
// ══════════════════════════════════════════════════════════════

// ── Week navigation ────────────────────────────────────────
const currentWeekStart = ref(getMonday(new Date()))

function getMonday(d) {
  const date = new Date(d)
  const day = date.getDay()
  const diff = date.getDate() - day + (day === 0 ? -6 : 1)
  date.setDate(diff)
  date.setHours(0, 0, 0, 0)

  return date
}

const weekEnd = computed(() => {
  const d = new Date(currentWeekStart.value)
  d.setDate(d.getDate() + 6)

  return d
})

const weekLabel = computed(() => {
  const start = currentWeekStart.value.toLocaleDateString(locale.value, { day: 'numeric', month: 'long' })
  const end = weekEnd.value.toLocaleDateString(locale.value, { day: 'numeric', month: 'long', year: 'numeric' })

  return `${t('planning.weekOf')} ${start} ${t('planning.to')} ${end}`
})

const weekDates = computed(() => {
  const dates = []
  for (let i = 0; i < 7; i++) {
    const d = new Date(currentWeekStart.value)
    d.setDate(d.getDate() + i)
    dates.push(d.toISOString().slice(0, 10))
  }

  return dates
})

const weekDateIso = computed(() => currentWeekStart.value.toISOString().slice(0, 10))

const prevWeek = () => {
  const d = new Date(currentWeekStart.value)
  d.setDate(d.getDate() - 7)
  currentWeekStart.value = d
}

const nextWeek = () => {
  const d = new Date(currentWeekStart.value)
  d.setDate(d.getDate() + 7)
  currentWeekStart.value = d
}

const goToToday = () => {
  currentWeekStart.value = getMonday(new Date())
}

// ── Data loading ────────────────────────────────────────────
const gridLoading = ref(false)

const fetchWeekData = async () => {
  gridLoading.value = true
  try {
    await Promise.all([
      store.fetchWeekShifts(weekDateIso.value),
      store.fetchStatistics({ from: weekDates.value[0], to: weekDates.value[6] }),
    ])
  } finally {
    gridLoading.value = false
  }
}

watch(currentWeekStart, () => fetchWeekData())

// ── Grid data ───────────────────────────────────────────────
const gridHeaders = computed(() => {
  const cols = [
    { title: t('planning.columns.employee'), key: 'employee', sortable: false, width: 200 },
  ]
  for (let i = 0; i < 7; i++) {
    const dayDate = new Date(currentWeekStart.value)
    dayDate.setDate(dayDate.getDate() + i)
    const label = `${dayNames.value[i]} ${dayDate.getDate()}`
    cols.push({ title: label, key: `day_${i}`, sortable: false, align: 'center', width: 130 })
  }

  return cols
})

// Transform weekShifts (grouped by employee) into table rows
const gridRows = computed(() => {
  const data = store.weekShifts
  if (!data || !Array.isArray(data)) return []

  return data.map(entry => {
    const row = {
      employee: entry.employee,
      employee_id: entry.employee?.id || entry.employee_id,
      shifts: {},
    }

    // Map shifts to day slots
    const shifts = entry.shifts || []
    shifts.forEach(shift => {
      const shiftDate = shift.date?.split('T')[0] || shift.date
      const dayIdx = weekDates.value.indexOf(shiftDate)
      if (dayIdx !== -1) {
        if (!row.shifts[dayIdx]) row.shifts[dayIdx] = []
        row.shifts[dayIdx].push(shift)
      }
    })

    return row
  })
})

// ── Stats ───────────────────────────────────────────────────
const stats = computed(() => store.statistics || {})
const statTotal = computed(() => stats.value.total ?? 0)
const statPublished = computed(() => stats.value.published ?? 0)
const statDraft = computed(() => stats.value.draft ?? 0)
const statHours = computed(() => {
  const mins = stats.value.total_minutes ?? stats.value.hours_planned ?? 0

  return typeof mins === 'number' && mins > 0 ? `${(mins / 60).toFixed(1)}h` : `${stats.value.hours_planned ?? 0}h`
})

// ── Shift status helpers ─────────────────────────────────────
const shiftChipColor = status => {
  const colors = {
    draft: 'default',
    published: 'success',
    completed: 'info',
    cancelled: 'error',
  }

  return colors[status] || 'default'
}

const shiftChipVariant = status => {
  if (status === 'draft') return 'outlined'
  if (status === 'cancelled') return 'tonal'

  return 'flat'
}

// ── Selection for batch publish ─────────────────────────────
const selectedDraftIds = ref([])

const allDraftShiftIds = computed(() => {
  const ids = []
  gridRows.value.forEach(row => {
    Object.values(row.shifts).forEach(dayShifts => {
      dayShifts.forEach(s => {
        if (s.status === 'draft') ids.push(s.id)
      })
    })
  })

  return ids
})

const toggleSelectAll = () => {
  if (selectedDraftIds.value.length === allDraftShiftIds.value.length) {
    selectedDraftIds.value = []
  } else {
    selectedDraftIds.value = [...allDraftShiftIds.value]
  }
}

const toggleShiftSelection = id => {
  const idx = selectedDraftIds.value.indexOf(id)
  if (idx === -1) {
    selectedDraftIds.value.push(id)
  } else {
    selectedDraftIds.value.splice(idx, 1)
  }
}

const publishSelected = async () => {
  if (!selectedDraftIds.value.length) return
  try {
    await store.publishShifts(selectedDraftIds.value)
    toast(t('planning.published'), 'success')
    selectedDraftIds.value = []
    fetchWeekData()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
}

// ── Shift create/edit dialog ────────────────────────────────
const isShiftDrawerOpen = ref(false)
const editingShift = ref(null)
const shiftFormLoading = ref(false)
const shiftFormData = ref({
  employee_id: null,
  date: '',
  start_at: '',
  end_at: '',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  schedule_template_id: null,
  work_location_id: null,
  notes: '',
})

const isShiftEditMode = computed(() => !!editingShift.value)

const resetShiftForm = () => {
  editingShift.value = null
  shiftFormData.value = {
    employee_id: null,
    date: '',
    start_at: '',
    end_at: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    schedule_template_id: null,
    work_location_id: null,
    notes: '',
  }
}

const openCreateShift = (employeeId = null, date = null) => {
  resetShiftForm()
  if (employeeId) shiftFormData.value.employee_id = employeeId
  if (date) shiftFormData.value.date = date
  isShiftDrawerOpen.value = true
}

const openEditShift = shift => {
  editingShift.value = shift
  shiftFormData.value = {
    employee_id: shift.employee_id,
    date: shift.date?.split('T')[0] || shift.date || '',
    start_at: shift.start_at || '',
    end_at: shift.end_at || '',
    timezone: shift.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone,
    schedule_template_id: shift.schedule_template_id || null,
    work_location_id: shift.work_location_id || null,
    notes: shift.notes || '',
  }
  isShiftDrawerOpen.value = true
}

// Auto-fill times when a template is selected
watch(() => shiftFormData.value.schedule_template_id, templateId => {
  if (!templateId) return
  const tpl = store.allTemplates.find(t => t.id === templateId)
  if (tpl) {
    shiftFormData.value.start_at = tpl.start_time || ''
    shiftFormData.value.end_at = tpl.end_time || ''
    if (tpl.work_location_id) {
      shiftFormData.value.work_location_id = tpl.work_location_id
    }
  }
})

const submitShift = async () => {
  shiftFormLoading.value = true
  try {
    const payload = {
      employee_id: shiftFormData.value.employee_id,
      date: shiftFormData.value.date,
      start_at: shiftFormData.value.start_at,
      end_at: shiftFormData.value.end_at,
      timezone: shiftFormData.value.timezone,
      schedule_template_id: shiftFormData.value.schedule_template_id || null,
      work_location_id: shiftFormData.value.work_location_id || null,
      notes: shiftFormData.value.notes || null,
    }

    if (isShiftEditMode.value) {
      await store.updateShift(editingShift.value.id, payload)
      toast(t('planning.shiftUpdated'), 'success')
    } else {
      await store.createShift(payload)
      toast(t('planning.shiftCreated'), 'success')
    }

    isShiftDrawerOpen.value = false
    resetShiftForm()
    fetchWeekData()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    shiftFormLoading.value = false
  }
}

// ── Shift delete ────────────────────────────────────────────
const deleteShiftDialog = ref(false)
const deleteShiftTarget = ref(null)

const openDeleteShift = shift => {
  deleteShiftTarget.value = shift
  deleteShiftDialog.value = true
}

const confirmDeleteShift = async () => {
  try {
    await store.deleteShift(deleteShiftTarget.value.id)
    toast(t('planning.shiftDeleted'), 'success')
    deleteShiftDialog.value = false
    deleteShiftTarget.value = null
    fetchWeekData()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    deleteShiftDialog.value = false
  }
}

// ── Cancel shift ────────────────────────────────────────────
const cancelShiftDialog = ref(false)
const cancelShiftTarget = ref(null)
const cancelReason = ref('')

const openCancelShift = shift => {
  cancelShiftTarget.value = shift
  cancelReason.value = ''
  cancelShiftDialog.value = true
}

const confirmCancelShift = async () => {
  try {
    await store.cancelShift(cancelShiftTarget.value.id, cancelReason.value || null)
    toast(t('planning.shiftCancelled'), 'success')
    cancelShiftDialog.value = false
    cancelShiftTarget.value = null
    cancelReason.value = ''
    fetchWeekData()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    cancelShiftDialog.value = false
  }
}

// ── Bulk create drawer ──────────────────────────────────────
const isBulkDrawerOpen = ref(false)
const bulkFormLoading = ref(false)
const bulkFormData = ref({
  schedule_template_id: null,
  employee_ids: [],
  date_from: '',
  date_to: '',
  work_location_id: null,
})

const resetBulkForm = () => {
  bulkFormData.value = {
    schedule_template_id: null,
    employee_ids: [],
    date_from: '',
    date_to: '',
    work_location_id: null,
  }
}

const submitBulk = async () => {
  bulkFormLoading.value = true
  try {
    await store.bulkCreateShifts({
      schedule_template_id: bulkFormData.value.schedule_template_id,
      employee_ids: bulkFormData.value.employee_ids,
      date_from: bulkFormData.value.date_from,
      date_to: bulkFormData.value.date_to,
      work_location_id: bulkFormData.value.work_location_id || null,
    })
    toast(t('planning.bulkCreated'), 'success')
    isBulkDrawerOpen.value = false
    resetBulkForm()
    fetchWeekData()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    bulkFormLoading.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// TAB 2 — MODELES (Schedule Templates)
// ══════════════════════════════════════════════════════════════
const templatesLoading = ref(false)

const templateHeaders = computed(() => [
  { title: t('planning.templates.columns.name'), key: 'name', sortable: false },
  { title: t('planning.templates.columns.hours'), key: 'hours', sortable: false, width: 140 },
  { title: t('planning.templates.columns.break'), key: 'break_duration_minutes', sortable: false, width: 100 },
  { title: t('planning.templates.columns.location'), key: 'location', sortable: false, width: 160 },
  { title: t('planning.templates.columns.color'), key: 'color', sortable: false, width: 100 },
  { title: t('planning.templates.columns.active'), key: 'is_active', sortable: false, width: 100 },
  { title: '', key: 'actions', sortable: false, width: 100 },
])

const fetchAllTemplates = async () => {
  templatesLoading.value = true
  try {
    await store.fetchTemplates({ all: true, force: true })
  } finally {
    templatesLoading.value = false
  }
}

// ── Template drawer ─────────────────────────────────────────
const isTemplateDrawerOpen = ref(false)
const editingTemplate = ref(null)
const templateFormLoading = ref(false)
const templateFormData = ref({
  name: '',
  start_time: '',
  end_time: '',
  break_duration_minutes: 0,
  color: '#1976D2',
  work_location_id: null,
})

const isTemplateEditMode = computed(() => !!editingTemplate.value)

const colorPresets = [
  '#1976D2', '#388E3C', '#F57C00', '#D32F2F',
  '#7B1FA2', '#0097A7', '#5D4037', '#455A64',
  '#E91E63', '#00BCD4', '#8BC34A', '#FF9800',
]

const resetTemplateForm = () => {
  editingTemplate.value = null
  templateFormData.value = {
    name: '',
    start_time: '',
    end_time: '',
    break_duration_minutes: 0,
    color: '#1976D2',
    work_location_id: null,
  }
}

const openCreateTemplate = () => {
  resetTemplateForm()
  isTemplateDrawerOpen.value = true
}

const openEditTemplate = tpl => {
  editingTemplate.value = tpl
  templateFormData.value = {
    name: tpl.name || '',
    start_time: tpl.start_time || '',
    end_time: tpl.end_time || '',
    break_duration_minutes: tpl.break_duration_minutes ?? 0,
    color: tpl.color || '#1976D2',
    work_location_id: tpl.work_location_id || null,
  }
  isTemplateDrawerOpen.value = true
}

const submitTemplate = async () => {
  templateFormLoading.value = true
  try {
    const payload = { ...templateFormData.value }
    if (isTemplateEditMode.value) {
      await store.updateTemplate(editingTemplate.value.id, payload)
      toast(t('planning.templates.updated'), 'success')
    } else {
      await store.createTemplate(payload)
      toast(t('planning.templates.created'), 'success')
    }
    isTemplateDrawerOpen.value = false
    resetTemplateForm()
    fetchAllTemplates()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    templateFormLoading.value = false
  }
}

// ── Delete template ─────────────────────────────────────────
const deleteTemplateDialog = ref(false)
const deleteTemplateTarget = ref(null)

const openDeleteTemplate = tpl => {
  deleteTemplateTarget.value = tpl
  deleteTemplateDialog.value = true
}

const confirmDeleteTemplate = async () => {
  try {
    await store.deleteTemplate(deleteTemplateTarget.value.id)
    toast(t('planning.templates.deleted'), 'success')
    deleteTemplateDialog.value = false
    deleteTemplateTarget.value = null
    fetchAllTemplates()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    deleteTemplateDialog.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// TAB 3 — LIEUX (Work Locations)
// ══════════════════════════════════════════════════════════════
const locationsLoading = ref(false)

const locationHeaders = computed(() => [
  { title: t('planning.locations.columns.name'), key: 'name', sortable: false },
  { title: t('planning.locations.columns.type'), key: 'type', sortable: false, width: 140 },
  { title: t('planning.locations.columns.address'), key: 'address', sortable: false },
  { title: t('planning.locations.columns.timezone'), key: 'timezone', sortable: false, width: 160 },
  { title: t('planning.locations.columns.active'), key: 'is_active', sortable: false, width: 100 },
  { title: '', key: 'actions', sortable: false, width: 100 },
])

const locationTypeOptions = [
  { title: t('planning.locations.types.internal'), value: 'internal' },
  { title: t('planning.locations.types.client_site'), value: 'client_site' },
  { title: t('planning.locations.types.remote'), value: 'remote' },
  { title: t('planning.locations.types.mobile'), value: 'mobile' },
]

const locationTypeIcon = type => {
  const icons = {
    internal: 'tabler-building',
    client_site: 'tabler-briefcase',
    remote: 'tabler-home',
    mobile: 'tabler-car',
  }

  return icons[type] || 'tabler-map-pin'
}

const locationTypeColor = type => {
  const colors = {
    internal: 'primary',
    client_site: 'warning',
    remote: 'success',
    mobile: 'info',
  }

  return colors[type] || 'secondary'
}

const fetchAllLocations = async () => {
  locationsLoading.value = true
  try {
    await store.fetchLocations({ all: true, force: true })
  } finally {
    locationsLoading.value = false
  }
}

// ── Location drawer ─────────────────────────────────────────
const isLocationDrawerOpen = ref(false)
const editingLocation = ref(null)
const locationFormLoading = ref(false)
const locationFormData = ref({
  name: '',
  type: 'internal',
  client_name: '',
  client_reference: '',
  address: '',
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
})

const isLocationEditMode = computed(() => !!editingLocation.value)

const resetLocationForm = () => {
  editingLocation.value = null
  locationFormData.value = {
    name: '',
    type: 'internal',
    client_name: '',
    client_reference: '',
    address: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  }
}

const openCreateLocation = () => {
  resetLocationForm()
  isLocationDrawerOpen.value = true
}

const openEditLocation = loc => {
  editingLocation.value = loc
  locationFormData.value = {
    name: loc.name || '',
    type: loc.type || 'internal',
    client_name: loc.client_name || '',
    client_reference: loc.client_reference || '',
    address: loc.address || '',
    timezone: loc.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone,
  }
  isLocationDrawerOpen.value = true
}

const submitLocation = async () => {
  locationFormLoading.value = true
  try {
    const payload = { ...locationFormData.value }
    if (isLocationEditMode.value) {
      await store.updateLocation(editingLocation.value.id, payload)
      toast(t('planning.locations.updated'), 'success')
    } else {
      await store.createLocation(payload)
      toast(t('planning.locations.created'), 'success')
    }
    isLocationDrawerOpen.value = false
    resetLocationForm()
    fetchAllLocations()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    locationFormLoading.value = false
  }
}

// ── Delete location ─────────────────────────────────────────
const deleteLocationDialog = ref(false)
const deleteLocationTarget = ref(null)

const openDeleteLocation = loc => {
  deleteLocationTarget.value = loc
  deleteLocationDialog.value = true
}

const confirmDeleteLocation = async () => {
  try {
    await store.deleteLocation(deleteLocationTarget.value.id)
    toast(t('planning.locations.deleted'), 'success')
    deleteLocationDialog.value = false
    deleteLocationTarget.value = null
    fetchAllLocations()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    deleteLocationDialog.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
onMounted(async () => {
  await Promise.all([
    fetchWeekData(),
    store.fetchTemplates({ all: true }),
    store.fetchLocations({ all: true }),
    employeesStore.fetchEmployees({ perPage: 200 }),
  ])
})

// Lazy-load tab data on first visit
const templatesLoaded = ref(false)
const locationsLoaded = ref(false)

watch(activeTab, tab => {
  if (tab === 'templates' && !templatesLoaded.value) {
    templatesLoaded.value = true
    fetchAllTemplates()
  } else if (tab === 'locations' && !locationsLoaded.value) {
    locationsLoaded.value = true
    fetchAllLocations()
  }
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('planning.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('planning.subtitle') }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="activeTab === 'planning' && selectedDraftIds.length > 0"
          v-can="'workforce.planning_manage'"
          color="success"
          prepend-icon="tabler-send"
          @click="publishSelected"
        >
          {{ $t('planning.publishSelected', { count: selectedDraftIds.length }) }}
        </VBtn>
        <VBtn
          v-if="activeTab === 'planning'"
          v-can="'workforce.planning_manage'"
          variant="outlined"
          prepend-icon="tabler-copy"
          @click="isBulkDrawerOpen = true"
        >
          {{ $t('planning.bulkCreate') }}
        </VBtn>
        <VBtn
          v-if="activeTab === 'planning'"
          v-can="'workforce.planning_manage'"
          color="primary"
          prepend-icon="tabler-calendar-plus"
          @click="openCreateShift()"
        >
          {{ $t('planning.newShift') }}
        </VBtn>
        <VBtn
          v-if="activeTab === 'templates'"
          v-can="'workforce.planning_manage'"
          color="primary"
          prepend-icon="tabler-plus"
          @click="openCreateTemplate"
        >
          {{ $t('planning.templates.newTemplate') }}
        </VBtn>
        <VBtn
          v-if="activeTab === 'locations'"
          v-can="'workforce.planning_manage'"
          color="primary"
          prepend-icon="tabler-plus"
          @click="openCreateLocation"
        >
          {{ $t('planning.locations.newLocation') }}
        </VBtn>
      </div>
    </div>

    <!-- Tabs -->
    <VTabs
      v-model="activeTab"
      class="mb-6"
    >
      <VTab value="planning">
        <VIcon
          icon="tabler-calendar-event"
          class="me-2"
        />
        {{ $t('planning.tabs.planning') }}
      </VTab>
      <VTab value="templates">
        <VIcon
          icon="tabler-template"
          class="me-2"
        />
        {{ $t('planning.tabs.templates') }}
      </VTab>
      <VTab value="locations">
        <VIcon
          icon="tabler-map-pin"
          class="me-2"
        />
        {{ $t('planning.tabs.locations') }}
      </VTab>
    </VTabs>

    <VWindow v-model="activeTab">
      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: PLANNING (Weekly Grid)                            -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="planning">
        <!-- Stats Row -->
        <VRow class="mb-6">
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
                  <VIcon icon="tabler-calendar-stats" />
                </VAvatar>
                <div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('planning.stats.totalShifts') }}
                  </div>
                  <div class="text-h5 font-weight-bold">
                    {{ statTotal }}
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
                    {{ $t('planning.stats.published') }}
                  </div>
                  <div class="text-h5 font-weight-bold">
                    {{ statPublished }}
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
                  <VIcon icon="tabler-pencil" />
                </VAvatar>
                <div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('planning.stats.draft') }}
                  </div>
                  <div class="text-h5 font-weight-bold">
                    {{ statDraft }}
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
                  <VIcon icon="tabler-clock-hour-4" />
                </VAvatar>
                <div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('planning.stats.hoursPlanned') }}
                  </div>
                  <div class="text-h5 font-weight-bold">
                    {{ statHours }}
                  </div>
                </div>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>

        <!-- Week Navigation + Grid -->
        <VCard>
          <VCardText>
            <div class="d-flex align-center justify-space-between">
              <div class="d-flex align-center gap-2">
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  @click="prevWeek"
                >
                  <VIcon icon="tabler-chevron-left" />
                </VBtn>
                <h5 class="text-h5 font-weight-medium text-capitalize">
                  {{ weekLabel }}
                </h5>
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  @click="nextWeek"
                >
                  <VIcon icon="tabler-chevron-right" />
                </VBtn>
              </div>
              <div class="d-flex align-center gap-2">
                <VBtn
                  variant="tonal"
                  size="small"
                  @click="goToToday"
                >
                  {{ $t('planning.today') }}
                </VBtn>
                <VBtn
                  v-if="allDraftShiftIds.length > 0"
                  v-can="'workforce.planning_manage'"
                  variant="text"
                  size="small"
                  :prepend-icon="selectedDraftIds.length === allDraftShiftIds.length ? 'tabler-square-check' : 'tabler-square'"
                  @click="toggleSelectAll"
                >
                  {{ $t('planning.selectAll') }}
                </VBtn>
              </div>
            </div>
          </VCardText>

          <VDivider />

          <!-- Weekly Grid Table -->
          <VTable
            density="comfortable"
            :loading="gridLoading"
            class="planning-grid"
          >
            <thead>
              <tr>
                <th
                  class="text-start"
                  style="min-width: 200px;"
                >
                  {{ $t('planning.columns.employee') }}
                </th>
                <th
                  v-for="(date, i) in weekDates"
                  :key="date"
                  class="text-center"
                  style="min-width: 130px;"
                  :class="{ 'bg-light': date === new Date().toISOString().slice(0, 10) }"
                >
                  <div class="text-capitalize font-weight-medium">
                    {{ dayNames[i] }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ new Date(date + 'T00:00:00').getDate() }}
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="gridLoading && gridRows.length === 0">
                <td
                  :colspan="8"
                  class="text-center pa-8"
                >
                  <VProgressCircular
                    indeterminate
                    color="primary"
                  />
                </td>
              </tr>
              <tr v-else-if="gridRows.length === 0 && !gridLoading">
                <td
                  :colspan="8"
                  class="text-center pa-8"
                >
                  <VIcon
                    icon="tabler-calendar-event"
                    size="48"
                    color="secondary"
                    class="mb-4"
                  />
                  <h6 class="text-h6 mb-2">
                    {{ $t('planning.emptyGrid.title') }}
                  </h6>
                  <p class="text-body-1 text-medium-emphasis mb-4">
                    {{ $t('planning.emptyGrid.description') }}
                  </p>
                  <VBtn
                    v-can="'workforce.planning_manage'"
                    color="primary"
                    prepend-icon="tabler-calendar-plus"
                    @click="openCreateShift()"
                  >
                    {{ $t('planning.newShift') }}
                  </VBtn>
                </td>
              </tr>
              <tr
                v-for="row in gridRows"
                :key="row.employee_id"
              >
                <!-- Employee column -->
                <td>
                  <div class="d-flex align-center gap-3 py-2">
                    <VAvatar
                      color="primary"
                      variant="tonal"
                      size="32"
                    >
                      <span class="text-xs font-weight-medium">
                        {{ (row.employee?.first_name?.[0] || '') + (row.employee?.last_name?.[0] || '') }}
                      </span>
                    </VAvatar>
                    <span class="font-weight-medium">
                      {{ row.employee?.first_name }} {{ row.employee?.last_name }}
                    </span>
                  </div>
                </td>

                <!-- Day columns -->
                <td
                  v-for="(date, dayIdx) in weekDates"
                  :key="date"
                  class="text-center planning-cell"
                  :class="{ 'bg-light': date === new Date().toISOString().slice(0, 10) }"
                  @click="!row.shifts[dayIdx]?.length ? openCreateShift(row.employee_id, date) : null"
                >
                  <template v-if="row.shifts[dayIdx]?.length">
                    <div
                      v-for="shift in row.shifts[dayIdx]"
                      :key="shift.id"
                      class="d-inline-flex align-center mb-1"
                    >
                      <!-- Selection checkbox for drafts -->
                      <VCheckbox
                        v-if="shift.status === 'draft'"
                        :model-value="selectedDraftIds.includes(shift.id)"
                        density="compact"
                        hide-details
                        class="me-1 flex-shrink-0"
                        @update:model-value="toggleShiftSelection(shift.id)"
                        @click.stop
                      />
                      <VChip
                        :color="shift.template_color || shiftChipColor(shift.status)"
                        :variant="shiftChipVariant(shift.status)"
                        size="small"
                        :class="{ 'text-decoration-line-through': shift.status === 'cancelled' }"
                        class="cursor-pointer"
                        @click.stop="openEditShift(shift)"
                      >
                        {{ formatShiftRange(shift) }}
                      </VChip>
                      <!-- Quick actions menu -->
                      <VMenu location="bottom end">
                        <template #activator="{ props: menuProps }">
                          <VBtn
                            v-bind="menuProps"
                            icon
                            variant="text"
                            size="x-small"
                            class="ms-1"
                            @click.stop
                          >
                            <VIcon
                              icon="tabler-dots-vertical"
                              size="14"
                            />
                          </VBtn>
                        </template>
                        <VList density="compact">
                          <VListItem
                            prepend-icon="tabler-edit"
                            :title="$t('common.edit')"
                            @click="openEditShift(shift)"
                          />
                          <VListItem
                            v-if="shift.status === 'draft'"
                            prepend-icon="tabler-send"
                            :title="$t('planning.publish')"
                            @click="publishSelected(); selectedDraftIds = [shift.id]"
                          />
                          <VListItem
                            v-if="shift.status === 'published'"
                            prepend-icon="tabler-ban"
                            :title="$t('common.cancel')"
                            @click="openCancelShift(shift)"
                          />
                          <VListItem
                            v-if="shift.status === 'draft'"
                            prepend-icon="tabler-trash"
                            :title="$t('common.delete')"
                            class="text-error"
                            @click="openDeleteShift(shift)"
                          />
                        </VList>
                      </VMenu>
                    </div>
                  </template>
                  <template v-else>
                    <VBtn
                      v-can="'workforce.planning_manage'"
                      icon
                      variant="text"
                      size="x-small"
                      color="secondary"
                      class="planning-add-btn"
                      @click.stop="openCreateShift(row.employee_id, date)"
                    >
                      <VIcon
                        icon="tabler-plus"
                        size="16"
                      />
                    </VBtn>
                  </template>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: MODELES (Schedule Templates)                      -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="templates">
        <VCard>
          <VDataTableServer
            :headers="templateHeaders"
            :items="store.allTemplates"
            :items-length="store.allTemplates.length"
            :loading="templatesLoading"
            :items-per-page="-1"
            class="text-no-wrap"
            hide-default-footer
          >
            <!-- Name -->
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <VAvatar
                  :color="item.color || '#1976D2'"
                  size="32"
                  rounded
                >
                  <VIcon
                    icon="tabler-template"
                    size="18"
                    color="white"
                  />
                </VAvatar>
                <span class="font-weight-medium">{{ item.name }}</span>
              </div>
            </template>

            <!-- Hours -->
            <template #item.hours="{ item }">
              {{ formatTime(item.start_time) }} - {{ formatTime(item.end_time) }}
            </template>

            <!-- Break -->
            <template #item.break_duration_minutes="{ item }">
              <span v-if="item.break_duration_minutes">
                {{ item.break_duration_minutes }} min
              </span>
              <span
                v-else
                class="text-medium-emphasis"
              >-</span>
            </template>

            <!-- Location -->
            <template #item.location="{ item }">
              <span v-if="item.location?.name || item.work_location?.name">
                {{ item.location?.name || item.work_location?.name }}
              </span>
              <span
                v-else
                class="text-medium-emphasis"
              >-</span>
            </template>

            <!-- Color -->
            <template #item.color="{ item }">
              <VAvatar
                :color="item.color || '#1976D2'"
                size="24"
                rounded
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
                  @click.stop="openEditTemplate(item)"
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
                  @click.stop="openDeleteTemplate(item)"
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
                  icon="tabler-template"
                  size="48"
                  color="secondary"
                  class="mb-4"
                />
                <h6 class="text-h6 mb-2">
                  {{ $t('planning.templates.empty') }}
                </h6>
                <p class="text-body-1 text-medium-emphasis mb-4">
                  {{ $t('planning.templates.emptyDescription') }}
                </p>
                <VBtn
                  v-can="'workforce.planning_manage'"
                  color="primary"
                  prepend-icon="tabler-plus"
                  @click="openCreateTemplate"
                >
                  {{ $t('planning.templates.newTemplate') }}
                </VBtn>
              </div>
            </template>
          </VDataTableServer>
        </VCard>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: LIEUX (Work Locations)                            -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="locations">
        <VCard>
          <VDataTableServer
            :headers="locationHeaders"
            :items="store.allLocations"
            :items-length="store.allLocations.length"
            :loading="locationsLoading"
            :items-per-page="-1"
            class="text-no-wrap"
            hide-default-footer
          >
            <!-- Name -->
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <VAvatar
                  :color="locationTypeColor(item.type)"
                  variant="tonal"
                  size="32"
                  rounded
                >
                  <VIcon
                    :icon="locationTypeIcon(item.type)"
                    size="18"
                  />
                </VAvatar>
                <div>
                  <span class="font-weight-medium">{{ item.name }}</span>
                  <div
                    v-if="item.client_name"
                    class="text-body-2 text-medium-emphasis"
                  >
                    {{ item.client_name }}
                  </div>
                </div>
              </div>
            </template>

            <!-- Type -->
            <template #item.type="{ item }">
              <VChip
                :color="locationTypeColor(item.type)"
                size="small"
                variant="tonal"
                :prepend-icon="locationTypeIcon(item.type)"
              >
                {{ $t(`planning.locations.types.${item.type}`) }}
              </VChip>
            </template>

            <!-- Address -->
            <template #item.address="{ item }">
              <span v-if="item.address">{{ item.address }}</span>
              <span
                v-else
                class="text-medium-emphasis"
              >-</span>
            </template>

            <!-- Timezone -->
            <template #item.timezone="{ item }">
              <span class="text-body-2">{{ item.timezone || '-' }}</span>
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
                  @click.stop="openEditLocation(item)"
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
                  @click.stop="openDeleteLocation(item)"
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
                  icon="tabler-map-pin"
                  size="48"
                  color="secondary"
                  class="mb-4"
                />
                <h6 class="text-h6 mb-2">
                  {{ $t('planning.locations.empty') }}
                </h6>
                <p class="text-body-1 text-medium-emphasis mb-4">
                  {{ $t('planning.locations.emptyDescription') }}
                </p>
                <VBtn
                  v-can="'workforce.planning_manage'"
                  color="primary"
                  prepend-icon="tabler-plus"
                  @click="openCreateLocation"
                >
                  {{ $t('planning.locations.newLocation') }}
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

    <!-- Shift Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isShiftDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ isShiftEditMode ? $t('planning.editShift') : $t('planning.newShift') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isShiftDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="submitShift">
          <VRow>
            <VCol cols="12">
              <AppAutocomplete
                v-model="shiftFormData.employee_id"
                :items="employeeItems"
                :label="$t('planning.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
                :disabled="isShiftEditMode"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="shiftFormData.date"
                :label="$t('planning.fields.date')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="shiftFormData.schedule_template_id"
                :items="templateItems"
                :label="$t('planning.fields.template')"
                clearable
                :hint="$t('planning.fields.templateHint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="shiftFormData.start_at"
                :label="$t('planning.fields.startAt')"
                type="time"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="shiftFormData.end_at"
                :label="$t('planning.fields.endAt')"
                type="time"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="shiftFormData.work_location_id"
                :items="locationItems"
                :label="$t('planning.fields.location')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="shiftFormData.notes"
                :label="$t('planning.fields.notes')"
                type="textarea"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isShiftDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="shiftFormLoading"
                >
                  {{ isShiftEditMode ? $t('common.save') : $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Bulk Create Drawer -->
    <VNavigationDrawer
      v-model="isBulkDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('planning.bulkCreate') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isBulkDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VAlert
          type="info"
          variant="tonal"
          class="mb-4"
        >
          {{ $t('planning.bulkHint') }}
        </VAlert>
        <VForm @submit.prevent="submitBulk">
          <VRow>
            <VCol cols="12">
              <AppSelect
                v-model="bulkFormData.schedule_template_id"
                :items="templateItems"
                :label="$t('planning.fields.template')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppAutocomplete
                v-model="bulkFormData.employee_ids"
                :items="employeeItems"
                :label="$t('planning.fields.employees')"
                :rules="[v => (v && v.length > 0) || $t('validation.required')]"
                multiple
                chips
                closable-chips
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="bulkFormData.date_from"
                :label="$t('planning.fields.dateFrom')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="bulkFormData.date_to"
                :label="$t('planning.fields.dateTo')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="bulkFormData.work_location_id"
                :items="locationItems"
                :label="$t('planning.fields.location')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isBulkDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="bulkFormLoading"
                >
                  {{ $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Template Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isTemplateDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ isTemplateEditMode ? $t('planning.templates.editTemplate') : $t('planning.templates.newTemplate') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isTemplateDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="submitTemplate">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="templateFormData.name"
                :label="$t('planning.templates.fields.name')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="templateFormData.start_time"
                :label="$t('planning.templates.fields.startTime')"
                type="time"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="templateFormData.end_time"
                :label="$t('planning.templates.fields.endTime')"
                type="time"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="templateFormData.break_duration_minutes"
                :label="$t('planning.templates.fields.breakDuration')"
                :hint="$t('planning.templates.fields.breakDurationHint')"
                persistent-hint
                type="number"
                min="0"
                step="5"
              />
            </VCol>
            <VCol cols="12">
              <div class="mb-2 text-body-2 font-weight-medium">
                {{ $t('planning.templates.fields.color') }}
              </div>
              <div class="d-flex flex-wrap gap-2">
                <VAvatar
                  v-for="c in colorPresets"
                  :key="c"
                  :color="c"
                  size="32"
                  rounded
                  class="cursor-pointer"
                  :class="{ 'border-lg border-primary': templateFormData.color === c }"
                  @click="templateFormData.color = c"
                >
                  <VIcon
                    v-if="templateFormData.color === c"
                    icon="tabler-check"
                    size="16"
                    color="white"
                  />
                </VAvatar>
              </div>
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="templateFormData.work_location_id"
                :items="locationItems"
                :label="$t('planning.templates.fields.location')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isTemplateDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="templateFormLoading"
                >
                  {{ isTemplateEditMode ? $t('common.save') : $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Location Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isLocationDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ isLocationEditMode ? $t('planning.locations.editLocation') : $t('planning.locations.newLocation') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isLocationDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="submitLocation">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="locationFormData.name"
                :label="$t('planning.locations.fields.name')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="locationFormData.type"
                :items="locationTypeOptions"
                :label="$t('planning.locations.fields.type')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol
              v-if="locationFormData.type === 'client_site'"
              cols="12"
            >
              <AppTextField
                v-model="locationFormData.client_name"
                :label="$t('planning.locations.fields.clientName')"
              />
            </VCol>
            <VCol
              v-if="locationFormData.type === 'client_site'"
              cols="12"
            >
              <AppTextField
                v-model="locationFormData.client_reference"
                :label="$t('planning.locations.fields.clientReference')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="locationFormData.address"
                :label="$t('planning.locations.fields.address')"
                type="textarea"
                rows="2"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="locationFormData.timezone"
                :label="$t('planning.locations.fields.timezone')"
                :hint="$t('planning.locations.fields.timezoneHint')"
                persistent-hint
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isLocationDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="locationFormLoading"
                >
                  {{ isLocationEditMode ? $t('common.save') : $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Delete Shift Dialog -->
    <VDialog
      v-model="deleteShiftDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('planning.deleteShift.title') }}
        </VCardTitle>
        <VCardText>
          {{ $t('planning.deleteShift.text') }}
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="deleteShiftDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="confirmDeleteShift"
          >
            {{ $t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Cancel Shift Dialog -->
    <VDialog
      v-model="cancelShiftDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('planning.cancelShift.title') }}
        </VCardTitle>
        <VCardText>
          <p class="mb-4">
            {{ $t('planning.cancelShift.text') }}
          </p>
          <AppTextField
            v-model="cancelReason"
            :label="$t('planning.cancelShift.reason')"
            type="textarea"
            rows="2"
          />
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="cancelShiftDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="confirmCancelShift"
          >
            {{ $t('common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Template Dialog -->
    <VDialog
      v-model="deleteTemplateDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('planning.templates.deleteTitle') }}
        </VCardTitle>
        <VCardText>
          {{ $t('planning.templates.deleteText', { name: deleteTemplateTarget?.name }) }}
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="deleteTemplateDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="confirmDeleteTemplate"
          >
            {{ $t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Delete Location Dialog -->
    <VDialog
      v-model="deleteLocationDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ $t('planning.locations.deleteTitle') }}
        </VCardTitle>
        <VCardText>
          {{ $t('planning.locations.deleteText', { name: deleteLocationTarget?.name }) }}
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="deleteLocationDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="confirmDeleteLocation"
          >
            {{ $t('common.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style lang="scss" scoped>
.planning-grid {
  .planning-cell {
    cursor: pointer;
    vertical-align: middle;
    padding: 4px 8px !important;
    min-height: 60px;

    &:hover {
      background-color: rgba(var(--v-theme-on-surface), 0.04);
    }
  }

  .planning-add-btn {
    opacity: 0;
    transition: opacity 0.15s ease;
  }

  .planning-cell:hover .planning-add-btn {
    opacity: 1;
  }

  .bg-light {
    background-color: rgba(var(--v-theme-primary), 0.04);
  }
}
</style>
