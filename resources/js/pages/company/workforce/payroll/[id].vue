<script setup>
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import StatusChip from '@/core/components/StatusChip.vue'
import { usePayrollStore } from '@/modules/company/workforce/payroll.store'
import { useI18n } from 'vue-i18n'
import PayrollDisclaimer from './_PayrollDisclaimer.vue'
import EmployeePreparationPanel from './_EmployeePreparationPanel.vue'

definePage({ meta: { module: 'workforce_payroll', permission: 'workforce.payroll_prepare' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const { can } = useCan()
const route = useRoute()
const router = useRouter()
const store = usePayrollStore()

// RBAC: amounts only for compensation_read holders (UI masking — see C1 ADR).
const canReadComp = computed(() => can('workforce.compensation_read'))

// ── Reactive state ───────────────────────────────────────────────
const showValidateDialog = ref(false)
const showDeleteDialog = ref(false)
const showLineDetailDialog = ref(false)
const validationNote = ref('')
const lineDetailLoading = ref(false)

// ── Computed ─────────────────────────────────────────────────────
const run = computed(() => store.currentRun)
const calculationTotals = computed(() => store.currentRunTotals)
const hasCalculations = computed(() => store.currentRunCalculations?.length > 0)
const lineDetail = computed(() => store.currentLineDetail)
const runDocuments = computed(() => store.currentRunDocuments)

const workflowSteps = computed(() => {
  const statusOrder = ['draft', 'computed', 'validated', 'exported']
  const idx = statusOrder.indexOf(run.value?.status)
  return [
    { key: 'draft', label: t('payroll.workflow.draft'), icon: 'tabler-edit', color: 'secondary' },
    { key: 'computed', label: t('payroll.workflow.computed'), icon: 'tabler-calculator', color: 'info' },
    { key: 'validated', label: t('payroll.workflow.validated'), icon: 'tabler-check', color: 'success' },
    { key: 'exported', label: t('payroll.workflow.exported'), icon: 'tabler-download', color: 'primary' },
  ].map((s, i) => ({ ...s, reached: i <= idx, active: i === idx }))
})

// ── Preparation guidance (honest, derived from status + calculations) ────────
const errorAnomalies = computed(() => (run.value?.anomalies ?? []).filter(a => a.severity === 'error'))
const preparation = computed(() => {
  const status = run.value?.status
  if (status === 'draft')
    return { step: t('payroll.prep.draft'), hint: t('payroll.prep.draftHint'), color: 'info', icon: 'tabler-calculator' }
  if (status === 'computed' && !hasCalculations.value)
    return { step: t('payroll.prep.computed'), hint: t('payroll.prep.computedHint'), color: 'info', icon: 'tabler-math-function' }
  if (status === 'computed')
    return errorAnomalies.value.length
      ? { step: t('payroll.prep.blocked'), hint: t('payroll.prep.blockedHint'), color: 'error', icon: 'tabler-alert-circle' }
      : { step: t('payroll.prep.readyToValidate'), hint: t('payroll.prep.readyToValidateHint'), color: 'warning', icon: 'tabler-checks' }
  if (status === 'validated')
    return { step: t('payroll.prep.validated'), hint: t('payroll.prep.validatedHint'), color: 'success', icon: 'tabler-file-certificate' }
  if (status === 'exported')
    return { step: t('payroll.prep.exported'), hint: t('payroll.prep.exportedHint'), color: 'success', icon: 'tabler-lock-check' }
  return { step: '—', hint: '', color: 'secondary', icon: 'tabler-help' }
})

// Per-employee preparation state (derived from line anomalies + run status).
function lineReadiness(line) {
  const anomalies = line.anomalies ?? []
  if (anomalies.some(a => a.severity === 'error'))
    return { label: t('payroll.lineStatus.toFix'), color: 'error', icon: 'tabler-alert-circle' }
  if (anomalies.length)
    return { label: t('payroll.lineStatus.warning'), color: 'warning', icon: 'tabler-alert-triangle' }
  if (run.value?.status === 'draft')
    return { label: t('payroll.lineStatus.toCompute'), color: 'info', icon: 'tabler-calculator' }
  return { label: t('payroll.lineStatus.ready'), color: 'success', icon: 'tabler-check' }
}

// ── Table headers ────────────────────────────────────────────────
const lineHeaders = computed(() => [
  { title: t('payroll.lines.employee'), key: 'employee', sortable: false },
  { title: t('payroll.lines.worked'), key: 'worked_minutes', sortable: false, width: 100 },
  { title: t('payroll.lines.overtime'), key: 'total_overtime_minutes', sortable: false, width: 100 },
  ...(canReadComp.value ? [
    { title: t('payroll.lines.grossBasis'), key: 'gross_basis_cents', sortable: false, width: 130 },
    { title: t('payroll.lines.netPayable'), key: 'net_payable', sortable: false, width: 130 },
    { title: t('payroll.lines.employerCost'), key: 'employer_cost', sortable: false, width: 130 },
  ] : []),
  { title: t('payroll.lines.preparation'), key: 'preparation', sortable: false, width: 130 },
  { title: t('payroll.lines.anomalies'), key: 'anomalies', sortable: false, width: 100 },
])

// ── Helpers ──────────────────────────────────────────────────────
function formatCents(cents) {
  if (cents == null) return '\u2014'
  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(cents / 100)
}
function formatHours(minutes) {
  if (minutes == null) return '\u2014'
  return `${Math.floor(minutes / 60)}h${(minutes % 60).toString().padStart(2, '0')}`
}
function formatPeriod(r) {
  if (!r) return ''
  const opts = { day: '2-digit', month: 'short', year: 'numeric' }
  return `${new Date(r.period_start).toLocaleDateString(locale.value, opts)} \u2014 ${new Date(r.period_end).toLocaleDateString(locale.value, opts)}`
}
function formatDate(d) {
  if (!d) return '\u2014'
  return new Date(d).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

// ── Data loading ─────────────────────────────────────────────────
async function reload() {
  await Promise.all([
    store.fetchRun(route.params.id),
    store.fetchLines(route.params.id),
    store.fetchCalculations(route.params.id),
    store.fetchRunDocuments(route.params.id),
  ])
}
function onLineOptionsUpdate(options) {
  store.fetchLines(route.params.id, { page: options.page, perPage: options.itemsPerPage })
}

// ── Action handlers ──────────────────────────────────────────────
async function withToast(fn, successKey) {
  try { await fn(); toast(t(successKey), 'success'); await reload() }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error') }
}
const handleCompute = () => withToast(() => store.computeRun(run.value.id), 'payroll.computed')
const handleComputeCalculations = () => withToast(() => store.computeCalculations(run.value.id), 'payroll.calculationsComputed')
const handleExport = () => withToast(() => store.exportRun(run.value.id), 'payroll.exported')
const handleRecompute = () => withToast(() => store.recomputeRun(run.value.id), 'payroll.recomputed')

async function handleValidate() {
  try {
    await store.validateRun(run.value.id, validationNote.value || null)
    toast(t('payroll.validated'), 'success')
    showValidateDialog.value = false
    validationNote.value = ''
    await reload()
  }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error') }
}
async function handleDelete() {
  try {
    await store.deleteRun(run.value.id)
    toast(t('payroll.deleted'), 'success')
    router.push({ name: 'company-workforce-payroll' })
  }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error') }
}
async function handleGenerateDrafts() {
  try {
    await store.generatePayslipDrafts(run.value.id)
    toast(t('payroll.payslips.draftsGenerated'), 'success')
    await store.fetchRunDocuments(route.params.id)
  }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error') }
}
async function handleGenerateOfficial() {
  try {
    await store.generateOfficialPayslips(run.value.id)
    toast(t('payroll.payslips.officialGenerated'), 'success')
    await store.fetchRunDocuments(route.params.id)
  }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error') }
}

// ── Employee preparation panel ───────────────────────────────────
const openLineId = ref(null)
const canRecompute = computed(() => run.value?.status === 'computed' && can('workforce.payroll_prepare'))
const panelEmployeeId = computed(() => lineDetail.value?.employee?.id ?? null)

async function openLineDetail(item) {
  openLineId.value = item.id
  lineDetailLoading.value = true
  showLineDetailDialog.value = true
  // Deep-link the opened collaborator so the panel survives back-navigation.
  if (route.query.line !== String(item.id)) router.replace({ query: { ...route.query, line: item.id } })
  try { await store.fetchLineDetail(route.params.id, item.id) }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error'); showLineDetailDialog.value = false }
  finally { lineDetailLoading.value = false }
}
function closeLineDetail() {
  showLineDetailDialog.value = false
  openLineId.value = null
  store.clearLineDetail()
  if (route.query.line) {
    const q = { ...route.query }; delete q.line
    router.replace({ query: q })
  }
}

// Current period URL (with the selected collaborator) — used to return here.
const returnUrl = computed(() => `/company/workforce/payroll/${route.params.id}${openLineId.value ? `?line=${openLineId.value}` : ''}`)

// Contextual navigation to the SOURCE, carrying a return path to this period.
function goTimesheet() {
  if (!panelEmployeeId.value) return
  router.push({ name: 'company-workforce-time', query: { tab: 'timesheets', employee_id: panelEmployeeId.value, return: returnUrl.value } })
}
function goContract() {
  if (!panelEmployeeId.value) return
  router.push({ name: 'company-workforce-employees-id', params: { id: panelEmployeeId.value }, query: { tab: 'contract' } })
}
function goFiche() {
  if (!panelEmployeeId.value) return
  router.push({ name: 'company-workforce-employees-id', params: { id: panelEmployeeId.value } })
}
function goLeave() {
  if (!panelEmployeeId.value) return
  router.push({ name: 'company-workforce-leave', query: { employee_id: panelEmployeeId.value, return: returnUrl.value } })
}
async function handlePanelRecompute() {
  if (!run.value) return
  await withToast(() => store.recomputeRun(run.value.id), 'payroll.recomputed')
  if (openLineId.value) await store.fetchLineDetail(route.params.id, openLineId.value)
}

// ── Lifecycle ────────────────────────────────────────────────────
onMounted(async () => {
  await reload()
  // Re-open the collaborator panel when returning via a deep-link (?line=).
  const lineParam = route.query.line
  if (lineParam) {
    const item = store.currentRunLines.find(l => String(l.id) === String(lineParam))
    if (item) openLineDetail(item)
  }
})
onBeforeUnmount(() => store.clearCurrentRun())
</script>

<template>
  <div v-if="run">
    <!-- A. Back button -->
    <VBtn variant="text" :to="{ name: 'company-workforce-payroll' }" prepend-icon="tabler-arrow-left" class="mb-4">
      {{ $t('payroll.backToList') }}
    </VBtn>

    <!-- Honest capability disclaimer -->
    <PayrollDisclaimer />

    <!-- B. Header card -->
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-4">
          <div>
            <h4 class="text-h4 font-weight-bold mb-1">{{ formatPeriod(run) }}</h4>
            <div class="d-flex align-center gap-3">
              <StatusChip domain="payrollRun" :status="run.status" />
              <span class="text-body-2 text-medium-emphasis">{{ run.employee_count }} {{ $t('payroll.employees') }}</span>
            </div>
          </div>
          <!-- Workflow actions -->
          <div class="d-flex gap-2 flex-wrap">
            <VBtn v-if="run.status === 'draft'" v-can="'workforce.payroll_prepare'" color="info" :loading="store.loading" data-test="pr-compute" @click="handleCompute">
              <VIcon start icon="tabler-calculator" />{{ $t('payroll.actions.compute') }}
            </VBtn>
            <VBtn v-if="run.status === 'computed' && !hasCalculations" v-can="'workforce.payroll_prepare'" color="info" variant="outlined" :loading="store.loading" data-test="pr-compute-calc" @click="handleComputeCalculations">
              <VIcon start icon="tabler-math-function" />{{ $t('payroll.actions.computeCalculations') }}
            </VBtn>
            <VBtn v-if="run.status === 'computed' && hasCalculations" v-can="'workforce.payroll_validate'" color="success" :loading="store.loading" data-test="pr-validate-open" @click="showValidateDialog = true">
              <VIcon start icon="tabler-check" />{{ $t('payroll.actions.validate') }}
            </VBtn>
            <VBtn v-if="run.status === 'validated'" v-can="'workforce.payroll_prepare'" variant="outlined" :loading="store.loading" @click="handleGenerateDrafts">
              <VIcon start icon="tabler-file-text" />{{ $t('payroll.actions.generateDrafts') }}
            </VBtn>
            <VBtn v-if="run.status === 'validated'" v-can="'workforce.payroll_prepare'" color="success" :loading="store.loading" :title="$t('payroll.actions.generateOfficialHint')" @click="handleGenerateOfficial">
              <VIcon start icon="tabler-file-certificate" />{{ $t('payroll.actions.generateOfficial') }}
            </VBtn>
            <VBtn v-if="run.status === 'validated'" v-can="'workforce.payroll_export'" color="primary" :loading="store.loading" data-test="pr-export" :title="$t('payroll.actions.exportHint')" @click="handleExport">
              <VIcon start icon="tabler-download" />{{ $t('payroll.actions.export') }}
            </VBtn>
            <VBtn v-if="run.status === 'computed'" v-can="'workforce.payroll_prepare'" color="warning" variant="outlined" :loading="store.loading" @click="handleRecompute">
              <VIcon start icon="tabler-refresh" />{{ $t('payroll.actions.recompute') }}
            </VBtn>
            <VBtn v-if="run.status === 'draft'" v-can="'workforce.payroll_prepare'" color="error" variant="outlined" :loading="store.loading" @click="showDeleteDialog = true">
              <VIcon start icon="tabler-trash" />{{ $t('payroll.actions.delete') }}
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- C. Workflow stepper -->
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex align-center justify-space-between">
          <template v-for="(step, i) in workflowSteps" :key="step.key">
            <div class="d-flex align-center gap-2">
              <VAvatar :color="step.reached ? step.color : 'grey-lighten-2'" :variant="step.active ? 'elevated' : 'tonal'" size="36">
                <VIcon :icon="step.icon" size="20" />
              </VAvatar>
              <span :class="step.reached ? 'font-weight-bold' : 'text-medium-emphasis'" class="text-body-2">{{ step.label }}</span>
            </div>
            <VDivider v-if="i < workflowSteps.length - 1" class="mx-2" />
          </template>
        </div>
      </VCardText>
    </VCard>

    <!-- C-bis. Preparation guidance (explicit next action) -->
    <VAlert :color="preparation.color" variant="tonal" class="mb-6" border="start">
      <div class="d-flex align-center gap-3">
        <VIcon :icon="preparation.icon" size="24" />
        <div>
          <div class="font-weight-bold">{{ $t('payroll.prep.label') }}: {{ preparation.step }}</div>
          <div class="text-body-2">{{ preparation.hint }}</div>
        </div>
      </div>
    </VAlert>

    <!-- D. Totals cards row -->
    <VRow class="mb-6">
      <VCol v-if="canReadComp" cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-report-money" size="32" color="primary" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.gross') }}</div>
            <div class="text-h5 font-weight-bold text-primary">{{ formatCents(run.total_gross_cents) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="canReadComp" cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-wallet" size="32" color="success" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.netPayable') }}</div>
            <div class="text-h5 font-weight-bold text-success">{{ formatCents(calculationTotals?.net_payable_cents) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol v-if="canReadComp" cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-building-bank" size="32" color="warning" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.employerCost') }}</div>
            <div class="text-h5 font-weight-bold text-warning">{{ formatCents(calculationTotals?.total_cost_employer_cents) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" :sm="canReadComp ? 6 : 12" :md="canReadComp ? 3 : 12">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-alert-triangle" size="32" :color="run.anomaly_count > 0 ? 'error' : 'grey'" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.anomalies') }}</div>
            <div class="text-h5 font-weight-bold" :class="run.anomaly_count > 0 ? 'text-error' : 'text-grey'">{{ run.anomaly_count ?? 0 }}</div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- E. Anomaly banner -->
    <VAlert v-if="run.anomaly_count > 0 && run.anomalies?.length" type="warning" variant="tonal" class="mb-6" closable>
      <VAlertTitle>{{ $t('payroll.anomalies.title') }}</VAlertTitle>
      <div v-for="anomaly in run.anomalies" :key="`${anomaly.employee_id}-${anomaly.type}`" class="mt-1">
        <VIcon :icon="anomaly.severity === 'error' ? 'tabler-alert-circle' : 'tabler-alert-triangle'" :color="anomaly.severity === 'error' ? 'error' : 'warning'" size="16" class="me-1" />
        {{ $t(`payroll.anomalies.types.${anomaly.type}`, anomaly.type) }}
        <span v-if="anomaly.employee_name" class="text-medium-emphasis">&mdash; {{ anomaly.employee_name }}</span>
      </div>
    </VAlert>

    <!-- F. Employee lines table -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ $t('payroll.lines.title') }}</span>
        <VChip size="small" color="primary" variant="tonal">{{ store.currentRunTotalLines }} {{ $t('payroll.employees') }}</VChip>
      </VCardTitle>
      <VDataTableServer
        :headers="lineHeaders" :items="store.currentRunLines" :items-length="store.currentRunTotalLines"
        :loading="store.loading" :items-per-page="15"
        @update:options="onLineOptionsUpdate" @click:row="(_event, { item }) => openLineDetail(item)"
      >
        <template #item.employee="{ item }">
          <div>
            <div class="font-weight-medium">{{ item.employee ? `${item.employee.first_name} ${item.employee.last_name}` : (item.employee_name || '—') }}</div>
            <div v-if="item.employee?.employee_number || item.employee_number" class="text-body-2 text-medium-emphasis">{{ item.employee?.employee_number || item.employee_number }}</div>
          </div>
        </template>
        <template #item.worked_minutes="{ item }">{{ formatHours(item.worked_minutes) }}</template>
        <template #item.total_overtime_minutes="{ item }">
          <span :class="item.total_overtime_minutes > 0 ? 'text-warning font-weight-medium' : ''">{{ formatHours(item.total_overtime_minutes) }}</span>
        </template>
        <template #item.gross_basis_cents="{ item }">{{ formatCents(item.gross_basis_cents) }}</template>
        <template #item.net_payable="{ item }">{{ formatCents(item.net_payable_cents) }}</template>
        <template #item.employer_cost="{ item }">{{ formatCents(item.employer_cost_cents) }}</template>
        <template #item.preparation="{ item }">
          <VChip size="small" :color="lineReadiness(item).color" variant="tonal">
            <VIcon :icon="lineReadiness(item).icon" size="14" class="me-1" />
            {{ lineReadiness(item).label }}
          </VChip>
        </template>
        <template #item.anomalies="{ item }">
          <VIcon v-if="item.has_anomalies" icon="tabler-alert-triangle" color="warning" size="20" />
          <span v-else class="text-medium-emphasis">&mdash;</span>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- G. Documents section -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center gap-2">
        <VIcon icon="tabler-file-certificate" size="22" />
        {{ $t('payroll.documents.title') }}
      </VCardTitle>
      <VCardText v-if="runDocuments.length === 0">
        <div class="text-center text-medium-emphasis py-6">
          <VIcon icon="tabler-file-off" size="48" class="mb-2" />
          <div class="text-body-1">{{ $t('payroll.documents.empty') }}</div>
        </div>
      </VCardText>
      <VList v-else lines="two">
        <VListItem v-for="doc in runDocuments" :key="doc.id">
          <template #prepend>
            <VAvatar color="primary" variant="tonal" size="40"><VIcon icon="tabler-file-text" size="20" /></VAvatar>
          </template>
          <VListItemTitle>{{ doc.name }}</VListItemTitle>
          <VListItemSubtitle>
            <span class="text-medium-emphasis">{{ formatDate(doc.generated_at) }}</span>
          </VListItemSubtitle>
          <template #append>
            <VChip v-if="doc.signature_status" size="small" :color="doc.signature_status === 'signed' ? 'success' : 'warning'" variant="tonal">
              {{ $t(`payroll.documents.signature.${doc.signature_status}`) }}
            </VChip>
          </template>
        </VListItem>
      </VList>
    </VCard>

    <!-- H. Employee preparation panel (C2) -->
    <EmployeePreparationPanel
      v-model="showLineDetailDialog"
      :detail="lineDetail"
      :run="run"
      :loading="lineDetailLoading"
      :can-read-comp="canReadComp"
      :can-recompute="canRecompute"
      @update:model-value="v => { if (!v) closeLineDetail() }"
      @go-timesheet="goTimesheet"
      @go-contract="goContract"
      @go-leave="goLeave"
      @go-fiche="goFiche"
      @recompute="handlePanelRecompute"
    />

    <!-- I. Validate dialog -->
    <VDialog v-model="showValidateDialog" max-width="500">
      <VCard>
        <VCardTitle>{{ $t('payroll.dialogs.validate.title') }}</VCardTitle>
        <VCardText>
          <p class="mb-4">{{ $t('payroll.dialogs.validate.message') }}</p>
          <AppTextField v-model="validationNote" data-test="pr-validate-note" type="textarea" :label="$t('payroll.dialogs.validate.noteLabel')" :placeholder="$t('payroll.dialogs.validate.notePlaceholder')" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showValidateDialog = false">{{ $t('payroll.dialogs.cancel') }}</VBtn>
          <VBtn data-test="pr-validate-confirm" color="success" :loading="store.loading" @click="handleValidate">{{ $t('payroll.actions.validate') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- J. Delete confirmation dialog -->
    <VDialog v-model="showDeleteDialog" max-width="400">
      <VCard>
        <VCardTitle>{{ $t('payroll.dialogs.delete.title') }}</VCardTitle>
        <VCardText><p>{{ $t('payroll.dialogs.delete.message') }}</p></VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showDeleteDialog = false">{{ $t('payroll.dialogs.cancel') }}</VBtn>
          <VBtn color="error" :loading="store.loading" @click="handleDelete">{{ $t('payroll.actions.delete') }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

  <!-- Loading state -->
  <div v-else-if="store.loading" class="d-flex justify-center align-center py-16">
    <VProgressCircular indeterminate size="48" color="primary" />
  </div>
</template>
