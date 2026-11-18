<script setup>
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'
import { useDisplay } from 'vuetify'
import { daysToHundredths, formatDays, formatMinutes } from '@/utils/workUnits'
import TimeSpaceHeader from '@/components/workforce/TimeSpaceHeader.vue'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'
import { useModuleStore } from '@/core/stores/module'

definePage({ meta: { module: 'workforce' } })

const { t, locale } = useI18n()
const { width: viewportWidth } = useDisplay()
const router = useRouter()
// ADR-786 : la carte « Mes bulletins » liste déjà MES bulletins. Le raccourci vers la paie COLLECTIVE
// n'est proposé qu'à qui peut l'ouvrir (même règle que la page : module + workforce.payroll_prepare).
const { can, moduleActive } = useCompanyPermissionContext()
const canOpenPayroll = computed(() => moduleActive('workforce_payroll') && can('workforce.payroll_prepare'))
const clockStore = useWorkforceClockStore()
const { toast } = useAppToast()

// ── State ───────────────────────────────────────────────────
const loading = ref(true)
const documents = ref([])
const documentsLoading = ref(false)
const clockActionLoading = ref(false)
const shifts = ref([])
const shiftsLoading = ref(false)
// ADR-787 : MES heures (semaine, période de paie ou mois, à valider, validées) — lues côté serveur.
const hours = ref(null)

// ── Data fetching ───────────────────────────────────────────
onMounted(async () => {
  try {
    await clockStore.fetchProfile()
    // ADR-786 : sans fiche salarié, il n'y a ni documents ni créneaux personnels — pas d'appel (404 évités).
    if (employee.value) await Promise.all([fetchDocuments(), fetchShifts(), fetchHours()])
  }
  catch {
    toast(t('workforceMe.loadError'), 'error')
  }
  finally {
    loading.value = false
  }
})

// W-HR-UX-4 §6 — le salarié consulte son planning PUBLIÉ (créneaux à venir, lecture seule).
async function fetchShifts() {
  shiftsLoading.value = true
  try {
    const data = await $api('/workforce/me/shifts')

    shifts.value = data.shifts || []
  }
  catch {
    // Silently fail — planning is secondary to the clock actions
  }
  finally {
    shiftsLoading.value = false
  }
}

async function fetchHours() {
  try { hours.value = await $api('/workforce/me/hours') }
  catch { hours.value = null } // pas de valeur trompeuse : la bande ne s'affiche pas
}

async function fetchDocuments() {
  documentsLoading.value = true
  try {
    const data = await $api('/workforce/me/documents')

    documents.value = (data.documents || []).slice(0, 5)
  }
  catch {
    // Silently fail — documents are secondary
  }
  finally {
    documentsLoading.value = false
  }
}

// Planning formatting (locale-aware, read-only).
function shiftDay(s) {
  return new Date(s.date).toLocaleDateString(locale.value, { weekday: 'short', day: '2-digit', month: 'short' })
}
function shiftRange(s) {
  const opts = { hour: '2-digit', minute: '2-digit' }

  return `${new Date(s.start_at).toLocaleTimeString(locale.value, opts)} – ${new Date(s.end_at).toLocaleTimeString(locale.value, opts)}`
}

// ── Reactive computed ───────────────────────────────────────
const employee = computed(() => clockStore.employee)
const todayClock = computed(() => clockStore.todayClock)
const leaveBalances = computed(() => clockStore.leaveBalances)
const moduleStore = useModuleStore()
const hasLeaveModule = computed(() => moduleStore.isActive('workforce_leave')) // ADR-804 : mêmes gardes que l'API
// Soldes stockés en CENTIÈMES de jour (1 j = 100) — conversion en jours pour l'affichage.
const toDays = v => (Number(v) || 0) / 100

const employeeName = computed(() => {
  if (!employee.value) return ''

  return `${employee.value.first_name} ${employee.value.last_name}`
})

const payslipDocs = computed(() => documents.value.filter(d => d.template?.code?.startsWith('payslip_')))

