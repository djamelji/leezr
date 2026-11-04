<script setup>
// E3-B / B3 (ADR-699) — détail & validation d'une feuille de temps. Aligné STRICTEMENT sur les contrats
// serveur (champs réels : anomaly_count, daily_overtime_minutes, leave_type_code, anomalies[].details).
// Prévu / constaté / validé distingués ; totaux lus du read model (jamais reconstitués) ; transitions via
// SmartActionBar selon le moteur réel ; historique scopé (workflow + corrections tracées). Aucun moteur modifié.
import { useTimesheetsStore } from '@/modules/company/workforce/timesheets.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import { useDisplay } from 'vuetify'
import StatusChip from '@/core/components/StatusChip.vue'
import AppAdaptiveDialog from '@/core/components/AppAdaptiveDialog.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.timesheet_view' } })

const store = useTimesheetsStore()
const route = useRoute()
const { t, locale } = useI18n()
const { toast } = useAppToast()
const { can } = useCan()
const { xs } = useDisplay()

const loading = ref(true)
const history = ref([])
const historyLoading = ref(false)

const loadHistory = async () => {
  historyLoading.value = true
  try { history.value = await store.fetchTimesheetHistory(route.params.id) } catch { history.value = [] } finally { historyLoading.value = false }
}

// P1a — contrôle réglementaire (constaté). Projection de consultation, lecture seule, SÉPARÉE des
// anomalies techniques (P0) et des heures sup (« à qualifier »). Le statut vient du SERVEUR (autorité).
const compliance = ref(null)
const complianceLoading = ref(false)
const loadCompliance = async () => {
  complianceLoading.value = true
  try { compliance.value = await store.fetchTimesheetCompliance(route.params.id) } catch { compliance.value = null } finally { complianceLoading.value = false }
}
const reload = async () => { await store.fetchTimesheet(route.params.id); loadHistory(); loadCompliance() }

onMounted(async () => {
  try { await store.fetchTimesheet(route.params.id); loadHistory(); loadCompliance() } finally { loading.value = false }
})
onBeforeUnmount(() => store.clearCurrentPeriod())

const period = computed(() => store.currentPeriod)

// ── Helpers ──
const fmtMin = m => {
  if (m == null) return '—'
  const h = Math.floor(Math.abs(m) / 60); const mn = Math.abs(m) % 60

  return `${m < 0 ? '-' : ''}${h}h${String(mn).padStart(2, '0')}`
}
const fmtDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '—'
const fmtDateTime = d => d ? new Date(d).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '—'
const fmtTime = iso => iso ? new Date(iso).toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' }) : '—'
const dayName = d => d ? new Date(d).toLocaleDateString(locale.value, { weekday: 'short' }) : ''

// ── Lignes (par jour) : réel depuis source_snapshot ; écart = constaté − planifié ──
const lines = computed(() => (period.value?.lines || []).map(l => {
  const entries = l.source_snapshot?.time_entries || []

  return {
    ...l,
    clock_in: entries[0]?.clock_in || null,
    clock_out: entries[entries.length - 1]?.clock_out || null,
    gap: (!l.is_leave_day && !l.is_rest_day && l.planned_minutes > 0) ? (l.worked_minutes - l.planned_minutes) : null,
  }
}))

// ── Anomalies : groupées {type: [{date, severity, details}]} (TimesheetAnomalyReadModel) ──
const anomalyGroups = computed(() => period.value?.anomalies || {})
const hasAnomalies = computed(() => Object.keys(anomalyGroups.value).length > 0)
const flatAnomalies = computed(() => Object.entries(anomalyGroups.value).flatMap(([type, items]) => (items || []).map(a => ({ ...a, type }))))
// Approbation : seules les anomalies de sévérité « error » (p.ex. leave_conflict) exigent une note (moteur réel).
const hasErrorAnomaly = computed(() => flatAnomalies.value.some(a => a.severity === 'error'))

