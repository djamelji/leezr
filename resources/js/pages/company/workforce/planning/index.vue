<script setup>
import { usePlanningStore } from '@/modules/company/workforce/planning.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import { useDisplay } from 'vuetify'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'
import PlanningCalendar from './_PlanningCalendar.vue'
import ActivityDay from './_ActivityDay.vue'

definePage({ meta: { module: 'workforce_planning', permission: 'workforce.planning_view' } })

// PRODUCT-UX-2 (LOT B1) — `embedded` : masque l'en-tête de page quand monté comme onglet « Planning »
// de l'espace « Temps de travail » (évite le doublon de titre). Logique 100 % réutilisée.
defineProps({ embedded: { type: Boolean, default: false } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = usePlanningStore()
const employeesStore = useEmployeesStore()
const { can } = useCan()

// ── Tab navigation ───────────────────────────────────────────
const activeTab = ref('calendar')
const { smAndDown } = useDisplay()

// E3-B (ADR-697) — sous-navigation planning. La GRILLE hebdo (lignes=collaborateurs × 7 jours, ~1110px)
// n'est pas lisible sur mobile : on la réserve au desktop ; le mobile utilise l'AGENDA (onglet Calendrier).
// §8 — l'onglet Planning est désormais TOUJOURS disponible ; sur mobile il affiche une vue JOUR dédiée
// (pas de grille desktop compressée).
const planningSections = computed(() => [
  { value: 'calendar', label: t('planning.tabs.calendar'), icon: 'tabler-calendar-week' },
  { value: 'planning', label: t('planning.tabs.planning'), icon: 'tabler-layout-grid' },
  { value: 'templates', label: t('planning.tabs.templates'), icon: 'tabler-template' },
])

// ── Calendrier Planning (W-G4A : scope + filtres serveur, absences + conflits) ──
const calRef = ref()
const calEmployee = ref(null)
const calDepartment = ref(null)
const calStatus = ref(null)
const calConflicts = ref([])
const calFilters = computed(() => ({
  employeeId: calEmployee.value || undefined,
  departmentId: calDepartment.value || undefined,
  status: calStatus.value || undefined,
}))
const calDepartmentOptions = computed(() => employeesStore.departments.map(d => ({ title: d.name, value: d.id })))
const calStatusOptions = [
  { title: t('workforce.statuses.shift.draft', 'Brouillon'), value: 'draft' },
  { title: t('workforce.statuses.shift.published', 'Publié'), value: 'published' },
]
const calSelected = ref(null)
const calDetailOpen = ref(false)
const onSelectEvent = ev => { calSelected.value = ev; calDetailOpen.value = true }
const calLoaded = ref(false)
const fmtTime = iso => iso ? new Date(iso).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : '—'
const fmtDateTime = iso => iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '—'

// ── W-G4B : premier jour de semaine (préférence Company) ──
// Convention serveur `week_start_day` = 0 (dimanche) … 6 (samedi) ; NULL = pas de préférence → lundi (ISO).
// E3-B/B2 (réserve B1 #1) : la GRILLE hebdo utilisait le lundi en dur ; elle respecte désormais cette
// même préférence (une seule source de vérité, pas de 2ᵉ configuration de calendrier).
const weekStartDay = ref(null)
const effectiveFirstDay = computed(() => weekStartDay.value ?? 1)
const canEditShifts = computed(() => can('workforce.planning_manage'))
onMounted(async () => {
  try { weekStartDay.value = (await store.fetchWeekStart()).week_start_day } catch { /* repli locale */ }
})

// ── W-G4B : drag-and-drop d'un brouillon → confirmation + verrou optimiste + revert ──
const moveDialog = ref(false)
const moveLoading = ref(false)
const pendingMove = ref(null)
const onShiftMove = payload => { pendingMove.value = payload; moveDialog.value = true }
const cancelMove = () => { pendingMove.value?.revert(); pendingMove.value = null; moveDialog.value = false }
const confirmMove = async () => {
  moveLoading.value = true
  try {
    await store.updateShift(pendingMove.value.shift.shift_id, {
      start_at: pendingMove.value.start,
      end_at: pendingMove.value.end,
      expected_updated_at: pendingMove.value.shift.updated_at,
    })
    toast(t('planning.ops.moved'), 'success')
    moveDialog.value = false
    pendingMove.value = null
    calRef.value?.refetch()
  } catch (e) {
    // Échec (conflit concurrence 409, règle 422…) → revert visuel + message.
    pendingMove.value?.revert()
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
    moveDialog.value = false
    pendingMove.value = null
  } finally { moveLoading.value = false }
}

// ── W-G4B : résolution / réaffectation d'un brouillon ──
const reassignOpen = ref(false)
const reassignLoading = ref(false)
const reassignCandidates = ref([])
const reassignTarget = ref(null)
const openReassign = async () => {
  reassignOpen.value = true
  reassignTarget.value = null
  reassignCandidates.value = []
  try {
    reassignCandidates.value = await store.fetchAvailability({
      start: calSelected.value.start,
      end: calSelected.value.end,
      excludeShiftId: calSelected.value.shift_id,
    })
  } catch { /* liste vide */ }
}
const availColor = a => ({ available: 'success', to_verify: 'warning', busy: 'secondary', unavailable: 'error' }[a] || 'secondary')
const confirmReassign = async () => {
  reassignLoading.value = true
  try {
    await store.updateShift(calSelected.value.shift_id, {
      employee_id: reassignTarget.value,
      expected_updated_at: calSelected.value.updated_at,
    })
    toast(t('planning.ops.reassigned'), 'success')
    reassignOpen.value = false
    calDetailOpen.value = false
    calRef.value?.refetch()
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  } finally { reassignLoading.value = false }
}

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

// Libellés de jours pivotés pour COMMENCER au premier jour de semaine effectif (préférence Company).
const weekdayLabels = format => {
  const days = []
  const sunday = new Date(2024, 0, 7) // 7 janvier 2024 = dimanche (getDay() === 0)
  for (let i = 0; i < 7; i++) {
    const d = new Date(sunday)
    d.setDate(d.getDate() + effectiveFirstDay.value + i)
    days.push(d.toLocaleDateString(locale.value, { weekday: format }))
  }

  return days
}
const dayNames = computed(() => weekdayLabels('short'))
const dayNamesLong = computed(() => weekdayLabels('long'))

const formatTime = timeStr => {
  if (!timeStr) return ''
  const parts = timeStr.split(':')

  return `${parseInt(parts[0])}h${parts[1] !== '00' ? parts[1] : ''}`
}

// Extrait l'heure locale HH:MM d'un datetime ISO/SQL sans conversion de fuseau (heure murale stockée).
const timeOfDay = dt => {
  if (!dt) return ''
  const m = String(dt).match(/[T ](\d{2}):(\d{2})/)

  return m ? `${m[1]}:${m[2]}` : ''
}

const formatShiftRange = shift => {
  if (!shift) return ''

  return `${timeOfDay(shift.start_at)}–${timeOfDay(shift.end_at)}`
}

// Couleur/état d'un chip de shift : couleur du modèle (snapshot) ; brouillon = contour, publié = plein.
const shiftColor = shift => shift?.template_snapshot?.color || shift?.template_color || (shift?.status === 'published' ? 'success' : 'secondary')
const shiftVariant = shift => {
  if (shift?.status === 'cancelled') return 'tonal'
  if (shift?.status === 'draft') return 'outlined'

  return 'flat'
}
const shiftActivityCount = shift => shift?.scheduled_activities_count ?? shift?.activities_count ?? 0

// Nom du POSTE/mission d'un shift (bloc métier) : catégorie de couverture > modèle > type d'activité.
const categoryNameById = id => store.coverageCategories.find(c => c.id === id)?.name
const shiftPoste = shift => categoryNameById(shift?.template_snapshot?.coverage_category_id)
  || shift?.template_snapshot?.template_name
  || shift?.template_snapshot?.activity_type
  || t('planning.shift.untitled')

// Résumé par collaborateur : heures planifiées + jours travaillés (depuis les shifts de la ligne).
const rowSummary = row => {
  let mins = 0
  let days = 0
  Object.values(row.shifts || {}).forEach(list => {
    const active = list.filter(s => s.status !== 'cancelled')
    if (active.length) days++
    active.forEach(s => {
      const d = (new Date(s.end_at) - new Date(s.start_at)) / 60000
      const brk = s.template_snapshot?.break_duration_minutes || 0
      if (d > 0) mins += Math.max(0, d - brk)
    })
  })

  return { hours: (mins / 60).toFixed(mins % 60 === 0 ? 0 : 1), days }
}
const shiftHex = shift => shift?.template_snapshot?.color || shift?.template_color
  || (shift?.status === 'published' ? '#1f8a54' : shift?.status === 'cancelled' ? '#c23b3b' : '#8691a8')

// ══════════════════════════════════════════════════════════════
// TAB 1 — PLANNING (Weekly Grid)
// ══════════════════════════════════════════════════════════════

// ── Week navigation ────────────────────────────────────────
// Début de semaine générique : ramène `d` au premier jour de semaine effectif (lun/dim/autre).
function getWeekStart(d, firstDay = 1) {
  const date = new Date(d)
  date.setHours(0, 0, 0, 0)
  const diff = (date.getDay() - firstDay + 7) % 7
  date.setDate(date.getDate() - diff)

  return date
}

const currentWeekStart = ref(getWeekStart(new Date(), 1))

// Quand la préférence Company est résolue (ou change), on recale la semaine affichée sur ce premier jour.
watch(effectiveFirstDay, fd => { currentWeekStart.value = getWeekStart(currentWeekStart.value, fd) })

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
  currentWeekStart.value = getWeekStart(new Date(), effectiveFirstDay.value)
}

// ── Data loading ────────────────────────────────────────────
const gridLoading = ref(false)

const fetchWeekData = async () => {
  gridLoading.value = true
  try {
    await Promise.all([
      store.fetchWeekShifts(weekDateIso.value),
      store.fetchStatistics({ from: weekDates.value[0], to: weekDates.value[6] }),
      loadCoverage(),
      loadAbsences(),
    ])
  } finally {
    gridLoading.value = false
  }
}

// ── Indisponibilités (Lot 3 §3, ADR-716) ────────────────────
// RÉUTILISE le tableau Planning (W-G4A) comme PROJECTION autorisée des congés/absences du module RH
// (LeaveRequest) — pas de 2ᵉ table de congés dans Planning. On n'affiche QUE le TYPE (jamais le motif
// médical détaillé : le board n'expose pas le champ `reason`). On distingue CONFIRMÉ (approved/consumed,
// opposable) de EN ATTENTE (pending, non opposable → n'altère pas la couverture confirmée).
const weekAbsences = ref([])
const loadAbsences = async () => {
  try {
    const resp = await store.fetchBoard({ from: weekDates.value[0], to: weekDates.value[6] })
    weekAbsences.value = resp.absences ?? []
  } catch {
    weekAbsences.value = []
  }
}

// Map employeeId → dayIdx → [absences] (une absence jour-granulaire peut couvrir plusieurs jours).
const absencesByEmployeeDay = computed(() => {
  const map = {}
  weekAbsences.value.forEach(a => {
    weekDates.value.forEach((date, dayIdx) => {
      // board renvoie `end` exclusif (FullCalendar) → borne haute = end - 1 jour, donc date < end.
      if (date >= a.start && date < a.end) {
        const empId = a.employee_id
        if (!map[empId]) map[empId] = {}
        if (!map[empId][dayIdx]) map[empId][dayIdx] = []
        map[empId][dayIdx].push(a)
      }
    })
  })

  return map
})
const absencesFor = (employeeId, dayIdx) => absencesByEmployeeDay.value[employeeId]?.[dayIdx] ?? []
const isConfirmedAbsence = a => a.status === 'approved' || a.status === 'consumed'
const absenceChipColor = a => (isConfirmedAbsence(a) ? 'error' : 'warning')
const absenceChipLabel = a => {
  const win = (a.start_time || a.end_time) ? ` ${a.start_time || ''}–${a.end_time || ''}` : ''

  return (a.leave_type || t('planning.absence.generic')) + win
    + (isConfirmedAbsence(a) ? '' : ' · ' + t('planning.absence.pending'))
}
// Un shift publié est-il impacté par une absence CONFIRMÉE du collaborateur ce jour-là ?
const shiftHasConfirmedAbsence = (employeeId, dayIdx) =>
  absencesFor(employeeId, dayIdx).some(isConfirmedAbsence)

// ── Remplacement guidé (Lot 3 §2, ADR-717) ──────────────────
const replacementOpen = ref(false)
const replacementLoading = ref(false)
const replacementFetching = ref(false)
const replacementShift = ref(null)
const replacementData = ref({ candidates: [], window: null, unassured: [], coverage_category_id: null })
const replacementTarget = ref(null)
const replacementWindow = ref({ start: '', end: '' })
const replacementAvailColor = s => ({ available: 'success', to_verify: 'warning', busy: 'secondary', unavailable: 'error' }[s] || 'secondary')

const openReplacement = async shift => {
  replacementShift.value = shift
  replacementTarget.value = null
  replacementOpen.value = true
  replacementFetching.value = true
  try {
    const data = await store.fetchReplacementCandidates(shift.id)
    replacementData.value = data
    replacementWindow.value = { start: data.window?.start || shift.start_at, end: data.window?.end || shift.end_at }
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
    replacementOpen.value = false
  } finally {
    replacementFetching.value = false
  }
}
const confirmReplacement = async () => {
  if (!replacementTarget.value) return
  replacementLoading.value = true
  try {
    await store.replaceShift(replacementShift.value.id, {
      employeeId: replacementTarget.value,
      startAt: timeOfDay(replacementWindow.value.start)
        ? `${replacementShift.value.date?.split('T')[0] || replacementShift.value.date} ${timeOfDay(replacementWindow.value.start)}`
        : replacementWindow.value.start,
      endAt: timeOfDay(replacementWindow.value.end)
        ? `${replacementShift.value.date?.split('T')[0] || replacementShift.value.date} ${timeOfDay(replacementWindow.value.end)}`
        : replacementWindow.value.end,
    })
    toast(t('planning.replacement.done'), 'success')
    replacementOpen.value = false
    fetchWeekData()
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  } finally {
    replacementLoading.value = false
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

// SP1a.1 §4 — le endpoint /shifts/week renvoie une liste PLATE de shifts (et non des entrées groupées par
// employé). On groupe donc par employé, en semant une ligne pour CHAQUE collaborateur (même sans shift) afin
// de permettre l'affectation sur cellule vide. Supporte aussi un éventuel format déjà groupé (rétro-compat).
const pushShiftToRow = (row, shift) => {
  const shiftDate = shift.date?.split('T')[0] || shift.date
  const dayIdx = weekDates.value.indexOf(shiftDate)
  if (dayIdx !== -1) {
    if (!row.shifts[dayIdx]) row.shifts[dayIdx] = []
    row.shifts[dayIdx].push(shift)
  }
}

const gridRows = computed(() => {
  const data = store.weekShifts
  if (!Array.isArray(data)) return []

  const rows = new Map()
  const ensure = (employee, employeeId) => {
    if (!employeeId) return null
    if (!rows.has(employeeId)) rows.set(employeeId, { employee: employee || { id: employeeId }, employee_id: employeeId, shifts: {} })
    else if (employee && !rows.get(employeeId).employee?.first_name) rows.get(employeeId).employee = employee

    return rows.get(employeeId)
  }

  // Semences : tous les collaborateurs chargés (lignes assignables même vides).
  ;(employeesStore.employees || []).forEach(e => ensure(e, e.id))

  const grouped = data.length > 0 && data[0] && data[0].shifts !== undefined && data[0].start_at === undefined
  if (grouped) {
    data.forEach(entry => {
      const row = ensure(entry.employee, entry.employee?.id || entry.employee_id)
      if (row) (entry.shifts || []).forEach(s => pushShiftToRow(row, s))
    })
  } else {
    data.forEach(shift => {
      const row = ensure(shift.employee, shift.employee?.id || shift.employee_id)
      if (row) pushShiftToRow(row, shift)
    })
  }

  return [...rows.values()]
})

// ── Vue par collaborateurs / par postes (Skello-like, même moteur) ──
// Mapping explicite : un « poste » = une CATÉGORIE de couverture du shift (snapshot). Les shifts sans
// catégorie sont regroupés sous « Non catégorisé » (une catégorie n'est pas présumée être un poste RH).
const gridView = useCookie('planningGridView', { default: () => 'employees' })
const postRows = computed(() => {
  const groups = new Map()
  const data = Array.isArray(store.weekShifts) ? store.weekShifts : []
  data.filter(s => s && s.status !== 'cancelled').forEach(s => {
    const catId = s.template_snapshot?.coverage_category_id || null
    if (gridActivity.value && (s.template_snapshot?.activity_type ?? null) !== gridActivity.value) return
    if (gridLocation.value && s.work_location_id !== gridLocation.value) return
    if (gridStatus.value && s.status !== gridStatus.value) return
    const key = catId ?? 'none'
    if (!groups.has(key)) {
      groups.set(key, { key, label: categoryNameById(catId) || t('planning.posts.uncategorized'), color: shiftHex(s), days: {} })
    }
    const g = groups.get(key)
    const d = s.date?.split('T')[0] || s.date
    const idx = weekDates.value.indexOf(d)
    if (idx !== -1) {
      if (!g.days[idx]) g.days[idx] = []
      g.days[idx].push(s)
    }
  })

  return [...groups.values()]
})
const empInitials = s => (s.employee?.first_name?.[0] || '') + (s.employee?.last_name?.[0] || '')
const empName = s => `${s.employee?.first_name ?? ''} ${s.employee?.last_name ?? ''}`.trim()

// ── Vue JOUR mobile (§8) : navigation par jour + lignes collaborateurs avec blocs métier ──
const mobileDayIdx = ref(0)
const mobileDayDate = computed(() => weekDates.value[mobileDayIdx.value])
const mobileDayLabel = computed(() => {
  const d = mobileDayDate.value
  if (!d) return ''

  return new Date(d + 'T00:00:00').toLocaleDateString(locale.value, { weekday: 'long', day: 'numeric', month: 'long' })
})
const mobilePrevDay = () => {
  if (mobileDayIdx.value > 0) mobileDayIdx.value--
  else { prevWeek(); mobileDayIdx.value = 6 }
}
const mobileNextDay = () => {
  if (mobileDayIdx.value < 6) mobileDayIdx.value++
  else { nextWeek(); mobileDayIdx.value = 0 }
}
// Collaborateurs ayant un shift ce jour-là (pour la vue jour mobile).
const mobileDayRows = computed(() => filteredGridRows.value
  .filter(r => r.shifts[mobileDayIdx.value]?.length)
  .map(r => ({ employee: r.employee, employee_id: r.employee_id, shifts: r.shifts[mobileDayIdx.value] })))
// §3/§8 — indisponibilités du jour affiché (mobile), avec nom du collaborateur.
const mobileDayAbsences = computed(() => weekAbsences.value.filter(a => {
  const date = mobileDayDate.value

  return date && date >= a.start && date < a.end
}))

// ── Grid filters (Smart Planning SP1a) ──────────────────────
const gridDepartment = ref(null)
const gridLocation = ref(null)
const gridActivity = ref(null)
const gridStatus = ref(null)

const departmentItems = computed(() => employeesStore.departments.map(d => ({ title: d.name, value: d.id })))
const coverageCategoryItems = computed(() => store.coverageCategories.filter(c => c.enabled !== false).map(c => ({ title: c.name, value: c.id })))
const activityTypeItems = computed(() => {
  const set = new Set()
  store.allTemplates.forEach(t => { if (t.activity_type) set.add(t.activity_type) })

  return [...set].map(a => ({ title: a, value: a }))
})

const hasGridFilters = computed(() => !!(gridDepartment.value || gridLocation.value || gridActivity.value || gridStatus.value))
const clearGridFilters = () => { gridDepartment.value = null; gridLocation.value = null; gridActivity.value = null; gridStatus.value = null }

// Filtre les lignes par département (employé) et les shifts de chaque cellule par statut/lieu/activité.
const filteredGridRows = computed(() => {
  return gridRows.value
    .filter(row => !gridDepartment.value || row.employee?.department_id === gridDepartment.value)
    .map(row => {
      if (!gridLocation.value && !gridActivity.value && !gridStatus.value) return row
      const shifts = {}
      Object.entries(row.shifts).forEach(([dayIdx, list]) => {
        const kept = list.filter(s =>
          (!gridLocation.value || s.work_location_id === gridLocation.value)
          && (!gridStatus.value || s.status === gridStatus.value)
          && (!gridActivity.value || (s.template_snapshot?.activity_type ?? s.activity_type) === gridActivity.value))
        if (kept.length) shifts[dayIdx] = kept
      })

      return { ...row, shifts }
    })
})

// ── Coverage QUALIFIÉE (Lot A) — lignes par catégorie + édition inline ──────
const coverageDaily = ref({})
const coverageReqs = ref([])
const loadCoverage = async () => {
  try {
    const resp = await store.fetchCoverageSummary({ from: weekDates.value[0], to: weekDates.value[6] })
    coverageDaily.value = resp.daily ?? {}
    coverageReqs.value = resp.requirements ?? []
  } catch {
    coverageDaily.value = {}
    coverageReqs.value = []
  }
}
const hasCoverage = computed(() => coverageReqs.value.length > 0)

// Regroupe les besoins en LIGNES (par catégorie + fenêtre), chaque ligne portant une cellule par jour.
const coverageLines = computed(() => {
  const lines = new Map()
  coverageReqs.value.forEach(r => {
    const key = `${r.coverage_category_id ?? 'none'}|${r.start_time ?? ''}|${r.end_time ?? ''}`
    if (!lines.has(key)) {
      lines.set(key, {
        key,
        categoryId: r.coverage_category_id,
        label: r.category_name || r.label || t('planning.coverage.anyPresence'),
        color: r.category_color || null,
        window: r.start_time ? `${r.start_time.slice(0, 5)}–${(r.end_time || '').slice(0, 5)}` : null,
        byDate: {},
      })
    }
    lines.get(key).byDate[r.date] = r
  })

  return [...lines.values()]
})
const covCellColor = cell => {
  if (!cell) return 'default'
  if (cell.gap > 0) return 'error'
  if (cell.status === 'over') return 'warning'

  return 'success'
}

// Bandeau de couverture rétractable + synthèse compacte (état conservé en cookie).
const coverageCollapsed = useCookie('planningCoverageCollapsed', { default: () => false })
const coverageSummary = computed(() => {
  let under = 0
  let over = 0
  coverageReqs.value.forEach(r => {
    if (r.status === 'under') under++
    else if (r.status === 'over') over++
  })

  return { under, over }
})

// Édition inline d'un besoin (headcount) depuis la grille.
const covEdit = ref({ open: false, requirementId: null, headcount: 1, date: null, line: null })
const openCovEdit = (cell, line, date) => {
  covEdit.value = { open: true, requirementId: cell?.requirement_id ?? null, headcount: cell?.headcount ?? 1, date, line }
}
const saveCovEdit = async () => {
  try {
    if (covEdit.value.requirementId) {
      await store.updateCoverage(covEdit.value.requirementId, { headcount: Number(covEdit.value.headcount) })
    } else if (covEdit.value.line) {
      // Pas de besoin ce jour-là → en créer un pour ce jour de semaine, même catégorie/fenêtre.
      const dow = new Date(covEdit.value.date + 'T00:00:00').getDay()
      const sample = Object.values(covEdit.value.line.byDate)[0]
      await store.createCoverage({
        day_of_week: dow, headcount: Number(covEdit.value.headcount),
        coverage_category_id: covEdit.value.line.categoryId,
        start_time: sample?.start_time || undefined, end_time: sample?.end_time || undefined,
        priority: 'normal',
      })
    }
    covEdit.value.open = false
    await loadCoverage()
    toast(t('planning.coverage.updated'), 'success')
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  }
}
const replicateCovLine = async line => {
  const sample = Object.values(line.byDate).find(r => r.requirement_id)
  if (!sample) return
  try {
    const r = await store.replicateCoverage(sample.requirement_id, [1, 2, 3, 4, 5])
    toast(t('planning.coverage.replicated', { n: r.created }), 'success')
    await Promise.all([store.fetchCoverage(), loadCoverage()])
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  }
}

// ── SP1a.1 — copie de semaine, duplication, affectation rapide par modèle ──
const armedTemplateId = ref(null)
const armedTemplateItems = computed(() => store.allTemplates.filter(t => t.enabled !== false).map(t => ({ title: t.name, value: t.id })))

const copyPreviousWeek = async () => {
  const prev = new Date(currentWeekStart.value)
  prev.setDate(prev.getDate() - 7)
  const fromIso = prev.toISOString().slice(0, 10)
  try {
    const r = await store.copyWeek({ fromWeekStart: fromIso, toWeekStart: weekDateIso.value })
    toast(t('planning.copyWeek.done', { created: r.created, skipped: r.skipped }), r.created ? 'success' : 'warning')
    fetchWeekData()
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  }
}

const duplicateShiftAction = async shift => {
  try {
    await store.duplicateShift(shift.id, {})
    toast(t('planning.duplicateShift.done'), 'success')
    fetchWeekData()
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  }
}

// Clic sur cellule vide : si un modèle est « armé », affecte directement ; sinon ouvre le drawer.
const onEmptyCell = (employeeId, date) => {
  if (armedTemplateId.value) return quickAssign(employeeId, date)

  return openCreateShift(employeeId, date)
}
const quickAssign = async (employeeId, date) => {
  const tpl = store.allTemplates.find(t => t.id === armedTemplateId.value)
  if (!tpl) return openCreateShift(employeeId, date)
  try {
    await store.createShift({
      employee_id: employeeId, date,
      start_at: `${date} ${tpl.start_time}`, end_at: `${date} ${tpl.end_time}`,
      timezone: 'Europe/Paris', schedule_template_id: tpl.id, work_location_id: tpl.work_location_id,
    })
    toast(t('planning.quickAssign.done'), 'success')
    fetchWeekData()
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  }
}

// ── Day detail (multi-activités) — Smart Planning SP1b ───────
const activityDayOpen = ref(false)
const activityDayShift = ref(null)
const activityDayEmployee = ref('')
const openActivityDay = (shift, row) => {
  activityDayShift.value = shift
  activityDayEmployee.value = row?.employee ? `${row.employee.first_name ?? ''} ${row.employee.last_name ?? ''}`.trim() : ''
  activityDayOpen.value = true
}

// ── Coverage management drawer (besoins de personnel) ────────
const isCoverageDrawerOpen = ref(false)
const coverageFormLoading = ref(false)
const editingCoverage = ref(null)
const coverageMode = ref('recurrent') // 'recurrent' (jour de semaine) | 'ponctuel' (date)
const coverageModeItems = computed(() => [
  { title: t('planning.coverage.modeRecurrent'), value: 'recurrent' },
  { title: t('planning.coverage.modePonctuel'), value: 'ponctuel' },
])
const coverageForm = ref({ label: '', day_of_week: null, date: null, start_time: '', end_time: '', department_id: null, coverage_category_id: null, headcount: 1, priority: 'normal' })
const weekdayItems = computed(() => [
  { title: t('planning.coverage.weekdays.1'), value: 1 },
  { title: t('planning.coverage.weekdays.2'), value: 2 },
  { title: t('planning.coverage.weekdays.3'), value: 3 },
  { title: t('planning.coverage.weekdays.4'), value: 4 },
  { title: t('planning.coverage.weekdays.5'), value: 5 },
  { title: t('planning.coverage.weekdays.6'), value: 6 },
  { title: t('planning.coverage.weekdays.0'), value: 0 },
])
const priorityItems = computed(() => ['low', 'normal', 'high', 'critical'].map(p => ({ title: t(`planning.coverage.priorities.${p}`), value: p })))
const weekdayLabel = v => weekdayItems.value.find(w => w.value === v)?.title ?? '—'
const categoryName = id => store.coverageCategories.find(c => c.id === id)?.name
const coverageTitle = c => categoryName(c.coverage_category_id) || c.label || t('planning.coverage.anyPresence')
const coverageWhen = c => {
  const w = c.date
    ? new Date(c.date + 'T00:00:00').toLocaleDateString(locale.value, { weekday: 'short', day: 'numeric', month: 'short' })
    : weekdayLabel(c.day_of_week)
  const slot = c.start_time ? `${c.start_time.slice(0, 5)}–${(c.end_time || '').slice(0, 5)}` : t('planning.coverage.allDay')

  return `${w} · ${slot} · ${c.headcount}p`
}

const resetCoverageForm = () => {
  editingCoverage.value = null
  coverageMode.value = 'recurrent'
  coverageForm.value = { label: '', day_of_week: null, date: null, start_time: '', end_time: '', department_id: null, coverage_category_id: null, headcount: 1, priority: 'normal' }
}
const openCoverageManager = async () => {
  resetCoverageForm()
  isCoverageDrawerOpen.value = true
  await Promise.all([store.fetchCoverage(), store.fetchCoverageCategories()])
}

// Catalogue de catégories de couverture (Lot A).
const newCategory = ref({ name: '', code: '', color: '#4b4bd6' })
const addCategory = async () => {
  if (!newCategory.value.name || !newCategory.value.code) { toast(t('validation.required'), 'error'); return }
  try {
    await store.createCoverageCategory({ ...newCategory.value })
    toast(t('planning.coverage.categoryCreated'), 'success')
    newCategory.value = { name: '', code: '', color: '#4b4bd6' }
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  }
}
const removeCategory = async c => {
  try { await store.deleteCoverageCategory(c.id); toast(t('planning.coverage.categoryDeleted'), 'success') } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error') }
}
const editCoverage = c => {
  editingCoverage.value = c
  coverageMode.value = c.date ? 'ponctuel' : 'recurrent'
  coverageForm.value = {
    label: c.label || '', day_of_week: c.day_of_week, date: c.date ? c.date.slice(0, 10) : null,
    start_time: c.start_time ? c.start_time.slice(0, 5) : '', end_time: c.end_time ? c.end_time.slice(0, 5) : '',
    department_id: c.department_id || null, coverage_category_id: c.coverage_category_id || null, headcount: c.headcount || 1, priority: c.priority || 'normal',
  }
}
const submitCoverage = async () => {
  // Validation front : récurrent → jour requis ; ponctuel → date requise.
  if (coverageMode.value === 'recurrent' && (coverageForm.value.day_of_week === null || coverageForm.value.day_of_week === undefined)) {
    toast(t('planning.coverage.needWeekday'), 'error'); return
  }
  if (coverageMode.value === 'ponctuel' && !coverageForm.value.date) {
    toast(t('planning.coverage.needDate'), 'error'); return
  }
  coverageFormLoading.value = true
  try {
    const payload = { ...coverageForm.value }
    // Exclusivité date / jour selon le mode.
    if (coverageMode.value === 'ponctuel') { payload.day_of_week = null } else { payload.date = null }
    if (!payload.start_time) delete payload.start_time
    if (!payload.end_time) delete payload.end_time
    if (editingCoverage.value) {
      await store.updateCoverage(editingCoverage.value.id, payload)
      toast(t('planning.coverage.updated'), 'success')
    } else {
      await store.createCoverage(payload)
      toast(t('planning.coverage.created'), 'success')
    }
    resetCoverageForm()
    await Promise.all([store.fetchCoverage(), loadCoverage()])
  } catch (error) {
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
  } finally {
    coverageFormLoading.value = false
  }
}
const removeCoverage = async c => {
  try {
    await store.deleteCoverage(c.id)
    toast(t('planning.coverage.deleted'), 'success')
    await loadCoverage()
  } catch (error) {
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
  }
}

// ── Stats ───────────────────────────────────────────────────
const stats = computed(() => store.statistics || {})

// Synthèse de la semaine affichée, calculée depuis les shifts RÉELS (fiable, contrairement à l'endpoint
// statistics agrégé) : total / brouillons / publiés / heures planifiées.
const weekSummary = computed(() => {
  const list = Array.isArray(store.weekShifts) ? store.weekShifts.filter(s => s && s.status !== 'cancelled') : []
  let draft = 0
  let published = 0
  let mins = 0
  list.forEach(s => {
    if (s.status === 'draft') draft++
    else if (s.status === 'published') published++
    const d = (new Date(s.end_at) - new Date(s.start_at)) / 60000
    const brk = s.template_snapshot?.break_duration_minutes || 0
    if (d > 0) mins += Math.max(0, d - brk)
  })

  return { total: list.length, draft, published, hours: (mins / 60).toFixed(mins % 60 === 0 ? 0 : 1) }
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

// ── Publication avec aperçu + confirmation (§3) ──────────────
const publishDialog = ref(false)
const publishTargetIds = ref([])
const publishLoading = ref(false)
const publishPreview = computed(() => {
  const ids = new Set(publishTargetIds.value)
  const emp = new Set()
  gridRows.value.forEach(row => {
    Object.values(row.shifts).forEach(list => list.forEach(s => { if (ids.has(s.id)) emp.add(row.employee_id) }))
  })

  return { shifts: publishTargetIds.value.length, employees: emp.size }
})
const openPublish = ids => {
  publishTargetIds.value = [...new Set(ids)].filter(Boolean)
  if (!publishTargetIds.value.length) { toast(t('planning.publishNoDraft'), 'warning'); return }
  publishDialog.value = true
}
const confirmPublish = async () => {
  publishLoading.value = true
  try {
    await store.publishShifts(publishTargetIds.value)
    toast(t('planning.published'), 'success')
    selectedDraftIds.value = []
    publishDialog.value = false
    fetchWeekData()
  } catch (error) {
    // Publication atomique : un conflit annule tout → message serveur explicite.
    toast((error.data?.message || error.response?._data?.message) || t('planning.publishConflict'), 'error')
  } finally {
    publishLoading.value = false
  }
}
const publishSelected = () => openPublish(selectedDraftIds.value)
const publishWeek = () => openPublish(allDraftShiftIds.value)

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

// ANO-009: the time inputs are type="time" and need "HH:MM", but the backend stores
// start_at/end_at as full datetimes. Extract the time part for display, and (on submit)
// recombine with the picked date into a full datetime the backend's `date` rule accepts.
const toTimeInput = v => {
  if (!v) return ''
  const m = String(v).match(/[T\s](\d{2}:\d{2})/)
  if (m) return m[1]

  return /^\d{2}:\d{2}/.test(v) ? v.slice(0, 5) : ''
}

const combineDateTime = (date, time) => {
  if (!date || !time) return time || null
  if (/^\d{4}-\d{2}-\d{2}[T ]/.test(time)) return time // already a full datetime

  return `${date}T${time}:00`
}

const openEditShift = shift => {
  editingShift.value = shift
  shiftFormData.value = {
    employee_id: shift.employee_id,
    date: shift.date?.split('T')[0] || shift.date || '',
    start_at: toTimeInput(shift.start_at),
    end_at: toTimeInput(shift.end_at),
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
      start_at: combineDateTime(shiftFormData.value.date, shiftFormData.value.start_at),
      end_at: combineDateTime(shiftFormData.value.date, shiftFormData.value.end_at),
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
  code: '',
  activity_type: '',
  coverage_category_id: null,
  start_time: '',
  end_time: '',
  break_duration_minutes: 0,
  color: '#1976D2',
  icon: '',
  work_location_id: null,
  department_id: null,
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
    code: '',
    activity_type: '',
    coverage_category_id: null,
  coverage_category_id: null,
    start_time: '',
    end_time: '',
    break_duration_minutes: 0,
    color: '#1976D2',
    icon: '',
    work_location_id: null,
    department_id: null,
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
    code: tpl.code || '',
    activity_type: tpl.activity_type || '',
    coverage_category_id: tpl.coverage_category_id || null,
    start_time: tpl.start_time || '',
    end_time: tpl.end_time || '',
    break_duration_minutes: tpl.break_duration_minutes ?? 0,
    color: tpl.color || '#1976D2',
    icon: tpl.icon || '',
    work_location_id: tpl.work_location_id || null,
    department_id: tpl.department_id || null,
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
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
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
    deleteLocationDialog.value = false
  }
}

// ══════════════════════════════════════════════════════════════
// INIT
// ══════════════════════════════════════════════════════════════
onMounted(async () => {
  employeesStore.fetchDepartments() // W-G4A : options de filtre du calendrier
  await Promise.all([
    fetchWeekData(),
    store.fetchTemplates({ all: true }),
    store.fetchLocations({ all: true }),
    store.fetchCoverageCategories(),
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
      <div v-if="!embedded">
        <h4 class="text-h4 font-weight-bold">
          {{ $t('planning.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('planning.subtitle') }}
        </p>
      </div>
      <VSpacer v-else />
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
          data-test="shift-add"
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
      </div>
    </div>

    <!-- E3-B/B2 (réserve B1 #2) — sélecteur de PRÉSENTATION compact et LIBELLÉ (segmented control, pas
         une 2ᵉ barre de navigation à icônes sous les 4 activités). Les libellés lèvent toute ambiguïté. -->
    <div class="d-inline-flex rounded-lg border pa-1 mb-5 planning-view-switch">
      <VBtn
        v-for="s in planningSections"
        :key="s.value"
        :variant="activeTab === s.value ? 'flat' : 'text'"
        :color="activeTab === s.value ? 'primary' : 'medium-emphasis'"
        size="small"
        class="text-none"
        :prepend-icon="s.icon"
        :data-test="`plan-view-${s.value}`"
        @click="activeTab = s.value"
      >
        {{ s.label }}
      </VBtn>
    </div>

    <VWindow v-model="activeTab">
      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: CALENDRIER (W-G4A : shifts + absences + conflits) -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="calendar">
        <VCard>
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
              <VCol cols="12" sm="6" md="4">
                <EmployeePicker
                  v-model="calEmployee"
                  :label="$t('leaves.columns.employee')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="calStatus"
                  :items="calStatusOptions"
                  :placeholder="$t('planning.fields.status')"
                  clearable
                  density="compact"
                />
              </VCol>
            </VRow>
            <!-- Légende -->
            <div class="d-flex gap-4 flex-wrap mt-1 text-caption align-center">
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="background:#7367f0" />{{ $t('workforce.statuses.shift.published', 'Publié') }}</span>
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="background:#a8aaae" />{{ $t('workforce.statuses.shift.draft', 'Brouillon') }}</span>
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="background:#82868b" />🌴 {{ $t('planning.calendar.absence') }}</span>
              <span class="d-flex align-center gap-1"><span class="legend-dot" style="border:2px solid #ea5455;background:transparent" />{{ $t('planning.calendar.conflict') }}</span>
            </div>
          </VCardText>

          <VAlert
            v-if="calConflicts.length"
            type="warning"
            variant="tonal"
            density="compact"
            class="mx-4 mb-2"
          >
            {{ $t('planning.calendar.conflictsBanner', { count: calConflicts.length }) }}
          </VAlert>

          <VDivider />
          <VCardText>
            <PlanningCalendar
              ref="calRef"
              :filters="calFilters"
              :first-day="weekStartDay"
              :editable="canEditShifts"
              @select="onSelectEvent"
              @conflicts="calConflicts = $event"
              @move="onShiftMove"
            />
          </VCardText>
        </VCard>

        <!-- Détail shift / absence (lecture seule) -->
        <VDialog
          v-model="calDetailOpen"
          max-width="440"
        >
          <VCard v-if="calSelected">
            <VCardItem>
              <VCardTitle>{{ calSelected.employee_name }}</VCardTitle>
              <VCardSubtitle>
                {{ calSelected.kind === 'shift' ? $t('planning.calendar.shift') : calSelected.leave_type }}
              </VCardSubtitle>
              <template #append>
                <VChip
                  size="small"
                  variant="tonal"
                  :color="calSelected.has_conflict ? 'error' : (calSelected.is_published === false ? 'secondary' : 'primary')"
                >
                  {{ calSelected.kind === 'shift' ? $t('workforce.statuses.shift.' + calSelected.status, calSelected.status) : $t('workforce.statuses.leave.' + calSelected.status, calSelected.status) }}
                </VChip>
              </template>
            </VCardItem>
            <VCardText>
              <template v-if="calSelected.kind === 'shift'">
                <div class="d-flex justify-space-between mb-1"><span class="text-medium-emphasis">{{ $t('planning.fields.start') }}</span><span>{{ fmtDateTime(calSelected.start) }}</span></div>
                <div class="d-flex justify-space-between mb-1"><span class="text-medium-emphasis">{{ $t('planning.fields.end') }}</span><span>{{ fmtDateTime(calSelected.end) }}</span></div>
                <div v-if="calSelected.location" class="d-flex justify-space-between mb-1"><span class="text-medium-emphasis">{{ $t('planning.fields.location') }}</span><span>{{ calSelected.location }}</span></div>
                <div class="d-flex justify-space-between"><span class="text-medium-emphasis">{{ $t('planning.calendar.plannedMinutes') }}</span><span>{{ calSelected.planned_minutes }} min</span></div>
                <VAlert v-if="calSelected.has_conflict" type="error" variant="tonal" density="compact" class="mt-3">
                  {{ $t('planning.calendar.conflictDetail') }}
                </VAlert>
              </template>
              <template v-else>
                <div class="d-flex justify-space-between mb-1"><span class="text-medium-emphasis">{{ $t('leaves.calendar.period') }}</span><span>{{ fmtTime(calSelected.start) ? calSelected.start : '' }} {{ calSelected.start }} → {{ calSelected.end }}</span></div>
              </template>
            </VCardText>
            <VCardText class="d-flex justify-end gap-3 pt-0">
              <VBtn variant="outlined" color="secondary" @click="calDetailOpen = false">
                {{ $t('common.close') }}
              </VBtn>
              <!-- Résolution/réaffectation : brouillons uniquement (publié figé) -->
              <VBtn
                v-if="calSelected.kind === 'shift' && calSelected.status === 'draft' && canEditShifts"
                color="primary"
                prepend-icon="tabler-user-check"
                @click="openReassign"
              >
                {{ calSelected.has_conflict ? $t('planning.ops.resolve') : $t('planning.ops.reassign') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VDialog>

        <!-- Réaffectation guidée (suggestions de disponibilité) -->
        <VDialog
          v-model="reassignOpen"
          max-width="520"
        >
          <VCard>
            <VCardItem>
              <VCardTitle>{{ $t('planning.ops.reassignTitle') }}</VCardTitle>
              <VCardSubtitle>{{ $t('planning.ops.reassignHint') }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VRadioGroup v-model="reassignTarget">
                <VList
                  density="compact"
                  max-height="300"
                >
                  <VListItem
                    v-for="c in reassignCandidates"
                    :key="c.employee_id"
                    :disabled="c.availability === 'unavailable' || c.availability === 'busy'"
                    @click="(c.availability === 'available' || c.availability === 'to_verify') && (reassignTarget = c.employee_id)"
                  >
                    <template #prepend>
                      <VRadio
                        :value="c.employee_id"
                        :disabled="c.availability === 'unavailable' || c.availability === 'busy'"
                      />
                    </template>
                    <VListItemTitle>{{ c.employee_name }}<span v-if="c.job_role" class="text-caption text-medium-emphasis"> · {{ c.job_role }}</span></VListItemTitle>
                    <template #append>
                      <VChip
                        :color="availColor(c.availability)"
                        size="x-small"
                        variant="tonal"
                      >
                        {{ $t('planning.availability.' + c.availability) }}
                      </VChip>
                    </template>
                  </VListItem>
                </VList>
              </VRadioGroup>
            </VCardText>
            <VCardText class="d-flex justify-end gap-3 pt-0">
              <VBtn variant="outlined" color="secondary" @click="reassignOpen = false">
                {{ $t('common.cancel') }}
              </VBtn>
              <VBtn
                color="primary"
                :loading="reassignLoading"
                :disabled="!reassignTarget"
                @click="confirmReassign"
              >
                {{ $t('planning.ops.confirmReassign') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VDialog>

        <!-- Confirmation de déplacement (drag-and-drop) -->
        <VDialog
          v-model="moveDialog"
          max-width="420"
          persistent
        >
          <VCard v-if="pendingMove">
            <VCardItem>
              <VCardTitle>{{ $t('planning.ops.moveTitle') }}</VCardTitle>
            </VCardItem>
            <VCardText>
              <p class="mb-2">
                {{ pendingMove.shift.employee_name }}
              </p>
              <div class="d-flex justify-space-between"><span class="text-medium-emphasis">{{ $t('planning.fields.start') }}</span><span>{{ fmtDateTime(pendingMove.start) }}</span></div>
              <div class="d-flex justify-space-between"><span class="text-medium-emphasis">{{ $t('planning.fields.end') }}</span><span>{{ fmtDateTime(pendingMove.end) }}</span></div>
            </VCardText>
            <VCardText class="d-flex justify-end gap-3 pt-0">
              <VBtn variant="outlined" color="secondary" :disabled="moveLoading" @click="cancelMove">
                {{ $t('common.cancel') }}
              </VBtn>
              <VBtn color="primary" :loading="moveLoading" @click="confirmMove">
                {{ $t('planning.ops.confirmMove') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VDialog>
      </VWindowItem>

      <!-- ═══════════════════════════════════════════════════════ -->
      <!-- TAB: PLANNING (Weekly Grid)                            -->
      <!-- ═══════════════════════════════════════════════════════ -->
      <VWindowItem value="planning">
        <!-- §8 — VUE JOUR MOBILE (pas de grille desktop compressée) -->
        <div v-if="smAndDown">
          <VCard>
            <VCardText class="d-flex align-center justify-space-between py-3">
              <VBtn
                icon
                variant="text"
                size="small"
                @click="mobilePrevDay"
              >
                <VIcon icon="tabler-chevron-left" />
              </VBtn>
              <div class="text-center">
                <div class="text-body-1 font-weight-medium text-capitalize">
                  {{ mobileDayLabel }}
                </div>
                <div
                  v-if="hasCoverage"
                  class="text-caption"
                >
                  <span :class="coverageSummary.under ? 'text-error' : 'text-success'">
                    {{ coverageSummary.under ? $t('planning.coverage.summaryUnder', { n: coverageSummary.under }) : $t('planning.coverage.summaryOk') }}
                  </span>
                </div>
              </div>
              <VBtn
                icon
                variant="text"
                size="small"
                @click="mobileNextDay"
              >
                <VIcon icon="tabler-chevron-right" />
              </VBtn>
            </VCardText>
            <VDivider />
            <VCardText>
              <div
                v-if="!mobileDayRows.length && !mobileDayAbsences.length"
                class="text-center text-medium-emphasis py-6"
              >
                {{ $t('planning.mobile.noShift') }}
              </div>
              <!-- Indisponibilités du jour (projection RH) -->
              <div
                v-if="mobileDayAbsences.length"
                class="mb-3 d-flex flex-wrap gap-2"
              >
                <VChip
                  v-for="abs in mobileDayAbsences"
                  :key="abs.id"
                  :color="absenceChipColor(abs)"
                  size="small"
                  label
                  :variant="isConfirmedAbsence(abs) ? 'flat' : 'tonal'"
                >
                  <VIcon icon="tabler-plane-departure" size="13" start />
                  {{ abs.employee_name }} · {{ absenceChipLabel(abs) }}
                </VChip>
              </div>
              <div
                v-for="row in mobileDayRows"
                :key="row.employee_id"
                class="mb-3"
              >
                <div class="d-flex align-center gap-2 mb-1">
                  <VAvatar
                    color="primary"
                    variant="tonal"
                    size="28"
                  >
                    <span class="text-xs">{{ (row.employee?.first_name?.[0] || '') + (row.employee?.last_name?.[0] || '') }}</span>
                  </VAvatar>
                  <span class="font-weight-medium">{{ row.employee?.first_name }} {{ row.employee?.last_name }}</span>
                </div>
                <button
                  v-for="shift in row.shifts"
                  :key="shift.id"
                  type="button"
                  class="plan-shift__body plan-shift__body--block mb-1"
                  :style="{ '--sc': shiftHex(shift) }"
                  @click="openActivityDay(shift, row)"
                >
                  <span class="plan-shift__poste">
                    {{ shiftPoste(shift) }}
                    <span
                      v-if="shiftActivityCount(shift)"
                      class="plan-shift__badge"
                    >+{{ shiftActivityCount(shift) }}</span>
                  </span>
                  <span class="plan-shift__time">{{ formatShiftRange(shift) }} · {{ shift.status === 'published' ? $t('workforce.statuses.shift.published', 'Publié') : $t('workforce.statuses.shift.draft', 'Brouillon') }}</span>
                </button>
              </div>
            </VCardText>
          </VCard>
        </div>

        <!-- Stats Row -->
        <!-- Synthèse compacte de la semaine (remplace les 4 grandes cartes) -->
        <div
          v-if="!smAndDown"
          class="d-flex flex-wrap align-center gap-x-6 gap-y-2 mb-4 px-1"
        >
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-calendar-stats" size="18" class="text-medium-emphasis" />
            <span class="text-h6 mb-0">{{ weekSummary.total }}</span>
            <span class="text-body-2 text-medium-emphasis">{{ $t('planning.stats.totalShifts') }}</span>
          </div>
          <VDivider vertical class="my-1" />
          <div class="d-flex align-center gap-2">
            <span class="legend-dot" style="background: rgb(var(--v-theme-success));" />
            <span class="text-body-1 font-weight-medium">{{ weekSummary.published }}</span>
            <span class="text-body-2 text-medium-emphasis">{{ $t('planning.stats.published') }}</span>
          </div>
          <div class="d-flex align-center gap-2">
            <span class="legend-dot" style="background: rgb(var(--v-theme-warning));" />
            <span class="text-body-1 font-weight-medium">{{ weekSummary.draft }}</span>
            <span class="text-body-2 text-medium-emphasis">{{ $t('planning.stats.draft') }}</span>
          </div>
          <VDivider vertical class="my-1" />
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-clock" size="18" class="text-medium-emphasis" />
            <span class="text-body-1 font-weight-medium">{{ weekSummary.hours }}h</span>
            <span class="text-body-2 text-medium-emphasis">{{ $t('planning.stats.hoursPlanned') }}</span>
          </div>
        </div>

        <!-- Week Navigation + Grid (desktop) -->
        <VCard v-if="!smAndDown">
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
                <VBtnToggle
                  v-model="gridView"
                  mandatory
                  density="compact"
                  variant="outlined"
                  divided
                  class="me-1"
                >
                  <VBtn value="employees" size="small" data-test="view-employees">
                    <VIcon icon="tabler-users" size="16" class="me-1" /> {{ $t('planning.views.byEmployee') }}
                  </VBtn>
                  <VBtn value="posts" size="small" data-test="view-posts">
                    <VIcon icon="tabler-briefcase" size="16" class="me-1" /> {{ $t('planning.views.byPost') }}
                  </VBtn>
                </VBtnToggle>
                <VBtn
                  v-if="allDraftShiftIds.length"
                  v-can="'workforce.planning_publish'"
                  variant="tonal"
                  color="success"
                  size="small"
                  prepend-icon="tabler-send"
                  data-test="publish-week"
                  @click="publishWeek"
                >
                  {{ $t('planning.publishWeek') }}
                </VBtn>
                <VBtn
                  v-can="'workforce.planning_manage'"
                  variant="text"
                  size="small"
                  prepend-icon="tabler-copy"
                  data-test="copy-prev-week"
                  @click="copyPreviousWeek"
                >
                  {{ $t('planning.copyWeek.button') }}
                </VBtn>
                <VBtn
                  v-can="'workforce.coverage_manage'"
                  variant="text"
                  size="small"
                  prepend-icon="tabler-users-group"
                  @click="openCoverageManager"
                >
                  {{ $t('planning.coverage.manage') }}
                </VBtn>
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
                <VBtn
                  v-if="selectedDraftIds.length > 0"
                  v-can="'workforce.planning_publish'"
                  color="success"
                  variant="flat"
                  size="small"
                  prepend-icon="tabler-send"
                  data-test="publish-selection"
                  @click="publishSelected"
                >
                  {{ $t('planning.publishSelection', { n: selectedDraftIds.length }) }}
                </VBtn>
              </div>
            </div>

            <!-- Grid filters (Smart Planning SP1a) -->
            <div class="d-flex flex-wrap align-center gap-3 mt-4">
              <AppSelect
                v-model="gridDepartment"
                :items="departmentItems"
                :label="$t('planning.filters.department')"
                density="compact"
                clearable
                hide-details
                style="min-inline-size: 160px;"
              />
              <AppSelect
                v-model="gridLocation"
                :items="locationItems"
                :label="$t('planning.filters.location')"
                density="compact"
                clearable
                hide-details
                style="min-inline-size: 160px;"
              />
              <AppSelect
                v-model="gridActivity"
                :items="activityTypeItems"
                :label="$t('planning.filters.activity')"
                density="compact"
                clearable
                hide-details
                style="min-inline-size: 160px;"
              />
              <AppSelect
                v-model="gridStatus"
                :items="calStatusOptions"
                :label="$t('planning.filters.status')"
                density="compact"
                clearable
                hide-details
                style="min-inline-size: 150px;"
              />
              <VBtn
                v-if="hasGridFilters"
                variant="text"
                size="small"
                prepend-icon="tabler-filter-off"
                @click="clearGridFilters"
              >
                {{ $t('planning.filters.clear') }}
              </VBtn>
              <VSpacer />
              <AppSelect
                v-model="armedTemplateId"
                :items="armedTemplateItems"
                :label="$t('planning.quickAssign.arm')"
                :hint="$t('planning.quickAssign.hint')"
                persistent-hint
                density="compact"
                clearable
                prepend-inner-icon="tabler-click"
                style="min-inline-size: 220px;"
              />
            </div>
          </VCardText>

          <VDivider />

          <!-- Weekly Grid Table -->
          <div class="planning-grid-wrap">
            <VTable
              density="compact"
              :loading="gridLoading"
              class="planning-grid"
            >
              <thead>
              <tr class="day-header">
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
              <!-- Bandeau couverture : bascule rétractable + synthèse compacte -->
              <tr
                v-if="hasCoverage"
                class="coverage-row coverage-toggle"
              >
                <th
                  :colspan="8"
                  class="text-start"
                >
                  <div class="d-flex align-center gap-2">
                    <VBtn
                      icon
                      variant="text"
                      size="x-small"
                      data-test="cov-collapse"
                      @click="coverageCollapsed = !coverageCollapsed"
                    >
                      <VIcon :icon="coverageCollapsed ? 'tabler-chevron-right' : 'tabler-chevron-down'" size="16" />
                    </VBtn>
                    <span class="text-caption font-weight-medium text-uppercase">{{ $t('planning.coverage.title') }}</span>
                    <VChip
                      v-if="coverageSummary.under"
                      size="x-small"
                      color="error"
                      variant="tonal"
                    >
                      {{ $t('planning.coverage.summaryUnder', { n: coverageSummary.under }) }}
                    </VChip>
                    <VChip
                      v-if="coverageSummary.over"
                      size="x-small"
                      color="warning"
                      variant="tonal"
                    >
                      {{ $t('planning.coverage.summaryOver', { n: coverageSummary.over }) }}
                    </VChip>
                    <VChip
                      v-if="!coverageSummary.under && !coverageSummary.over"
                      size="x-small"
                      color="success"
                      variant="tonal"
                    >
                      {{ $t('planning.coverage.summaryOk') }}
                    </VChip>
                  </div>
                </th>
              </tr>
              <!-- Couverture QUALIFIÉE : une ligne par catégorie (affecté/requis, écart, édition inline) -->
              <tr
                v-for="line in coverageLines"
                v-show="!coverageCollapsed"
                :key="`cov-${line.key}`"
                class="coverage-row"
              >
                <th class="text-start">
                  <div class="d-flex align-center gap-2">
                    <span
                      v-if="line.color"
                      class="legend-dot"
                      :style="{ background: line.color }"
                    />
                    <span
                      class="text-caption font-weight-medium text-truncate"
                      style="max-inline-size: 110px;"
                      :title="line.label"
                    >{{ line.label }}</span>
                    <span
                      v-if="line.window"
                      class="text-caption text-disabled"
                    >{{ line.window }}</span>
                    <VBtn
                      v-can="'workforce.coverage_manage'"
                      icon
                      variant="text"
                      size="x-small"
                      :title="$t('planning.coverage.replicateWeekdays')"
                      @click="replicateCovLine(line)"
                    >
                      <VIcon icon="tabler-repeat" size="13" />
                    </VBtn>
                  </div>
                </th>
                <th
                  v-for="date in weekDates"
                  :key="`cov-${line.key}-${date}`"
                  class="text-center"
                >
                  <VChip
                    v-if="line.byDate[date]"
                    :color="covCellColor(line.byDate[date])"
                    size="x-small"
                    variant="tonal"
                    class="cursor-pointer"
                    :title="$t('planning.coverage.editHint')"
                    @click="openCovEdit(line.byDate[date], line, date)"
                  >
                    {{ line.byDate[date].assigned }}/{{ line.byDate[date].headcount }}
                  </VChip>
                  <VBtn
                    v-else-if="can('workforce.coverage_manage')"
                    icon
                    variant="text"
                    size="x-small"
                    class="cov-add-btn"
                    :title="$t('planning.coverage.addHere')"
                    @click="openCovEdit(null, line, date)"
                  >
                    <VIcon icon="tabler-plus" size="13" />
                  </VBtn>
                  <span
                    v-else
                    class="text-disabled text-caption"
                  >—</span>
                </th>
              </tr>
            </thead>
            <tbody v-if="gridView === 'employees'">
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
                v-for="row in filteredGridRows"
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
                    <div class="d-flex flex-column" style="min-inline-size: 0;">
                      <span class="font-weight-medium text-truncate">
                        {{ row.employee?.first_name }} {{ row.employee?.last_name }}
                      </span>
                      <span
                        v-if="rowSummary(row).days"
                        class="text-caption text-medium-emphasis"
                      >{{ $t('planning.rowSummary', { hours: rowSummary(row).hours, days: rowSummary(row).days }) }}</span>
                    </div>
                  </div>
                </td>

                <!-- Day columns -->
                <td
                  v-for="(date, dayIdx) in weekDates"
                  :key="date"
                  class="text-center planning-cell"
                  :class="{ 'bg-light': date === new Date().toISOString().slice(0, 10) }"
                  @click="!row.shifts[dayIdx]?.length && !absencesFor(row.employee_id, dayIdx).length ? onEmptyCell(row.employee_id, date) : null"
                >
                  <!-- Indisponibilité (congé/absence) — projection RH, type seul, confirmé vs en attente -->
                  <VChip
                    v-for="abs in absencesFor(row.employee_id, dayIdx)"
                    :key="abs.id"
                    :color="absenceChipColor(abs)"
                    size="x-small"
                    label
                    :variant="isConfirmedAbsence(abs) ? 'flat' : 'tonal'"
                    class="mb-1 plan-absence"
                    :title="absenceChipLabel(abs) + (row.shifts[dayIdx]?.length ? ' · ' + $t('planning.absence.conflict') : '')"
                  >
                    <VIcon
                      :icon="row.shifts[dayIdx]?.length ? 'tabler-alert-triangle' : 'tabler-plane-departure'"
                      size="12"
                      start
                    />
                    {{ absenceChipLabel(abs) }}
                  </VChip>
                  <template v-if="row.shifts[dayIdx]?.length">
                    <div
                      v-for="shift in row.shifts[dayIdx]"
                      :key="shift.id"
                      class="plan-shift"
                      :class="`plan-shift--${shift.status}`"
                    >
                      <VCheckbox
                        v-if="shift.status === 'draft'"
                        :model-value="selectedDraftIds.includes(shift.id)"
                        density="compact"
                        hide-details
                        class="plan-shift__check"
                        @update:model-value="toggleShiftSelection(shift.id)"
                        @click.stop
                      />
                      <button
                        type="button"
                        class="plan-shift__body plan-shift__body--block"
                        :style="{ '--sc': shiftHex(shift) }"
                        :title="shiftPoste(shift) + ' · ' + formatShiftRange(shift)"
                        @click.stop="openActivityDay(shift, row)"
                      >
                        <span class="plan-shift__poste">
                          {{ shiftPoste(shift) }}
                          <span
                            v-if="shiftActivityCount(shift)"
                            class="plan-shift__badge"
                            :title="$t('planning.activities.count', { n: shiftActivityCount(shift) })"
                          >+{{ shiftActivityCount(shift) }}</span>
                        </span>
                        <span class="plan-shift__time">{{ formatShiftRange(shift) }}</span>
                      </button>
                      <!-- Quick actions menu -->
                      <VMenu location="bottom end">
                        <template #activator="{ props: menuProps }">
                          <VBtn
                            v-bind="menuProps"
                            icon
                            variant="text"
                            size="x-small"
                            class="plan-shift__menu"
                            data-test="shift-menu"
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
                            prepend-icon="tabler-timeline"
                            :title="$t('planning.activities.dayDetail')"
                            data-test="open-day-detail"
                            @click="openActivityDay(shift, row)"
                          />
                          <VListItem
                            prepend-icon="tabler-edit"
                            :title="$t('common.edit')"
                            @click="openEditShift(shift)"
                          />
                          <VListItem
                            prepend-icon="tabler-copy"
                            :title="$t('planning.duplicateShift.menu')"
                            @click="duplicateShiftAction(shift)"
                          />
                          <VListItem
                            v-if="shift.status === 'draft'"
                            prepend-icon="tabler-send"
                            :title="$t('planning.publish')"
                            @click="openPublish([shift.id])"
                          />
                          <VListItem
                            v-if="shift.status === 'published' && shiftHasConfirmedAbsence(row.employee_id, dayIdx)"
                            prepend-icon="tabler-user-plus"
                            :title="$t('planning.replacement.action')"
                            data-test="find-replacement"
                            class="text-warning"
                            @click="openReplacement(shift)"
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
                      @click.stop="onEmptyCell(row.employee_id, date)"
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

            <!-- Vue PAR POSTES : une ligne par catégorie, collaborateurs affectés par jour -->
            <tbody v-else>
              <tr v-if="!postRows.length">
                <td
                  :colspan="8"
                  class="text-center pa-8 text-medium-emphasis"
                >
                  {{ $t('planning.posts.empty') }}
                </td>
              </tr>
              <tr
                v-for="pr in postRows"
                :key="`post-${pr.key}`"
              >
                <td>
                  <div class="d-flex align-center gap-2 py-2">
                    <span
                      class="legend-dot"
                      :style="{ background: pr.color }"
                    />
                    <span class="font-weight-medium text-truncate">{{ pr.label }}</span>
                  </div>
                </td>
                <td
                  v-for="(date, dayIdx) in weekDates"
                  :key="`post-${pr.key}-${date}`"
                  class="text-center planning-cell"
                  :class="{ 'bg-light': date === new Date().toISOString().slice(0, 10) }"
                >
                  <div
                    v-if="pr.days[dayIdx]?.length"
                    class="d-flex flex-column gap-1 py-1"
                  >
                    <button
                      v-for="s in pr.days[dayIdx]"
                      :key="s.id"
                      type="button"
                      class="plan-post-chip"
                      :style="{ '--sc': shiftHex(s) }"
                      :title="empName(s) + ' · ' + formatShiftRange(s)"
                      @click="openActivityDay(s, { employee: s.employee })"
                    >
                      <VAvatar
                        size="18"
                        color="primary"
                        variant="tonal"
                      >
                        <span style="font-size: 9px;">{{ empInitials(s) }}</span>
                      </VAvatar>
                      <span class="plan-post-chip__name">{{ empName(s) }}</span>
                    </button>
                  </div>
                  <span
                    v-else
                    class="text-disabled text-caption"
                  >—</span>
                </td>
              </tr>
            </tbody>
            </VTable>
          </div>
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

    </VWindow>

    <!-- ═══════════════════════════════════════════════════════ -->
    <!-- DRAWERS & DIALOGS                                       -->
    <!-- ═══════════════════════════════════════════════════════ -->

    <!-- Shift Create/Edit Drawer -->
    <VNavigationDrawer class="wf-scroll-drawer"
      v-model="isShiftDrawerOpen"
      temporary
      location="end"
      width="400"
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
                data-test="shift-employee"
                :items="employeeItems"
                :label="$t('planning.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
                :disabled="isShiftEditMode"
              />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker
                v-model="shiftFormData.date"
                data-test="shift-date"
                :label="$t('planning.fields.date')"
                :config="{ dateFormat: 'Y-m-d' }"
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
                data-test="shift-start"
                :label="$t('planning.fields.startAt')"
                type="time"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="shiftFormData.end_at"
                data-test="shift-end"
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
                  data-test="shift-submit"
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
    <VNavigationDrawer class="wf-scroll-drawer"
      v-model="isBulkDrawerOpen"
      temporary
      location="end"
      width="400"
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
              <AppDateTimePicker
                v-model="bulkFormData.date_from"
                :label="$t('planning.fields.dateFrom')"
                :config="{ dateFormat: 'Y-m-d' }"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="6">
              <AppDateTimePicker
                v-model="bulkFormData.date_to"
                :label="$t('planning.fields.dateTo')"
                :config="{ dateFormat: 'Y-m-d' }"
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
    <VNavigationDrawer class="wf-scroll-drawer"
      v-model="isTemplateDrawerOpen"
      temporary
      location="end"
      width="400"
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
                v-model="templateFormData.code"
                :label="$t('planning.templates.fields.code')"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="templateFormData.activity_type"
                :label="$t('planning.templates.fields.activityType')"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="templateFormData.coverage_category_id"
                :items="coverageCategoryItems"
                :label="$t('planning.coverage.fields.category')"
                :hint="$t('planning.templates.fields.categoryHint')"
                persistent-hint
                clearable
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
              <AppSelect
                v-model="templateFormData.department_id"
                :items="departmentItems"
                :label="$t('planning.templates.fields.department')"
                :hint="$t('planning.templates.fields.departmentHint')"
                persistent-hint
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
                  data-test="template-submit"
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

    <!-- Remplacement guidé (Lot 3 §2, ADR-717) : fenêtre non assurée + candidats scopés -->
    <VNavigationDrawer
      v-model="replacementOpen"
      class="wf-scroll-drawer"
      temporary
      location="end"
      :width="420"
    >
      <div class="pa-5">
        <div class="d-flex align-center justify-space-between mb-4">
          <h5 class="text-h5">
            {{ $t('planning.replacement.title') }}
          </h5>
          <VBtn icon variant="text" size="small" @click="replacementOpen = false">
            <VIcon icon="tabler-x" />
          </VBtn>
        </div>

        <VAlert type="warning" variant="tonal" density="compact" class="mb-4">
          {{ $t('planning.replacement.gapHint') }}
          <span v-if="replacementWindow.start" class="font-weight-medium d-block mt-1">
            {{ timeOfDay(replacementWindow.start) }}–{{ timeOfDay(replacementWindow.end) }}
          </span>
        </VAlert>

        <div v-if="replacementFetching" class="text-center py-6">
          <VProgressCircular indeterminate color="primary" />
        </div>

        <template v-else>
          <p class="text-body-2 text-medium-emphasis mb-2">
            {{ $t('planning.replacement.pickHint') }}
          </p>
          <VList v-if="replacementData.candidates.length" density="compact" class="mb-4" max-height="340" style="overflow-y:auto;">
            <VListItem
              v-for="c in replacementData.candidates"
              :key="c.employee_id"
              :active="replacementTarget === c.employee_id"
              :disabled="c.availability === 'unavailable' || c.availability === 'busy'"
              data-test="replacement-candidate"
              @click="replacementTarget = c.employee_id"
            >
              <template #prepend>
                <VIcon
                  :icon="replacementTarget === c.employee_id ? 'tabler-circle-check-filled' : 'tabler-circle'"
                  :color="replacementTarget === c.employee_id ? 'primary' : 'disabled'"
                  class="me-2"
                />
              </template>
              <VListItemTitle>{{ c.employee_name }}</VListItemTitle>
              <VListItemSubtitle v-if="c.job_role">
                {{ c.job_role }}
              </VListItemSubtitle>
              <template #append>
                <VChip :color="replacementAvailColor(c.availability)" size="x-small" label>
                  {{ $t('planning.availability.' + c.availability) }}
                </VChip>
              </template>
            </VListItem>
          </VList>
          <VAlert v-else type="info" variant="tonal" density="compact" class="mb-4">
            {{ $t('planning.replacement.noCandidate') }}
          </VAlert>

          <div class="d-flex gap-2">
            <VBtn variant="outlined" @click="replacementOpen = false">
              {{ $t('common.cancel') }}
            </VBtn>
            <VSpacer />
            <VBtn
              color="primary"
              :loading="replacementLoading"
              :disabled="!replacementTarget"
              data-test="confirm-replacement"
              @click="confirmReplacement"
            >
              {{ $t('planning.replacement.confirm') }}
            </VBtn>
          </div>
        </template>
      </div>
    </VNavigationDrawer>

    <!-- Confirmation de publication (§3) : aperçu + publication atomique -->
    <VDialog
      v-model="publishDialog"
      max-width="420"
    >
      <VCard>
        <VCardItem>
          <VCardTitle>{{ $t('planning.publishConfirmTitle') }}</VCardTitle>
        </VCardItem>
        <VCardText>
          <p class="mb-3">
            {{ $t('planning.publishConfirmText', { shifts: publishPreview.shifts, employees: publishPreview.employees }) }}
          </p>
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="text-caption"
          >
            {{ $t('planning.publishAtomicHint') }}
          </VAlert>
        </VCardText>
        <VCardText class="d-flex justify-end gap-2 pt-0">
          <VBtn
            variant="outlined"
            :disabled="publishLoading"
            @click="publishDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="success"
            :loading="publishLoading"
            data-test="publish-confirm"
            @click="confirmPublish"
          >
            {{ $t('planning.publish') }}
          </VBtn>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Édition inline d'un besoin de couverture (Lot A) -->
    <VDialog
      v-model="covEdit.open"
      max-width="340"
    >
      <VCard>
        <VCardText>
          <div class="text-body-2 font-weight-medium mb-3">
            {{ covEdit.line?.label }} <span v-if="covEdit.line?.window" class="text-disabled">· {{ covEdit.line.window }}</span>
          </div>
          <AppTextField
            v-model.number="covEdit.headcount"
            :label="$t('planning.coverage.fields.headcount')"
            type="number"
            min="1"
            autofocus
            @keyup.enter="saveCovEdit"
          />
          <div class="d-flex justify-end gap-2 mt-3">
            <VBtn variant="outlined" size="small" @click="covEdit.open = false">
              {{ $t('common.cancel') }}
            </VBtn>
            <VBtn color="primary" size="small" data-test="cov-save" @click="saveCovEdit">
              {{ $t('common.save') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- Day detail — multi-activités (Smart Planning SP1b) -->
    <ActivityDay
      v-model:is-dialog-visible="activityDayOpen"
      :shift="activityDayShift"
      :employee-name="activityDayEmployee"
      @changed="fetchWeekData"
    />

    <!-- Coverage Management Drawer (besoins de personnel) — Smart Planning SP1a -->
    <VNavigationDrawer class="wf-scroll-drawer"
      v-model="isCoverageDrawerOpen"
      temporary
      location="end"
      :width="smAndDown ? 340 : 440"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('planning.coverage.manage') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isCoverageDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ $t('planning.coverage.hint') }}
        </p>

        <!-- Catalogue de catégories de couverture (Lot A) -->
        <div class="text-body-2 font-weight-medium mb-2">
          {{ $t('planning.coverage.categories') }}
        </div>
        <div class="d-flex flex-wrap gap-2 mb-2">
          <VChip
            v-for="c in store.coverageCategories"
            :key="c.id"
            size="small"
            closable
            variant="tonal"
            @click:close="removeCategory(c)"
          >
            <span
              class="legend-dot me-2"
              :style="{ background: c.color || '#8691a8' }"
            />
            {{ c.name }} <span class="text-disabled ms-1">({{ c.code }})</span>
          </VChip>
          <span
            v-if="!store.coverageCategories.length"
            class="text-caption text-disabled"
          >{{ $t('planning.coverage.noCategory') }}</span>
        </div>
        <div class="d-flex gap-2 mb-4">
          <AppTextField
            v-model="newCategory.name"
            :placeholder="$t('planning.coverage.fields.catName')"
            density="compact"
            hide-details
          />
          <AppTextField
            v-model="newCategory.code"
            :placeholder="$t('planning.coverage.fields.catCode')"
            density="compact"
            hide-details
            style="max-inline-size: 110px;"
          />
          <input
            v-model="newCategory.color"
            type="color"
            class="cov-color-input"
            :title="$t('planning.templates.fields.color')"
          >
          <VBtn
            color="primary"
            variant="tonal"
            size="small"
            data-test="cat-add"
            @click="addCategory"
          >
            {{ $t('common.add') }}
          </VBtn>
        </div>
        <VDivider class="mb-4" />

        <!-- Existing requirements -->
        <VList
          v-if="store.coverage.length"
          class="mb-4"
          density="compact"
        >
          <VListItem
            v-for="c in store.coverage"
            :key="c.id"
            :title="coverageTitle(c)"
            :subtitle="coverageWhen(c)"
            data-test="cov-req-item"
          >
            <template #append>
              <VChip
                size="x-small"
                variant="tonal"
                class="me-2"
              >
                {{ $t(`planning.coverage.priorities.${c.priority}`) }}
              </VChip>
              <VBtn
                icon
                variant="text"
                size="x-small"
                @click="editCoverage(c)"
              >
                <VIcon
                  icon="tabler-edit"
                  size="16"
                />
              </VBtn>
              <VBtn
                icon
                variant="text"
                size="x-small"
                color="error"
                @click="removeCoverage(c)"
              >
                <VIcon
                  icon="tabler-trash"
                  size="16"
                />
              </VBtn>
            </template>
          </VListItem>
        </VList>

        <VDivider class="mb-4" />

        <!-- Create / edit form -->
        <VForm @submit.prevent="submitCoverage">
          <VRow>
            <VCol cols="12">
              <VBtnToggle
                v-model="coverageMode"
                mandatory
                density="compact"
                variant="outlined"
                divided
                class="mb-1"
              >
                <VBtn value="recurrent" size="small" data-test="cov-mode-recurrent">
                  {{ $t('planning.coverage.modeRecurrent') }}
                </VBtn>
                <VBtn value="ponctuel" size="small" data-test="cov-mode-ponctuel">
                  {{ $t('planning.coverage.modePonctuel') }}
                </VBtn>
              </VBtnToggle>
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="coverageForm.label"
                :label="$t('planning.coverage.fields.label')"
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-if="coverageMode === 'recurrent'"
                v-model="coverageForm.day_of_week"
                :items="weekdayItems"
                :label="$t('planning.coverage.fields.weekday')"
                data-test="cov-weekday"
              />
              <AppTextField
                v-else
                v-model="coverageForm.date"
                :label="$t('planning.coverage.fields.date')"
                type="date"
                data-test="cov-date"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model.number="coverageForm.headcount"
                :label="$t('planning.coverage.fields.headcount')"
                type="number"
                min="1"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="coverageForm.start_time"
                :label="$t('planning.coverage.fields.startTime')"
                type="time"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-model="coverageForm.end_time"
                :label="$t('planning.coverage.fields.endTime')"
                type="time"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="coverageForm.coverage_category_id"
                :items="coverageCategoryItems"
                :label="$t('planning.coverage.fields.category')"
                :hint="$t('planning.coverage.fields.categoryHint')"
                persistent-hint
                clearable
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="coverageForm.department_id"
                :items="departmentItems"
                :label="$t('planning.coverage.fields.department')"
                clearable
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="coverageForm.priority"
                :items="priorityItems"
                :label="$t('planning.coverage.fields.priority')"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  v-if="editingCoverage"
                  variant="outlined"
                  @click="resetCoverageForm"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  data-test="coverage-submit"
                  :loading="coverageFormLoading"
                >
                  {{ editingCoverage ? $t('common.save') : $t('common.add') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Location Create/Edit Drawer -->
    <VNavigationDrawer class="wf-scroll-drawer"
      v-model="isLocationDrawerOpen"
      temporary
      location="end"
      width="400"
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
.legend-dot {
  display: inline-block;
  inline-size: 10px;
  block-size: 10px;
  border-radius: 50%;
}

// ── Grille Planning (refonte UX) : en-tête + colonne employé figés, densité maîtrisée ──
.planning-grid-wrap {
  overflow: auto;
  max-block-size: calc(100vh - 340px);
  border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.planning-grid {
  border-collapse: separate;
  border-spacing: 0;

  // Seule la ligne d'en-tête des jours est figée en haut (évite le chevauchement des lignes de couverture).
  thead tr.day-header th {
    position: sticky;
    inset-block-start: 0;
    z-index: 3;
    background: rgb(var(--v-theme-surface));
    box-shadow: inset 0 -1px 0 rgba(var(--v-border-color), var(--v-border-opacity));
  }
  thead tr.day-header th:first-child { z-index: 4; inset-inline-start: 0; }

  // Colonne collaborateur figée (cellules du corps).
  tbody td:first-child,
  .plan-emp-col {
    position: sticky;
    inset-inline-start: 0;
    z-index: 2;
    background: rgb(var(--v-theme-surface));
    box-shadow: inset -1px 0 0 rgba(var(--v-border-color), var(--v-border-opacity));
  }
  // Colonne libellé de couverture figée horizontalement (alignée avec la colonne employé).
  .coverage-row th:first-child {
    position: sticky;
    inset-inline-start: 0;
    z-index: 1;
    background: rgb(var(--v-theme-surface));
  }

  tbody tr:hover td { background-color: rgba(var(--v-theme-on-surface), 0.025); }

  .planning-cell {
    cursor: pointer;
    vertical-align: top;
    padding: 3px 5px !important;
    min-inline-size: 118px;
    block-size: 46px;
    border-inline-end: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.6));

    &:hover { background-color: rgba(var(--v-theme-on-surface), 0.04); }
  }

  .coverage-row th {
    padding-block: 3px;
    border-block-end: 1px solid rgba(var(--v-border-color), calc(var(--v-border-opacity) * 0.5));
    white-space: nowrap;
  }
  .coverage-row.coverage-toggle th {
    background: rgba(var(--v-theme-on-surface), 0.02);
  }

  tbody tr:first-child td { scroll-margin-block-start: 60px; }

  .planning-add-btn { opacity: 0; transition: opacity 0.15s ease; }
  .planning-cell:hover .planning-add-btn { opacity: 1; }

  .bg-light { background-color: rgba(var(--v-theme-primary), 0.05); }
}

// ── Pill de shift : couleur du modèle en liseré + fond teinté, lisible, états clairs ──
.plan-shift {
  position: relative;
  display: flex;
  align-items: center;
  gap: 2px;
  inline-size: 100%; // §2 : le bloc occupe toute la largeur utile de la cellule
  margin-block-end: 3px;

  &__check {
    flex: 0 0 auto;
    opacity: 0;
    margin: 0 !important;
    transform: scale(0.8);
    transition: opacity 0.12s ease;
    :deep(.v-selection-control) { min-block-size: 0; }
  }

  &:hover &__check,
  .v-selection-control--dirty &__check { opacity: 1; }

  &__body {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    min-inline-size: 0;
    padding: 2px 7px;
    border-radius: 6px;
    border-inline-start: 3px solid var(--sc);
    background: color-mix(in srgb, var(--sc) 14%, transparent);
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.74rem;
    font-weight: 600;
    line-height: 1.3;
    cursor: pointer;
    text-align: start;
    transition: background 0.12s ease;

    &:hover { background: color-mix(in srgb, var(--sc) 24%, transparent); }
  }

  &__time { white-space: nowrap; font-variant-numeric: tabular-nums; }

  // Bloc métier (Skello-like) : nom de poste d'abord, horaires ensuite.
  &__body--block {
    flex-direction: column;
    align-items: stretch;
    gap: 1px;
    padding: 3px 7px;
  }
  &__poste {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 4px;
    font-size: 0.74rem;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-transform: uppercase;
    letter-spacing: 0.01em;
  }
  &__body--block &__time { font-size: 0.68rem; font-weight: 500; opacity: 0.85; }

  &__badge {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-inline-size: 16px;
    block-size: 16px;
    padding-inline: 4px;
    border-radius: 8px;
    background: var(--sc);
    color: #fff;
    font-size: 0.62rem;
    font-weight: 700;
  }

  &__menu {
    flex: 0 0 auto;
    opacity: 0;
    transition: opacity 0.12s ease;
  }

  &:hover &__menu { opacity: 0.85; }

  &--published &__body { font-weight: 700; }
  &--cancelled &__body { text-decoration: line-through; opacity: 0.55; }
}

// Chip « par postes » : collaborateur affecté à une catégorie un jour donné.
.plan-post-chip {
  display: flex;
  align-items: center;
  gap: 5px;
  inline-size: 100%;
  padding: 2px 6px;
  border-radius: 6px;
  border-inline-start: 3px solid var(--sc);
  background: color-mix(in srgb, var(--sc) 12%, transparent);
  font-size: 0.72rem;
  font-weight: 600;
  cursor: pointer;
  text-align: start;

  &:hover { background: color-mix(in srgb, var(--sc) 22%, transparent); }
  &__name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
}

.cov-color-input {
  inline-size: 38px;
  block-size: 38px;
  padding: 2px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
}
// Bouton « + » des cellules de couverture : TOUJOURS visible (identifiable sans survol, §3).
.planning-grid .cov-add-btn { opacity: 0.5; transition: opacity 0.12s ease; }
.planning-grid .coverage-row th:hover .cov-add-btn,
.planning-grid .cov-add-btn:hover,
.planning-grid .cov-add-btn:focus-visible { opacity: 1; }
</style>