// ── Self-service leave request (ANO-007) ────────────────────
// Employees request their own leave here (open /workforce/me endpoint) instead of
// being routed to the permission-gated admin page (which 403'd for regular employees).
const isLeaveDrawerOpen = ref(false)
const leaveSubmitting = ref(false)
const leaveForm = ref({ leave_type_id: null, date_from: '', date_to: '', days: 1, reason: '' })
const leaveTypeOptions = computed(() =>
  leaveBalances.value.map(b => ({ title: b.leave_type_name, value: b.leave_type_id })))

function openLeaveDrawer() {
  leaveForm.value = { leave_type_id: leaveTypeOptions.value[0]?.value ?? null, date_from: '', date_to: '', days: 1, reason: '' }
  leaveComputed.value = null
  isLeaveDrawerOpen.value = true
}

// ADR-779 (UX4) — la durée est PROPOSÉE par le moteur existant dès que les dates sont choisies
// (modifiable par demi-journée) ; le solde du type choisi est affiché ; le serveur reste juge.
const leaveComputed = ref(null)
const leaveBalanceOf = computed(() => leaveBalances.value.find(b => b.leave_type_id === leaveForm.value.leave_type_id) || null)
const leaveShort = computed(() => leaveBalanceOf.value && leaveForm.value.days
  && leaveBalanceOf.value.available < daysToHundredths(leaveForm.value.days))
const leaveHasWeekend = computed(() => {
  const { date_from: a, date_to: b } = leaveForm.value
  if (!a || !b || a === b) return false
  for (let d = new Date(`${a}T00:00:00`), end = new Date(`${b}T00:00:00`), i = 0; d <= end && i < 400; d.setDate(d.getDate() + 1), i++)
    if (d.getDay() === 0 || d.getDay() === 6) return true

  return false
})
let leavePreviewSeq = 0 // seule la DERNIÈRE période demandée fait foi (réponses tardives ignorées)
watch(() => [leaveForm.value.date_from, leaveForm.value.date_to], async ([from, to]) => {
  if (from && !to) { leaveForm.value.date_to = from; return }
  const seq = ++leavePreviewSeq
  leaveComputed.value = null
  if (!from || !to || to < from) return
  try {
    const res = await clockStore.previewLeave({ dateFrom: from, dateTo: to })
    if (seq !== leavePreviewSeq) return
    leaveComputed.value = res
    if (res?.days_count_hundredths) leaveForm.value.days = res.days_count_hundredths / 100
  }
  catch { if (seq === leavePreviewSeq) leaveComputed.value = null }
})

async function submitSelfLeave() {
  leaveSubmitting.value = true
  try {
    await clockStore.createLeave({
      leaveTypeId: leaveForm.value.leave_type_id,
      dateFrom: leaveForm.value.date_from,
      dateTo: leaveForm.value.date_to,
      daysCountHundredths: daysToHundredths(leaveForm.value.days),
      reason: leaveForm.value.reason,
    })
    toast(t('workforceMe.leaveRequested'), 'success')
    isLeaveDrawerOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('workforceMe.leaveRequestFailed'), 'error')
  }
  finally {
    leaveSubmitting.value = false
  }
}

const currentContract = computed(() => {
  if (!employee.value) return null

  // EmployeeReadModel::detail loads contracts relation ordered by start_date desc
  const contracts = employee.value.contracts || []

  return contracts.find(c => c.is_current) || contracts[0] || null
})

// ── Clock status helpers ────────────────────────────────────
const clockStatusConfig = computed(() => {
  const status = clockStore.clockStatus

  const configs = {
    working: { icon: 'tabler-player-play', color: 'success', labelKey: 'workforceMe.status.working' },
    on_break: { icon: 'tabler-coffee', color: 'warning', labelKey: 'workforceMe.status.onBreak' },
    completed: { icon: 'tabler-check', color: 'info', labelKey: 'workforceMe.status.completed' },
    not_started: { icon: 'tabler-clock', color: 'secondary', labelKey: 'workforceMe.status.notStarted' },
  }

  return configs[status] || configs.not_started
})

// Break countdown helpers (mm:ss) — all durations come from the store (server + resolver).
function fmtClock(s) {
  if (s === null || s === undefined) return '—'
  const m = Math.floor(s / 60)
  const sec = s % 60

  return `${m}:${String(sec).padStart(2, '0')}`
}
const meBreakResumeLabel = computed(() => {
  const d = clockStore.breakResumeAt
  if (!d) return null

  return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
})