const sevColor = s => ({ error: 'error', warning: 'warning', info: 'info' }[s] || 'secondary')
const anomalyIcon = type => ({
  missing_clock_out: 'tabler-clock-off', missing_time_entry: 'tabler-clock-x', overtime_daily: 'tabler-clock-plus',
  overtime_weekly: 'tabler-clock-plus', insufficient_break: 'tabler-coffee-off', unplanned_work: 'tabler-calendar-plus',
  leave_conflict: 'tabler-calendar-x', late_arrival: 'tabler-login', early_departure: 'tabler-logout',
}[type] || 'tabler-alert-circle')
const anomalyLabel = type => t(`timesheets.anomalies.types.${type}`, type)
// Détail d'anomalie HUMANISÉ (jamais de dump technique brut §6) : chaîne telle quelle, ou dépassement lisible.
const anomalyDetail = a => {
  const d = a?.details
  if (!d) return ''
  if (typeof d === 'string') return d
  if (typeof d === 'object' && d.excess != null) return `+${fmtMin(d.excess)}`

  return ''
}

// P0 (ADR-701) — les heures sup NE SONT PLUS des anomalies : on exclut leurs types de l'affichage
// des anomalies par ligne (y compris feuilles historiques générées avant P0).
const OVERTIME_TYPES = ['overtime_daily', 'overtime_weekly']
const lineTechAnomalies = l => (l.anomalies || []).filter(a => !OVERTIME_TYPES.includes(a.type))

const lineAnomSeverity = l => {
  const a = lineTechAnomalies(l)
  if (a.some(x => x.severity === 'error')) return 'error'
  if (a.some(x => x.severity === 'warning')) return 'warning'

  return a.length ? 'info' : null
}

// Heures sup. HEBDOMADAIRES constatées (metadata, dernier jour de semaine) — distinctes des journalières.
const weeklyOvertimeMinutes = computed(() => (period.value?.lines || []).reduce((s, l) => s + (l.metadata?.weekly_overtime_minutes || 0), 0))
const hasOvertime = computed(() => (period.value?.total_overtime_minutes || 0) > 0 || weeklyOvertimeMinutes.value > 0)

// ── P1a — contrôles réglementaires (statut SERVEUR) ──
const COMPLIANCE_META = {
  violation: { color: 'error', icon: 'tabler-alert-octagon' },
  warning: { color: 'warning', icon: 'tabler-alert-triangle' },
  not_verifiable: { color: 'secondary', icon: 'tabler-help-circle' },
  not_applicable: { color: 'disabled', icon: 'tabler-minus' },
  pass: { color: 'success', icon: 'tabler-check' },
}
const complianceSummary = computed(() => compliance.value?.summary || {})
// On surface les contrôles ACTIONNABLES (violations, avertissements, non vérifiables) ; pass masqués.
const complianceSignals = computed(() => (compliance.value?.results || []).filter(r => ['violation', 'warning', 'not_verifiable'].includes(r.status)))
const hasComplianceSignals = computed(() => complianceSignals.value.length > 0)
const complianceColor = s => COMPLIANCE_META[s]?.color || 'secondary'
const complianceIcon = s => COMPLIANCE_META[s]?.icon || 'tabler-point'
const complianceRuleLabel = r => t(`timesheets.compliance.rules.${r.rule_key}`, r.rule_key)
const complianceStatusLabel = s => t(`timesheets.compliance.status.${s}`, s)

// ── Totaux distincts (lus du read model — jamais reconstitués) ──
const totals = computed(() => {
  const p = period.value || {}

  return [
    { key: 'planned', icon: 'tabler-calendar', color: 'primary', value: fmtMin(p.total_planned_minutes) },
    { key: 'worked', icon: 'tabler-clock-check', color: 'success', value: fmtMin(p.total_worked_minutes) },
    { key: 'overtime', icon: 'tabler-clock-plus', color: p.total_overtime_minutes > 0 ? 'warning' : 'secondary', value: fmtMin(p.total_overtime_minutes) },
    { key: 'breaks', icon: 'tabler-coffee', color: 'info', value: fmtMin(p.total_break_minutes) },
  ]
})

// ── Transitions (SmartActionBar) — strictement selon le moteur réel ──
const actionLoading = ref(false)
const showApprove = ref(false)
const showReject = ref(false)
const approvalNote = ref('')
const rejectionReason = ref('')

const run = async (fn, okMsg) => {
  actionLoading.value = true
  try {
    await fn()
    toast(okMsg, 'success')
    await reload()

    return true
  } catch (e) {
    // Une transition refusée par le serveur (422) ne doit JAMAIS apparaître comme réussie.
    toast(e.response?.data?.message || t('common.error'), 'error')
    await reload() // rafraîchissement contrôlé : l'état a peut-être changé entre-temps
    if (showApprove.value || showReject.value) { showApprove.value = false; showReject.value = false }

    return false
  } finally { actionLoading.value = false }
}

