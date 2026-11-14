<script setup>
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'
import { useDisplay } from 'vuetify'
import { daysToHundredths, formatDays, formatMinutes } from '@/utils/workUnits'
import TimeSpaceHeader from '@/components/workforce/TimeSpaceHeader.vue'

definePage({ meta: { module: 'workforce' } })

const { t, locale } = useI18n()
const { width: viewportWidth } = useDisplay()
const router = useRouter()
const clockStore = useWorkforceClockStore()
const { toast } = useAppToast()

// ── State ───────────────────────────────────────────────────
const loading = ref(true)
const documents = ref([])
const documentsLoading = ref(false)
const clockActionLoading = ref(false)
const shifts = ref([])
const shiftsLoading = ref(false)

// ── Data fetching ───────────────────────────────────────────
onMounted(async () => {
  try {
    await clockStore.fetchProfile()
    await Promise.all([fetchDocuments(), fetchShifts()])
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

// ADR-782 (UX6.1) — indicateurs du jour (mêmes données qu'avant, présentés en bande).
const todayStats = computed(() => [
  { key: 'status', label: t('workforceMe.todayStatus'), value: t(clockStatusConfig.value.labelKey), icon: clockStatusConfig.value.icon, tone: clockStatusConfig.value.color === 'secondary' ? 'muted' : clockStatusConfig.value.color },
  { key: 'worked', label: t('workforceMe.hoursWorked'), value: formatMinutes(clockStore.workedMinutesToday), icon: 'tabler-clock' },
  { key: 'break', label: t('workforceMe.breakTime'), value: formatMinutes(clockStore.breakMinutesToday), icon: 'tabler-coffee' },
  { key: 'arrival', label: t('workforceMe.clockIn'), value: todayClock.value ? formatTime(todayClock.value.clock_in) : '--:--', icon: 'tabler-login' },
])

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

        <VBtn
          variant="tonal"
          color="primary"
          prepend-icon="tabler-refresh"
          :loading="loading"
          :aria-label="t('workforceMe.refresh')"
          @click="clockStore.fetchProfile(); fetchDocuments()"
        >
          {{ t('workforceMe.refresh') }}
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

      <!-- My planning (published upcoming shifts, read-only) — W-HR-UX-4 §6 -->
      <VRow>
        <VCol cols="12">
          <VCard data-test="me-planning">
            <VCardItem>
              <template #prepend>
                <VAvatar color="primary" variant="tonal" rounded size="40">
                  <VIcon icon="tabler-calendar-event" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.myPlanning') }}</VCardTitle>
            </VCardItem>
            <VCardText v-if="shiftsLoading" class="text-center py-4">
              <VProgressCircular indeterminate color="primary" size="28" />
            </VCardText>
            <VCardText v-else-if="!shifts.length">
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('workforceMe.noShifts') }}
              </p>
            </VCardText>
            <VList v-else density="compact">
              <VListItem
                v-for="s in shifts"
                :key="s.id"
                :title="shiftDay(s)"
                :subtitle="shiftRange(s)"
              >
                <template #prepend>
                  <VIcon icon="tabler-clock-hour-4" size="20" color="primary" class="me-2" />
                </template>
                <template #append>
                  <VChip size="small" color="success" variant="tonal">
                    {{ t('workforceMe.shiftPublished') }}
                  </VChip>
                </template>
              </VListItem>
            </VList>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <!-- Card 2: My Contract -->
        <VCol
          cols="12"
          md="6"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="info"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-file-text" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.myContract') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="!currentContract">
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('workforceMe.noContract') }}
              </p>
            </VCardText>

            <VCardText v-else>
              <VList density="compact">
                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-check"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.contractType') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ contractTypeLabel(currentContract.contract_type) }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-settings"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.workModel') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ workModelLabel(currentContract.work_model_key) }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-clock"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.weeklyHours') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ currentContract.weekly_hours ? `${currentContract.weekly_hours}h` : '-' }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-calendar"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.startDate') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ formatDate(currentContract.start_date) }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem v-if="employee.department">
                  <template #prepend>
                    <VIcon
                      icon="tabler-building"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.department') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ employee.department.name }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem v-if="employee.job_role">
                  <template #prepend>
                    <VIcon
                      icon="tabler-briefcase"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.jobRole') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ employee.job_role.title }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Card 3: Leave Balances -->
        <VCol
          cols="12"
          md="6"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="success"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-beach" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.leaveBalances') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="!leaveBalances.length">
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('workforceMe.noLeaveBalances') }}
              </p>
            </VCardText>

            <VCardText v-else>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('workforceMe.leaveType') }}</th>
                    <th class="text-end">
                      {{ t('workforceMe.leaveAvailable') }}
                    </th>
                    <th class="text-end">
                      {{ t('workforceMe.leaveConsumed') }}
                    </th>
                    <th class="text-end">
                      {{ t('workforceMe.leaveAccrued') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="balance in leaveBalances"
                    :key="balance.leave_type_id"
                  >
                    <td>
                      <span class="font-weight-medium">{{ balance.leave_type_name }}</span>
                    </td>
                    <td class="text-end">
                      <VChip
                        :color="balance.available > 0 ? 'success' : 'error'"
                        size="small"
                        label
                      >
                        {{ t('workforceMe.daysUnit', { count: toDays(balance.available) }) }}
                      </VChip>
                    </td>
                    <td class="text-end text-medium-emphasis">
                      {{ t('workforceMe.daysUnit', { count: toDays(balance.consumed) }) }}
                    </td>
                    <td class="text-end text-medium-emphasis">
                      {{ t('workforceMe.daysUnit', { count: toDays(balance.accrued) }) }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>

            <VCardActions>
              <VSpacer />
              <VBtn
                variant="tonal"
                color="success"
                prepend-icon="tabler-plus"
                data-test="me-request-leave"
                @click="openLeaveDrawer"
              >
                {{ t('workforceMe.requestLeave') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>

      <!-- Card: Mes bulletins de paie -->
      <VRow class="mt-2">
        <VCol cols="12">
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-receipt" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.myPayslips') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="!payslipDocs.length">
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('workforceMe.noPayslips') }}
              </p>
            </VCardText>

            <VCardText v-else class="px-0">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('workforceMe.payslipPeriod') }}</th>
                    <th>{{ t('workforceMe.payslipType') }}</th>
                    <th>{{ t('workforceMe.payslipGeneratedAt') }}</th>
                    <th>{{ t('workforceMe.payslipSignatureStatus') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="doc in payslipDocs"
                    :key="doc.id"
                  >
                    <td class="font-weight-medium">
                      {{ doc.template?.name || doc.subject_type }}
                    </td>
                    <td>
                      <VChip
                        :color="doc.template?.code?.includes('official') ? 'success' : 'warning'"
                        size="small"
                        label
                      >
                        {{ doc.template?.code?.includes('official') ? t('workforceMe.payslipOfficial') : t('workforceMe.payslipDraft') }}
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ formatDate(doc.generated_at) }}
                    </td>
                    <td>
                      <VChip
                        :color="signatureChipProps(doc.signature_status).color"
                        size="x-small"
                        label
                      >
                        {{ signatureChipProps(doc.signature_status).text }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>

            <VCardActions>
              <VSpacer />
              <VBtn
                variant="tonal"
                color="primary"
                prepend-icon="tabler-receipt"
                @click="router.push({ name: 'company-workforce-payroll' })"
              >
                {{ t('workforceMe.viewPayroll') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>

      <!-- Card 4: Recent Documents -->
      <VRow class="mt-2">
        <VCol cols="12">
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="secondary"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-file-certificate" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.recentDocuments') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="documentsLoading">
              <div class="d-flex justify-center py-4">
                <VProgressCircular
                  indeterminate
                  color="primary"
                  size="32"
                />
              </div>
            </VCardText>

            <VCardText v-else-if="!documents.length">
              <p class="text-body-2 text-medium-emphasis mb-0">
                {{ t('workforceMe.noDocuments') }}
              </p>
            </VCardText>

            <VCardText v-else class="px-0">
              <VList density="compact">
                <VListItem
                  v-for="doc in documents"
                  :key="doc.id"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-text"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-1 font-weight-medium">
                    {{ doc.template?.name || doc.subject_type }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-2 text-medium-emphasis">
                    {{ formatDate(doc.generated_at) }}
                  </VListItemSubtitle>
                  <template #append>
                    <!-- ADR-782 : « Version signée » dit déjà l'état ; une seule pastille sinon -->
                    <VChip
                      v-if="doc.signature_status !== 'signed'"
                      :color="signatureChipProps(doc.signature_status).color"
                      size="x-small"
                      label
                      class="me-2"
                    >
                      {{ signatureChipProps(doc.signature_status).text }}
                    </VChip>
                    <!-- ADR-771 : la personne télécharge SON document (original, et version signée une fois finalisée) -->
                    <VBtn
                      v-if="doc.signature_status === 'signed'"
                      size="small"
                      variant="tonal"
                      color="success"
                      class="me-1"
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
                  </template>
                </VListItem>
              </VList>
            </VCardText>

            <VCardActions>
              <VSpacer />
              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-folder"
                @click="router.push({ name: 'company-workforce-documents' })"
              >
                {{ t('workforceMe.viewAllDocuments') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- ANO-007: self-service leave request drawer — ADR-782 (UX6.1) : tiroir commun -->
    <AppDrawer
      v-model="isLeaveDrawerOpen"
      :title="t('workforceMe.requestLeave')"
    >
        <VForm @submit.prevent="submitSelfLeave">
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
              data-test="me-leave-submit"
            >
              {{ t('timeSpace.leave.send') }}
            </VBtn>
          </div>
        </VForm>
    </AppDrawer>
  </div>
</template>