// ADR-783 (UX6.2) — faits du contrat (mêmes données, présentation compacte).
const contractFacts = computed(() => (currentContract.value ? [
  { label: t('workforceMe.contractType'), value: contractTypeLabel(currentContract.value.contract_type) },
  { label: t('workforceMe.workModel'), value: workModelLabel(currentContract.value.work_model_key) },
  { label: t('workforceMe.weeklyHours'), value: currentContract.value.weekly_hours ? `${Number(currentContract.value.weekly_hours)} h` : '—' },
  { label: t('workforceMe.startDate'), value: formatDate(currentContract.value.start_date) },
  { label: t('workforceMe.department'), value: employee.value?.department?.name },
  { label: t('workforceMe.jobRole'), value: employee.value?.job_role?.title },
] : []))

// ADR-782 (UX6.1) — indicateurs du jour (mêmes données qu'avant, présentés en bande).
const todayStats = computed(() => [
  { key: 'status', label: t('workforceMe.todayStatus'), value: t(clockStatusConfig.value.labelKey), icon: clockStatusConfig.value.icon, tone: clockStatusConfig.value.color === 'secondary' ? 'muted' : clockStatusConfig.value.color },
  { key: 'worked', label: t('workforceMe.hoursWorked'), value: formatMinutes(clockStore.workedMinutesToday), icon: 'tabler-clock' },
  { key: 'break', label: t('workforceMe.breakTime'), value: formatMinutes(clockStore.breakMinutesToday), icon: 'tabler-coffee' },
  { key: 'arrival', label: t('workforceMe.clockIn'), value: todayClock.value ? formatTime(todayClock.value.clock_in) : '--:--', icon: 'tabler-login' },
])

// ADR-787 — bande « Mes heures ». La période vient de la paie quand un cycle couvre aujourd'hui,
// sinon elle est explicitement « Ce mois ». Jamais présentées comme des heures payées.
const shortDate = d => new Date(`${d}T12:00:00`).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' })
const hoursStats = computed(() => {
  const h = hours.value
  if (!h) return []
  const periodLabel = h.period.kind === 'payroll' ? t('workforceMe.hours.payrollPeriod') : t('workforceMe.hours.thisMonth')

  return [
    { key: 'hours-week', label: t('workforceMe.hours.week'), value: formatMinutes(h.week.worked_minutes), icon: 'tabler-calendar-week' },
    { key: 'hours-period', label: periodLabel, value: formatMinutes(h.period.worked_minutes), icon: 'tabler-calendar-month' },
    { key: 'hours-pending', label: t('workforceMe.hours.pending'), value: formatMinutes(h.period.pending_minutes), icon: 'tabler-hourglass', tone: h.period.pending_minutes ? 'warning' : 'muted' },
    { key: 'hours-validated', label: t('workforceMe.hours.validated'), value: formatMinutes(h.period.validated_minutes), icon: 'tabler-circle-check', tone: h.period.validated_minutes ? 'success' : 'muted' },
  ]
})
const hoursCaption = computed(() => {
  const h = hours.value
  if (!h) return ''

  return t('workforceMe.hours.caption', {
    weekFrom: shortDate(h.week.from), weekTo: shortDate(h.week.to),
    periodFrom: shortDate(h.period.from), periodTo: shortDate(h.period.to),
  })
})

// ── Time formatting ─────────────────────────────────────────
// ADR-779 : formatMinutes partagé (« 7 h 30 ») — importé de @/utils/workUnits.

function formatTime(isoString) {
  if (!isoString) return '--:--'
  const d = new Date(isoString)

  return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}

function formatDate(dateString) {
  if (!dateString) return '-'

  return new Date(dateString).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' })
}

// ── Contract type labels ────────────────────────────────────
async function handleClockAction(actionFn) {
  clockActionLoading.value = true
  try {
    await actionFn()
    toast(t('workforceMe.clockActionSuccess'), 'success')
  }
  catch (error) {
    toast((error.data?.message || error.response?._data?.message) || t('common.error'), 'error')
  }
  finally {
    clockActionLoading.value = false
  }
}