const doSubmit = () => run(() => store.submitTimesheet(period.value.id), t('timesheets.submitted'))
const doApprove = async () => {
  if (hasErrorAnomaly.value && !approvalNote.value) return // note requise si anomalie erreur (garde-fou aligné serveur)
  const ok = await run(() => store.approveTimesheet(period.value.id, { approvalNote: approvalNote.value || undefined }), t('timesheets.approved'))
  if (ok) { showApprove.value = false; approvalNote.value = '' }
}
const doReject = async () => {
  if (!rejectionReason.value) return
  const ok = await run(() => store.rejectTimesheet(period.value.id, { rejectionReason: rejectionReason.value }), t('timesheets.rejected'))
  if (ok) { showReject.value = false; rejectionReason.value = '' }
}
const doLock = () => run(() => store.lockTimesheet(period.value.id), t('timesheets.locked'))
// Régénère un brouillon (récupération d'une feuille rouverte restée vide, ou après corrections).
const doRegenerate = () => run(() => store.generateTimesheet({ employeeId: period.value.employee_id, periodStart: period.value.period_start, periodEnd: period.value.period_end }), t('timesheets.regenerated'))
// Réouverture : le serveur remet en brouillon SANS régénérer → on régénère ensuite (orchestration front, pas de nouvel état).
const doReopen = () => run(async () => {
  await store.reopenTimesheet(period.value.id)
  await store.generateTimesheet({ employeeId: period.value.employee_id, periodStart: period.value.period_start, periodEnd: period.value.period_end })
}, t('timesheets.reopened'))

const actions = computed(() => {
  const s = period.value?.status
  const list = []
  if (s === 'draft') {
    list.push({ id: 'submit', label: t('timesheets.actions.submit'), icon: 'tabler-send', permission: 'workforce.timesheet_submit', primary: true, color: 'primary', dataTest: 'ts-submit', handler: doSubmit })
    // Récupération : régénérer un brouillon (notamment après une réouverture dont la régénération a échoué).
    list.push({ id: 'regenerate', label: t('timesheets.actions.regenerate'), icon: 'tabler-refresh', permission: 'workforce.time_manage', variant: 'outlined', dataTest: 'ts-regenerate', handler: doRegenerate })
  }
  if (s === 'submitted') {
    list.push({ id: 'approve', label: t('timesheets.actions.approve'), icon: 'tabler-check', permission: 'workforce.timesheet_approve', primary: true, color: 'success', dataTest: 'ts-approve', handler: () => { approvalNote.value = ''; showApprove.value = true } })
    list.push({ id: 'reject', label: t('timesheets.actions.reject'), icon: 'tabler-x', permission: 'workforce.timesheet_approve', danger: true, dataTest: 'ts-reject', handler: () => { rejectionReason.value = ''; showReject.value = true } })
  }
  if (s === 'approved') list.push({ id: 'lock', label: t('timesheets.actions.lock'), icon: 'tabler-lock', permission: 'workforce.time_approve', primary: true, color: 'primary', dataTest: 'ts-lock', handler: doLock })
  if (s === 'rejected') list.push({ id: 'reopen', label: t('timesheets.actions.reopen'), icon: 'tabler-refresh', permission: 'workforce.time_manage', primary: true, color: 'warning', dataTest: 'ts-reopen', handler: doReopen })

  return list
})

// Explication quand une feuille ne peut pas être validée / état terminal.
const stateHint = computed(() => {
  const s = period.value?.status
  if (s === 'locked') return t('timesheets.hints.locked')
  if (s === 'approved') return t('timesheets.hints.approved')
  if (s === 'submitted' && hasErrorAnomaly.value) return t('timesheets.hints.errorAnomaly')

  return null
})

const actionLabel = a => t(`timesheets.history.${a}`, a)
</script>