function contractTypeLabel(type) {
  return t(`employees.contractTypes.${type}`, type?.toUpperCase() ?? '-')
}

function workModelLabel(key) {
  return t(`employees.workModels.${key}`, key ?? '-')
}

// ── Signature status ────────────────────────────────────────
// ADR-771 — téléchargement de MES documents générés (propriété, aucune permission de gestion).
async function downloadOwnDocument(doc, signed) {
  try {
    const blob = await $api(`/workforce/me/generated-documents/${doc.id}/download${signed ? '?version=signed' : ''}`, { responseType: 'blob' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${doc.template?.code || 'document'}-${doc.id}${signed ? '-signe' : ''}.pdf`
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  }
  catch { toast(t('common.error'), 'error') }
}

function signatureChipProps(status) {
  const map = {
    none: { color: 'secondary', text: t('workforceMe.signatureNone') },
    pending: { color: 'warning', text: t('workforceMe.signaturePending') },
    signed: { color: 'success', text: t('workforceMe.signatureSigned') },
    declined: { color: 'error', text: t('workforceMe.signatureDeclined') },
  }

  return map[status] || map.none
}
</script>

<template>
  <div>
    <!-- ADR-779 (UX4) — « Mon temps » dans l'espace Planning & temps ; le pointage reste l'action principale. -->
    <TimeSpaceHeader
      active="me"
      :subtitle="employee ? t('workforceMe.welcome', { name: employeeName }) : ''"
    >
      <template #actions>
        <!-- Clock actions -->
        <VBtn
          v-if="employee && clockStore.clockStatus === 'not_started' && !clockStore.openEntry"
          color="success"
          prepend-icon="tabler-player-play"
          :loading="clockActionLoading"
          data-test="me-clock-in"
          @click="handleClockAction(clockStore.clockIn)"
        >
          {{ t('workforceClock.clockIn') }}
        </VBtn>
        <template v-else-if="clockStore.isWorking">
          <VBtn
            color="warning"
            variant="tonal"
            prepend-icon="tabler-coffee"
            :loading="clockActionLoading"
            data-test="me-start-break"
            @click="handleClockAction(clockStore.startBreak)"
          >
            {{ t('workforceClock.startBreak') }}
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            prepend-icon="tabler-player-stop"
            :loading="clockActionLoading"
            data-test="me-clock-out"
            @click="handleClockAction(clockStore.clockOut)"
          >
            {{ t('workforceClock.clockOut') }}
          </VBtn>
        </template>
        <VBtn
          v-else-if="clockStore.isOnBreak"
          color="success"
          prepend-icon="tabler-player-play"
          :loading="clockActionLoading"
          :disabled="clockStore.breakBelowMinimum && !clockStore.canResumeNow"
          data-test="me-end-break"
          @click="handleClockAction(clockStore.endBreak)"
        >
          {{ t('workforceClock.endBreak') }}
        </VBtn>

        <!-- ADR-823 : actualiser = action secondaire (icône), le pointage reste l'action principale -->
        <VBtn
          icon
          variant="text"
          color="secondary"
          :loading="loading"
          :aria-label="t('workforceMe.refresh')"
          :title="t('workforceMe.refresh')"
          data-test="me-refresh"
          @click="clockStore.fetchProfile(); fetchDocuments()"
        >
          <VIcon icon="tabler-refresh" />
        </VBtn>
      </template>
    </TimeSpaceHeader>

    <!-- ADR-779 (UX4) — pointage resté ouvert un autre jour : on explique au lieu d'un « Commencer » refusé. -->
    <VAlert
      v-if="clockStore.openEntry && clockStore.clockStatus === 'not_started'"
      type="warning"
      variant="tonal"
      class="mb-6"
      data-test="me-open-entry"
    >
      {{ t('timeSpace.openEntry', { date: new Date(clockStore.openEntry.date + 'T00:00:00').toLocaleDateString(locale) }) }}
    </VAlert>

    <!-- Loading state -->
    <div
      v-if="loading"
      class="d-flex justify-center align-center py-16"
    >
      <VProgressCircular
        indeterminate
        color="primary"
        size="48"
      />
    </div>

    <!-- No employee record -->
    <VAlert
      v-else-if="!employee"
      type="info"
      variant="tonal"
      class="mb-6"
    >
      {{ t('workforceMe.noEmployee') }}
    </VAlert>

    <!-- Main content -->
    <template v-else>
      <!-- Card 1: Today's Status -->
      <!-- ADR-782 (UX6.1) : une bande d'indicateurs au lieu de quatre cartes -->
      <AppStatStrip
        class="mb-6"
        :items="todayStats"
      />

      <!-- Break countdown panel (driven by the server-resolved break rules) -->
      <VRow v-if="clockStore.isOnBreak">
        <VCol cols="12">
          <VCard
            :color="clockStore.breakTargetReached ? 'warning' : undefined"
            :variant="clockStore.breakTargetReached ? 'tonal' : 'outlined'"
            data-test="me-break-panel"
          >
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <div class="d-flex align-center gap-2">
                  <VIcon icon="tabler-coffee" color="warning" />
                  <span class="text-h6">{{ t('workforceClock.onBreak') }}</span>
                </div>
                <VChip
                  v-if="clockStore.breakTargetReached"
                  color="warning"
                  size="small"
                  variant="elevated"
                >
                  {{ t('workforceClock.breakTargetReached') }}
                </VChip>
              </div>

              <VRow>
                <VCol cols="6" sm="3">
                  <div class="text-body-2 text-medium-emphasis">{{ t('workforceClock.breakElapsed') }}</div>
                  <div class="text-h5 font-weight-bold" data-test="me-break-elapsed">{{ fmtClock(clockStore.currentBreakSeconds) }}</div>
                </VCol>
                <VCol v-if="clockStore.hasBreakTarget" cols="6" sm="3">
                  <div class="text-body-2 text-medium-emphasis">{{ t('workforceClock.breakTarget') }}</div>
                  <div class="text-h5 font-weight-bold">{{ fmtClock(clockStore.breakTargetSeconds) }}</div>
                </VCol>
                <VCol v-if="clockStore.hasBreakTarget" cols="6" sm="3">
                  <div class="text-body-2 text-medium-emphasis">
                    {{ clockStore.breakTargetReached ? t('workforceClock.breakOvertime') : t('workforceClock.breakRemaining') }}
                  </div>
                  <div
                    class="text-h5 font-weight-bold"
                    :class="clockStore.breakTargetReached ? 'text-warning' : 'text-success'"
                    data-test="me-break-remaining"
                  >
                    {{ clockStore.breakTargetReached ? '+' + fmtClock(clockStore.breakOvershootSeconds) : fmtClock(clockStore.breakRemainingSeconds) }}
                  </div>
                </VCol>
                <VCol v-if="meBreakResumeLabel" cols="6" sm="3">
                  <div class="text-body-2 text-medium-emphasis">{{ t('workforceClock.resumeAt') }}</div>
                  <div class="text-h5 font-weight-bold">{{ meBreakResumeLabel }}</div>
                </VCol>
              </VRow>

              <VProgressLinear
                v-if="clockStore.hasBreakTarget"
                :model-value="clockStore.breakProgress"
                :color="clockStore.breakTargetReached ? 'warning' : 'primary'"
                height="8"
                rounded
                class="mt-3"
              />
              <div v-else class="text-body-2 text-medium-emphasis mt-2">
                {{ t('workforceClock.noBreakTarget') }}
              </div>

              <VAlert
                v-if="clockStore.breakBelowMinimum && !clockStore.canResumeNow"
                type="warning"
                variant="tonal"
                density="compact"
                class="mt-3"
              >
                {{ t('workforceClock.breakMinBlocked', { min: Math.round((clockStore.breakMinSeconds || 0) / 60) }) }}
              </VAlert>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- ADR-787 — Mes heures : bande d'indicateurs + explication honnête -->
      <section
        v-if="hoursStats.length"
        class="me-section"
        data-test="me-hours"
      >
        <div class="me-section__head">
          <h6 class="me-section__title">
            {{ t('workforceMe.hours.title') }}
          </h6>
          <span class="me-section__meta">{{ hoursCaption }}</span>
        </div>
        <AppStatStrip :items="hoursStats" />
        <p class="me-note">
          {{ hours?.in_progress ? t('workforceMe.hours.inProgressNote') : t('workforceMe.hours.note') }}
        </p>
      </section>

      <!-- ADR-787 — composition en deux colonnes : activité (planning, documents) | dossier (congés, contrat) -->
      <div class="me-grid">
        <div class="me-col">
          <!-- Mon planning (créneaux publiés, lecture seule) — W-HR-UX-4 §6 -->
          <VCard
            variant="outlined"
            class="me-block"
            data-test="me-planning"
          >
            <div class="me-block__head">
              <h6 class="me-block__title">
                {{ t('workforceMe.myPlanning') }}
              </h6>
            </div>
            <div
              v-if="shiftsLoading"
              class="me-block__body"
            >
              <VProgressLinear
                indeterminate
                color="primary"
              />
            </div>
            <p
              v-else-if="!shifts.length"
              class="me-empty"
            >
              {{ t('workforceMe.noShifts') }}
            </p>
            <div
              v-for="sh in shifts"
              v-else
              :key="sh.id"
              class="me-row"
            >
              <VIcon
                icon="tabler-clock-hour-4"
                size="18"
                class="me-row__icon"
              />
              <div class="me-row__main">
                <div class="me-row__title">
                  {{ shiftDay(sh) }}
                </div>
                <div class="me-row__sub">
                  {{ shiftRange(sh) }}
                </div>
              </div>
              <AppStatusText
                tone="success"
                :text="t('workforceMe.shiftPublished')"
              />
            </div>
          </VCard>

          <!-- Mes documents : bulletins + documents générés, une seule surface -->
          <VCard variant="outlined" class="me-block">
            <div class="me-block__head">
              <h6 class="me-block__title">
                {{ t('workforceMe.hours.documentsTitle') }}
              </h6>
              <div class="d-flex gap-2">
                <VBtn
                  v-if="canOpenPayroll"
                  variant="text"
                  size="small"
                  data-test="me-open-payroll"
                  @click="router.push({ name: 'company-workforce-payroll' })"
                >
                  {{ t('workforceMe.viewPayroll') }}
                </VBtn>
                <VBtn
                  variant="text"
                  size="small"
                  append-icon="tabler-chevron-right"
                  data-test="me-all-documents"
                  :aria-label="t('workforceMe.viewAllDocuments')"
                  @click="router.push({ name: 'company-my-space-tab', params: { tab: 'documents' } })"
                >
                  {{ t('workforceMe.hours.seeAll') }}
                </VBtn>
              </div>
            </div>
            <div
              v-if="documentsLoading"
              class="me-block__body"
            >
              <VProgressLinear
                indeterminate
                color="primary"
              />
            </div>
            <p
              v-else-if="!documents.length"
              class="me-empty"
              data-test="me-documents-empty"
            >
              {{ t('workforceMe.hours.noDocuments') }}
            </p>
            <div
              v-for="doc in documents"
              v-else
              :key="doc.id"
              class="me-row"
            >
              <VIcon
                :icon="doc.template?.code?.startsWith('payslip_') ? 'tabler-receipt' : 'tabler-file-text'"
                size="18"
                class="me-row__icon"
              />
              <div class="me-row__main">
                <div class="me-row__title">
                  {{ doc.template?.name || doc.subject_type }}
                </div>
                <div class="me-row__sub">
                  {{ formatDate(doc.generated_at) }}<template v-if="doc.template?.code?.startsWith('payslip_')">
                    · {{ doc.template?.code?.includes('official') ? t('workforceMe.payslipOfficial') : t('workforceMe.payslipDraft') }}
                  </template>
                </div>
              </div>
              <!-- ADR-782 : « Version signée » dit déjà l'état ; une seule mention sinon -->
              <AppStatusText
                v-if="doc.signature_status && doc.signature_status !== 'signed' && doc.signature_status !== 'none'"
                :tone="signatureChipProps(doc.signature_status).color === 'success' ? 'success' : 'warning'"
                :text="signatureChipProps(doc.signature_status).text"
                class="d-none d-sm-flex"
              />
              <!-- ADR-771 : la personne télécharge SON document (original, et version signée une fois finalisée) -->
              <VBtn
                v-if="doc.signature_status === 'signed'"
                size="small"
                variant="tonal"
                color="success"
                data-test="me-document-signed"
                @click="downloadOwnDocument(doc, true)"
              >
                {{ t('workforceMe.downloadSigned') }}
              </VBtn>
              <IconBtn
                size="small"
                :aria-label="t('workforceMe.downloadMine')"
                :title="t('workforceMe.downloadMine')"
                data-test="me-document-download"
                @click="downloadOwnDocument(doc, false)"
              >
                <VIcon icon="tabler-download" />
              </IconBtn>
            </div>
          </VCard>
        </div>

        <div class="me-col">
          <!-- Mes congés : une ligne par type, l'action dans l'en-tête (ADR-804 : seulement si le module Congés est actif) -->
          <VCard
            v-if="hasLeaveModule"
            variant="outlined"
            class="me-block"
          >
            <div class="me-block__head">
              <h6 class="me-block__title">
                {{ t('workforceMe.leaveBalances') }}
              </h6>
              <VBtn
                variant="text"
                size="small"
                prepend-icon="tabler-plus"
                data-test="me-request-leave"
                @click="openLeaveDrawer"
              >
                {{ t('workforceMe.requestLeave') }}
              </VBtn>
            </div>
            <p
              v-if="!leaveBalances.length"
              class="me-empty"
            >
              {{ t('workforceMe.noLeaveBalances') }}
            </p>
            <div
              v-for="balance in leaveBalances"
              v-else
              :key="balance.leave_type_id"
              class="me-row"
            >
              <div class="me-row__main">
                <div class="me-row__title">
                  {{ balance.leave_type_name }}
                </div>
                <div class="me-row__sub">
                  {{ t('workforceMe.hours.leaveDetail', { consumed: t('workforceMe.daysUnit', { count: toDays(balance.consumed) }), accrued: t('workforceMe.daysUnit', { count: toDays(balance.accrued) }) }) }}
                </div>
              </div>
              <div
                class="me-row__value"
                :class="balance.available > 0 ? 'text-success' : 'text-error'"
              >
                {{ t('workforceMe.daysUnit', { count: toDays(balance.available) }) }}
              </div>
            </div>
          </VCard>

          <!-- Mon contrat : faits en une colonne (libellé / valeur) -->
          <VCard variant="outlined" class="me-block">
            <div class="me-block__head">
              <h6 class="me-block__title">
                {{ t('workforceMe.myContract') }}
              </h6>
            </div>
            <p
              v-if="!currentContract"
              class="me-empty"
            >
              {{ t('workforceMe.noContract') }}
            </p>
            <dl
              v-else
              class="me-facts-list"
            >
              <div
                v-for="f in contractFacts"
                :key="f.label"
                class="me-facts-list__item"
              >
                <dt>{{ f.label }}</dt>
                <dd>{{ f.value || '—' }}</dd>
              </div>
            </dl>
          </VCard>
        </div>
      </div>
    </template>

    <!-- ANO-007: self-service leave request drawer — ADR-782 (UX6.1) : tiroir commun -->
    <AppDrawer
      v-model="isLeaveDrawerOpen"
      :title="t('workforceMe.requestLeave')"
    >
        <VForm @submit.prevent="submitSelfLeave">
          <!-- ADR-812 (ADR-811 D4) : sans solde ouvert (contrat non actif), aucun type n'est proposé — on l'explique. -->
          <VAlert
            v-if="!leaveTypeOptions.length"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            data-test="me-leave-no-balance"
          >
            {{ t('workforceMe.noLeaveBalance') }}
          </VAlert>
          <AppSelect
            v-model="leaveForm.leave_type_id"
            :items="leaveTypeOptions"
            :label="t('workforceMe.leaveType')"
            data-test="me-leave-type"
            class="mb-4"
          />
          <AppTextField
            v-model="leaveForm.date_from"
            type="date"
            :label="t('leaves.dateFrom')"
            data-test="me-leave-from"
            class="mb-4"
          />
          <AppTextField
            v-model="leaveForm.date_to"
            type="date"
            :label="t('leaves.dateTo')"
            data-test="me-leave-to"
            class="mb-4"
          />
          <AppTextField
            v-model.number="leaveForm.days"
            type="number"
            min="0.5"
            step="0.5"
            :label="t('leaves.fields.duration')"
            :suffix="t('timeSpace.leave.daysSuffix')"
            :hint="leaveComputed ? t('timeSpace.leave.computed', { days: formatDays(leaveComputed.days_count_hundredths, t, locale), hours: formatMinutes(Math.round(leaveComputed.hours_count_hundredths * 0.6)) }) : t('timeSpace.leave.pickDates')"
            persistent-hint
            data-test="me-leave-days"
            class="mb-2"
          />
          <div
            v-if="leaveHasWeekend"
            class="text-caption text-warning mb-2"
          >
            {{ t('timeSpace.leave.weekendNote') }}
          </div>
          <div
            v-if="leaveBalanceOf"
            class="text-body-2 mb-4"
            :class="leaveShort ? 'text-error' : 'text-medium-emphasis'"
            data-test="me-leave-balance"
          >
            {{ leaveShort ? t('timeSpace.leave.insufficient', { days: formatDays(leaveBalanceOf.available, t, locale) }) : t('timeSpace.leave.available', { days: formatDays(leaveBalanceOf.available, t, locale) }) }}
          </div>
          <AppTextarea
            v-model="leaveForm.reason"
            data-test="me-leave-reason"
            :label="t('timeSpace.leave.comment')"
            rows="2"
            class="mb-4"
          />
          <div class="app-drawer-actions">
            <VBtn
              variant="tonal"
              color="secondary"
              @click="isLeaveDrawerOpen = false"
            >
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              type="submit"
              :loading="leaveSubmitting"
              :disabled="!leaveTypeOptions.length"
              data-test="me-leave-submit"
            >
              {{ t('timeSpace.leave.send') }}
            </VBtn>
          </div>
        </VForm>
    </AppDrawer>
  </div>
</template>

<style scoped>
/* ADR-787 — Mon activité : sections sobres sur surfaces neutres (ADR-782), deux colonnes équilibrées
   par le contenu (activité | dossier), aucune hauteur forcée. Variables du thème : clair et sombre. */
.me-section { margin-block-end: 24px; }
.me-section__head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-block-end: 10px; }
.me-section__title, .me-block__title { margin: 0; font-size: 0.9375rem; font-weight: 600; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.me-section__meta { font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.me-note { margin: 8px 2px 0; font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

.me-grid { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 24px; align-items: start; }
.me-col { display: grid; gap: 24px; min-inline-size: 0; }
.me-block__head { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-block-size: 52px; padding: 8px 12px 8px 20px; border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.me-block__body { padding: 16px 20px; }
.me-empty { margin: 0; padding: 16px 20px; font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }

.me-row { display: flex; align-items: center; gap: 12px; min-block-size: 56px; padding: 8px 12px 8px 20px; }
.me-row + .me-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.me-row__icon { flex: none; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.me-row__main { flex: 1; min-inline-size: 0; }
.me-row__title { font-size: 0.9375rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.me-row__sub { font-size: 0.8125rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.me-row__value { flex: none; padding-inline-end: 8px; font-size: 1rem; font-weight: 600; }

.me-facts-list { margin: 0; padding: 4px 0; }
.me-facts-list__item { display: flex; justify-content: space-between; gap: 16px; padding: 10px 20px; }
.me-facts-list__item + .me-facts-list__item { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.me-facts-list__item dt { font-size: 0.875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.me-facts-list__item dd { margin: 0; font-size: 0.875rem; font-weight: 500; text-align: end; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }

@media (max-width: 959px) {
  .me-grid { grid-template-columns: minmax(0, 1fr); gap: 16px; }
  .me-col { gap: 16px; }
}
@media (max-width: 599px) {
  .me-block__head, .me-row { padding-inline-start: 16px; }
  .me-facts-list__item, .me-empty { padding-inline: 16px; }
}
</style>