<template>
  <div>
    <VBtn variant="text" :to="{ name: 'company-workforce-time', query: { tab: 'timesheets' } }" prepend-icon="tabler-arrow-left" class="mb-4">
      {{ $t('timesheets.backToList') }}
    </VBtn>

    <VCard v-if="loading" class="pa-8 text-center">
      <VProgressCircular indeterminate color="primary" />
    </VCard>

    <template v-else-if="period">
      <!-- En-tête : collaborateur + statut + période + actions -->
      <VCard class="mb-5">
        <VCardText>
          <div class="d-flex align-center gap-4 flex-wrap">
            <VAvatar color="primary" variant="tonal" size="56">
              <span class="text-h6 font-weight-medium">{{ (period.employee?.first_name?.[0] || '') + (period.employee?.last_name?.[0] || '') }}</span>
            </VAvatar>
            <div class="flex-grow-1" style="min-inline-size: 180px;">
              <h4 class="text-h5 font-weight-bold">{{ period.employee?.first_name }} {{ period.employee?.last_name }}</h4>
              <div class="d-flex align-center gap-2 mt-1 flex-wrap">
                <StatusChip :status="period.status" domain="timesheet">
                  {{ $t(`workforce.statuses.timesheet.${period.status}`) }}
                </StatusChip>
                <span class="text-body-2 text-medium-emphasis">{{ fmtDate(period.period_start) }} — {{ fmtDate(period.period_end) }}</span>
                <VChip v-if="period.anomaly_count > 0" size="small" color="error" variant="tonal" prepend-icon="tabler-alert-triangle">
                  {{ period.anomaly_count }} {{ $t('timesheets.summary.anomalies') }}
                </VChip>
              </div>
            </div>
            <!-- Transitions : boutons libellés gated par la permission EXACTE du moteur (lecture ≠ validation). -->
            <div class="d-flex gap-2 flex-wrap">
              <VBtn
                v-for="a in actions"
                v-show="can(a.permission)"
                :key="a.id"
                :color="a.color || (a.danger ? 'error' : 'primary')"
                :variant="a.variant || (a.danger ? 'outlined' : 'elevated')"
                :prepend-icon="a.icon"
                :loading="actionLoading"
                :data-test="a.dataTest"
                @click="a.handler()"
              >
                {{ a.label }}
              </VBtn>
            </div>
          </div>
          <VAlert v-if="stateHint" :type="period.status === 'locked' ? 'secondary' : (hasErrorAnomaly ? 'warning' : 'info')" variant="tonal" density="comfortable" class="mt-4 mb-0">
            {{ stateHint }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- Totaux distincts : planifié / constaté / heures sup. / pauses (lus du read model) -->
      <VRow class="card-grid card-grid-xs mb-5">
        <VCol v-for="tt in totals" :key="tt.key" cols="6" sm="3">
          <VCard>
            <VCardText class="d-flex align-center gap-3">
              <VAvatar :color="tt.color" variant="tonal" rounded><VIcon :icon="tt.icon" /></VAvatar>
              <div>
                <div class="text-h6 font-weight-bold">{{ tt.value }}</div>
                <div class="text-caption text-medium-emphasis">{{ $t(`timesheets.totals.${tt.key}`) }}</div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- Grille par jour : prévu / réel / pauses / travaillé / écart / anomalies -->
      <VCard class="mb-5">
        <VCardTitle class="pa-4 text-body-1 font-weight-medium">{{ $t('timesheets.grid.title') }}</VCardTitle>
        <VDivider />
        <!-- Desktop / tablette -->
        <VTable v-if="!xs" class="text-no-wrap" density="comfortable">
          <thead>
            <tr>
              <th>{{ $t('timesheets.grid.date') }}</th>
              <th>{{ $t('timesheets.totals.planned') }}</th>
              <th>{{ $t('timesheets.grid.clockIn') }}</th>
              <th>{{ $t('timesheets.grid.clockOut') }}</th>
              <th>{{ $t('timesheets.grid.break') }}</th>
              <th>{{ $t('timesheets.grid.worked') }}</th>
              <th>{{ $t('timeCockpit.col.gap') }}</th>
              <th>{{ $t('timesheets.grid.overtime') }}</th>
              <th>{{ $t('timesheets.grid.anomalies') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in lines" :key="l.id" :class="{ 'bg-light-info': l.is_leave_day }">
              <td><span class="font-weight-medium">{{ fmtDate(l.date) }}</span> <span class="text-caption text-medium-emphasis text-capitalize">{{ dayName(l.date) }}</span></td>
              <td>
                <VChip v-if="l.is_leave_day" size="x-small" color="info" variant="tonal">{{ $t(`timesheets.leaveTypes.${l.leave_type_code}`, l.leave_type_code) }}</VChip>
                <span v-else-if="l.is_rest_day" class="text-disabled">{{ $t('timesheets.grid.restDay') }}</span>
                <span v-else>{{ fmtMin(l.planned_minutes) }}</span>
              </td>
              <td>{{ fmtTime(l.clock_in) }}</td>
              <td>{{ fmtTime(l.clock_out) }}</td>
              <td :class="{ 'text-disabled': l.is_leave_day }">{{ fmtMin(l.break_minutes) }}</td>
              <td :class="{ 'text-disabled': l.is_leave_day }">{{ fmtMin(l.worked_minutes) }}</td>
              <td>
                <span v-if="l.gap !== null" :class="l.gap < 0 ? 'text-error' : (l.gap > 0 ? 'text-success' : '')">{{ l.gap > 0 ? '+' : '' }}{{ fmtMin(l.gap) }}</span>
                <span v-else class="text-disabled">—</span>
              </td>
              <td><span :class="l.daily_overtime_minutes > 0 ? 'text-warning' : 'text-disabled'">{{ fmtMin(l.daily_overtime_minutes) }}</span></td>
              <td>
                <template v-if="lineTechAnomalies(l).length">
                  <VIcon v-for="(a, i) in lineTechAnomalies(l)" :key="i" :icon="anomalyIcon(a.type)" :color="sevColor(a.severity)" size="20" class="me-1">
                    <VTooltip activator="parent" location="top">{{ anomalyLabel(a.type) }}<template v-if="anomalyDetail(a)"> · {{ anomalyDetail(a) }}</template></VTooltip>
                  </VIcon>
                </template>
                <span v-else class="text-disabled">—</span>
              </td>
            </tr>
          </tbody>
        </VTable>
        <!-- Mobile : liste par journée -->
        <VList v-else class="py-0">
          <template v-for="(l, idx) in lines" :key="l.id">
            <VListItem class="px-3 py-2" :class="{ 'bg-light-info': l.is_leave_day }">
              <div class="d-flex align-center justify-space-between gap-2">
                <span class="font-weight-medium"><span class="text-capitalize">{{ dayName(l.date) }}</span> {{ fmtDate(l.date) }}</span>
                <VChip v-if="l.is_leave_day" size="x-small" color="info" variant="tonal">{{ $t(`timesheets.leaveTypes.${l.leave_type_code}`, l.leave_type_code) }}</VChip>
                <span v-else-if="l.gap !== null" class="text-caption font-weight-medium" :class="l.gap < 0 ? 'text-error' : (l.gap > 0 ? 'text-success' : '')">{{ l.gap > 0 ? '+' : '' }}{{ fmtMin(l.gap) }}</span>
              </div>
              <div class="d-flex align-center flex-wrap gap-x-3 text-caption text-medium-emphasis mt-1">
                <span><VIcon icon="tabler-calendar" size="13" class="me-1" />{{ l.is_rest_day ? $t('timesheets.grid.restDay') : fmtMin(l.planned_minutes) }}</span>
                <span v-if="!l.is_leave_day"><VIcon icon="tabler-clock-record" size="13" class="me-1" />{{ fmtTime(l.clock_in) }}–{{ fmtTime(l.clock_out) }}</span>
                <span v-if="!l.is_leave_day" class="font-weight-medium">{{ fmtMin(l.worked_minutes) }}</span>
                <VIcon v-if="lineAnomSeverity(l)" icon="tabler-alert-triangle" :color="sevColor(lineAnomSeverity(l))" size="16" />
              </div>
            </VListItem>
            <VDivider v-if="idx < lines.length - 1" />
          </template>
        </VList>
      </VCard>

      <!-- P0 (ADR-701) — Heures supplémentaires CONSTATÉES, à QUALIFIER (≠ anomalie technique). -->
      <VCard v-if="hasOvertime" class="mb-5">
        <VCardTitle class="pa-4 text-body-1 font-weight-medium">
          <VIcon icon="tabler-clock-plus" size="20" color="warning" class="me-2" />{{ $t('timesheets.overtime.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <div class="d-flex flex-wrap gap-6 mb-3">
            <div>
              <div class="text-h6 font-weight-bold text-warning">{{ fmtMin(period.total_overtime_minutes) }}</div>
              <div class="text-caption text-medium-emphasis">{{ $t('timesheets.overtime.daily') }}</div>
            </div>
            <div v-if="weeklyOvertimeMinutes > 0">
              <div class="text-h6 font-weight-bold text-warning">{{ fmtMin(weeklyOvertimeMinutes) }}</div>
              <div class="text-caption text-medium-emphasis">{{ $t('timesheets.overtime.weekly') }}</div>
            </div>
          </div>
          <VAlert type="info" variant="tonal" density="comfortable" class="mb-0">
            {{ $t('timesheets.overtime.hint') }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- P1a — Contrôles réglementaires (constaté). Projection de consultation, SÉPARÉE des anomalies
           techniques et des heures sup. Ne bloque rien ; les heures constatées sont inchangées. -->
      <VCard v-if="hasComplianceSignals || complianceLoading" class="mb-5">
        <VCardTitle class="pa-4 text-body-1 font-weight-medium d-flex align-center flex-wrap gap-2">
          <span><VIcon icon="tabler-scale" size="20" class="me-2" />{{ $t('timesheets.compliance.title') }}</span>
          <VProgressCircular v-if="complianceLoading" indeterminate size="16" width="2" />
          <VSpacer />
          <VChip v-if="complianceSummary.violation" size="small" color="error" variant="tonal">{{ complianceSummary.violation }} {{ $t('timesheets.compliance.status.violation') }}</VChip>
          <VChip v-if="complianceSummary.warning" size="small" color="warning" variant="tonal">{{ complianceSummary.warning }} {{ $t('timesheets.compliance.status.warning') }}</VChip>
          <VChip v-if="complianceSummary.not_verifiable" size="small" color="secondary" variant="tonal">{{ complianceSummary.not_verifiable }} {{ $t('timesheets.compliance.status.not_verifiable') }}</VChip>
        </VCardTitle>
        <VDivider />
        <VCardText>
          <VList v-if="hasComplianceSignals" density="comfortable" class="py-0">
            <VListItem v-for="(r, i) in complianceSignals" :key="i" class="px-0">
              <template #prepend>
                <VIcon :icon="complianceIcon(r.status)" :color="complianceColor(r.status)" class="me-3" />
              </template>
              <VListItemTitle class="d-flex align-center flex-wrap gap-2">
                <span class="font-weight-medium">{{ complianceRuleLabel(r) }}</span>
                <VChip :color="complianceColor(r.status)" size="x-small" variant="tonal">{{ complianceStatusLabel(r.status) }}</VChip>
                <span v-if="r.period" class="text-caption text-medium-emphasis">· {{ r.period }}</span>
              </VListItemTitle>
              <VListItemSubtitle class="text-wrap mt-1">
                {{ r.message }}
                <span v-if="r.threshold_minutes != null && r.observed_minutes != null" class="text-medium-emphasis">
                  ({{ $t('timesheets.compliance.observed') }} {{ fmtMin(r.observed_minutes) }} / {{ $t('timesheets.compliance.threshold') }} {{ fmtMin(r.threshold_minutes) }})
                </span>
                <span v-if="r.reference" class="text-caption text-disabled"> · {{ r.reference }}</span>
              </VListItemSubtitle>
            </VListItem>
          </VList>
          <VAlert type="info" variant="tonal" density="comfortable" class="mt-3 mb-0">
            {{ $t('timesheets.compliance.hint') }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- Anomalies (types/sévérité/détails réels) -->
      <VCard v-if="hasAnomalies" class="mb-5">
        <VCardTitle class="pa-4 text-body-1 font-weight-medium">
          <VIcon icon="tabler-alert-triangle" size="20" color="warning" class="me-2" />{{ $t('timesheets.anomalies.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <div v-for="(items, type) in anomalyGroups" :key="type" class="mb-3">
            <div class="d-flex align-center gap-2 mb-1">
              <VIcon :icon="anomalyIcon(type)" :color="sevColor(items[0]?.severity)" size="20" />
              <span class="font-weight-medium">{{ anomalyLabel(type) }}</span>
              <VChip size="x-small" :color="sevColor(items[0]?.severity)" variant="tonal">{{ items.length }}</VChip>
            </div>
            <div v-for="(a, i) in items" :key="i" class="text-body-2 text-medium-emphasis ms-7">
              {{ fmtDate(a.date) }}<span v-if="anomalyDetail(a)"> · {{ anomalyDetail(a) }}</span>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Historique (workflow + corrections tracées) — projection scopée, lecture seule -->
      <VCard>
        <VCardTitle class="pa-4 text-body-1 font-weight-medium d-flex align-center">
          <VIcon icon="tabler-history" size="20" class="me-2" />{{ $t('timesheets.history.title') }}
          <VProgressCircular v-if="historyLoading" indeterminate size="16" width="2" class="ms-2" />
        </VCardTitle>
        <VDivider />
        <VCardText>
          <div v-if="!history.length" class="text-disabled text-body-2">{{ $t('timesheets.history.empty') }}</div>
          <VTimeline v-else side="end" density="compact" truncate-line="both" class="ms-1">
            <VTimelineItem
              v-for="(e, i) in history"
              :key="i"
              :dot-color="e.kind === 'correction' ? 'warning' : 'primary'"
              size="x-small"
            >
              <div class="d-flex justify-space-between flex-wrap gap-x-3">
                <span class="font-weight-medium">{{ actionLabel(e.action) }}</span>
                <span class="text-caption text-medium-emphasis">{{ fmtDateTime(e.at) }}</span>
              </div>
              <div class="text-body-2 text-medium-emphasis">
                <span v-if="e.actor">{{ e.actor }}</span>
                <template v-if="e.kind === 'correction' && e.change">
                  · {{ fmtDate(e.entry_date) }} : {{ fmtTime(e.change.old.clock_out) }} → {{ fmtTime(e.change.new.clock_out) }}
                </template>
              </div>
              <div v-if="e.reason" class="text-body-2 mt-1"><VIcon icon="tabler-message" size="14" class="me-1" />{{ e.reason }}</div>
            </VTimelineItem>
          </VTimeline>
        </VCardText>
      </VCard>
    </template>

    <!-- Dialogue d'approbation (note requise si anomalie erreur) -->
    <AppAdaptiveDialog v-model="showApprove" :title="$t('timesheets.dialogs.approve.title')" :max-width="500">
      <p class="text-body-2 mb-3">{{ $t('timesheets.dialogs.approve.message') }}</p>
      <VAlert v-if="hasErrorAnomaly" type="warning" variant="tonal" density="comfortable" class="mb-3">
        {{ $t('timesheets.dialogs.approve.errorAnomalyNote') }}
      </VAlert>
      <VTextarea
        v-model="approvalNote"
        :label="$t('timesheets.dialogs.approve.noteLabel') + (hasErrorAnomaly ? ' *' : '')"
        :rules="hasErrorAnomaly ? [v => !!v || $t('validation.required')] : []"
        rows="2"
        auto-grow
        data-test="ts-approve-note"
      />
      <template #actions="{ close }">
        <VSpacer />
        <VBtn variant="outlined" @click="close">{{ $t('common.cancel') }}</VBtn>
        <VBtn color="success" data-test="ts-approve-confirm" :loading="actionLoading" :disabled="hasErrorAnomaly && !approvalNote" @click="doApprove">
          {{ $t('timesheets.actions.approve') }}
        </VBtn>
      </template>
    </AppAdaptiveDialog>

    <!-- Dialogue de refus (motif requis) -->
    <AppAdaptiveDialog v-model="showReject" :title="$t('timesheets.dialogs.reject.title')" :max-width="500">
      <p class="text-body-2 mb-3">{{ $t('timesheets.dialogs.reject.message') }}</p>
      <VTextarea
        v-model="rejectionReason"
        :label="$t('timesheets.dialogs.reject.reasonLabel') + ' *'"
        :rules="[v => !!v || $t('validation.required')]"
        rows="2"
        auto-grow
        data-test="ts-reject-reason"
      />
      <template #actions="{ close }">
        <VSpacer />
        <VBtn variant="outlined" @click="close">{{ $t('common.cancel') }}</VBtn>
        <VBtn color="error" :loading="actionLoading" :disabled="!rejectionReason" data-test="ts-reject-confirm" @click="doReject">
          {{ $t('timesheets.actions.reject') }}
        </VBtn>
      </template>
    </AppAdaptiveDialog>
  </div>
</template>
