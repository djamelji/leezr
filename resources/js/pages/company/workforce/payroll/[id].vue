<script setup>
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'
import { usePayrollStore } from '@/modules/company/workforce/payroll.store'
import { useI18n } from 'vue-i18n'

definePage({ meta: { module: 'workforce_payroll', permission: 'workforce.payroll_prepare' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const route = useRoute()
const router = useRouter()
const store = usePayrollStore()

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

// ── Table headers ────────────────────────────────────────────────
const lineHeaders = [
  { title: t('payroll.lines.employee'), key: 'employee', sortable: false },
  { title: t('payroll.lines.worked'), key: 'worked_minutes', sortable: false, width: 100 },
  { title: t('payroll.lines.overtime'), key: 'total_overtime_minutes', sortable: false, width: 100 },
  { title: t('payroll.lines.grossBasis'), key: 'gross_basis_cents', sortable: false, width: 130 },
  { title: t('payroll.lines.netPayable'), key: 'net_payable', sortable: false, width: 130 },
  { title: t('payroll.lines.employerCost'), key: 'employer_cost', sortable: false, width: 130 },
  { title: t('payroll.lines.anomalies'), key: 'anomalies', sortable: false, width: 100 },
]

const contribHeaders = [
  { title: t('payroll.lineDetail.contributions.label'), key: 'label' },
  { title: t('payroll.lineDetail.contributions.base'), key: 'base_cents', align: 'end' },
  { title: t('payroll.lineDetail.contributions.rate'), key: 'rate_bps', align: 'end' },
  { title: t('payroll.lineDetail.contributions.employee'), key: 'employee_cents', align: 'end' },
  { title: t('payroll.lineDetail.contributions.employer'), key: 'employer_cents', align: 'end' },
]

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
function formatRate(bps) {
  if (bps == null) return '\u2014'
  return `${(bps / 100).toFixed(2)} %`
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

// ── Line detail ──────────────────────────────────────────────────
async function openLineDetail(item) {
  lineDetailLoading.value = true
  showLineDetailDialog.value = true
  try { await store.fetchLineDetail(route.params.id, item.id) }
  catch (e) { toast(e?.response?.data?.message || e.message, 'error'); showLineDetailDialog.value = false }
  finally { lineDetailLoading.value = false }
}
function closeLineDetail() {
  showLineDetailDialog.value = false
  store.clearLineDetail()
}

// ── Lifecycle ────────────────────────────────────────────────────
onMounted(() => reload())
onBeforeUnmount(() => store.clearCurrentRun())
</script>

<template>
  <div v-if="run">
    <!-- A. Back button -->
    <VBtn variant="text" :to="{ name: 'company-workforce-payroll' }" prepend-icon="tabler-arrow-left" class="mb-4">
      {{ $t('payroll.backToList') }}
    </VBtn>

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
            <VBtn v-if="run.status === 'draft'" v-can="'workforce.payroll_prepare'" color="info" :loading="store.loading" @click="handleCompute">
              <VIcon start icon="tabler-calculator" />{{ $t('payroll.actions.compute') }}
            </VBtn>
            <VBtn v-if="run.status === 'computed' && !hasCalculations" v-can="'workforce.payroll_prepare'" color="info" variant="outlined" :loading="store.loading" @click="handleComputeCalculations">
              <VIcon start icon="tabler-math-function" />{{ $t('payroll.actions.computeCalculations') }}
            </VBtn>
            <VBtn v-if="run.status === 'computed' && hasCalculations" v-can="'workforce.payroll_validate'" color="success" :loading="store.loading" @click="showValidateDialog = true">
              <VIcon start icon="tabler-check" />{{ $t('payroll.actions.validate') }}
            </VBtn>
            <VBtn v-if="run.status === 'validated'" v-can="'workforce.payroll_prepare'" variant="outlined" :loading="store.loading" @click="handleGenerateDrafts">
              <VIcon start icon="tabler-file-text" />{{ $t('payroll.actions.generateDrafts') }}
            </VBtn>
            <VBtn v-if="run.status === 'validated'" v-can="'workforce.payroll_prepare'" color="success" :loading="store.loading" @click="handleGenerateOfficial">
              <VIcon start icon="tabler-file-certificate" />{{ $t('payroll.actions.generateOfficial') }}
            </VBtn>
            <VBtn v-if="run.status === 'validated'" v-can="'workforce.payroll_export'" color="primary" :loading="store.loading" @click="handleExport">
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

    <!-- D. Totals cards row -->
    <VRow class="mb-6">
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-report-money" size="32" color="primary" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.gross') }}</div>
            <div class="text-h5 font-weight-bold text-primary">{{ formatCents(run.total_gross_cents) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-wallet" size="32" color="success" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.netPayable') }}</div>
            <div class="text-h5 font-weight-bold text-success">{{ formatCents(calculationTotals?.net_payable_cents) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard>
          <VCardText class="text-center">
            <VIcon icon="tabler-building-bank" size="32" color="warning" class="mb-2" />
            <div class="text-body-2 text-medium-emphasis mb-1">{{ $t('payroll.totals.employerCost') }}</div>
            <div class="text-h5 font-weight-bold text-warning">{{ formatCents(calculationTotals?.total_cost_employer_cents) }}</div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
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
            <div class="font-weight-medium">{{ item.employee_name }}</div>
            <div v-if="item.employee_number" class="text-body-2 text-medium-emphasis">{{ item.employee_number }}</div>
          </div>
        </template>
        <template #item.worked_minutes="{ item }">{{ formatHours(item.worked_minutes) }}</template>
        <template #item.total_overtime_minutes="{ item }">
          <span :class="item.total_overtime_minutes > 0 ? 'text-warning font-weight-medium' : ''">{{ formatHours(item.total_overtime_minutes) }}</span>
        </template>
        <template #item.gross_basis_cents="{ item }">{{ formatCents(item.gross_basis_cents) }}</template>
        <template #item.net_payable="{ item }">{{ formatCents(item.net_payable_cents) }}</template>
        <template #item.employer_cost="{ item }">{{ formatCents(item.employer_cost_cents) }}</template>
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
            <span class="me-3">{{ doc.template_code }}</span>
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

    <!-- H. Line detail dialog -->
    <VDialog v-model="showLineDetailDialog" max-width="700" @after-leave="closeLineDetail">
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>{{ $t('payroll.lineDetail.title') }}</span>
          <VBtn icon variant="text" size="small" @click="closeLineDetail"><VIcon icon="tabler-x" /></VBtn>
        </VCardTitle>
        <VCardText v-if="lineDetailLoading">
          <div class="d-flex justify-center py-8"><VProgressCircular indeterminate size="40" color="primary" /></div>
        </VCardText>
        <VCardText v-else-if="lineDetail">
          <!-- Employee & period -->
          <div class="mb-4">
            <h6 class="text-h6 font-weight-bold">{{ lineDetail.employee_name }}</h6>
            <div class="text-body-2 text-medium-emphasis">{{ formatPeriod(run) }}</div>
          </div>
          <VDivider class="mb-4" />

          <!-- Time section -->
          <h6 class="text-subtitle-1 font-weight-bold mb-2">{{ $t('payroll.lineDetail.time.title') }}</h6>
          <VRow class="mb-4">
            <VCol cols="6" sm="3">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.worked') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.worked_minutes) }}</div>
            </VCol>
            <VCol cols="6" sm="3">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.overtime') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.total_overtime_minutes) }}</div>
            </VCol>
            <VCol cols="6" sm="3">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.breaks') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.total_break_minutes) }}</div>
            </VCol>
            <VCol cols="6" sm="3">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.planned') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.planned_minutes) }}</div>
            </VCol>
          </VRow>
          <VDivider class="mb-4" />

          <!-- Leave section -->
          <h6 class="text-subtitle-1 font-weight-bold mb-2">{{ $t('payroll.lineDetail.leave.title') }}</h6>
          <VRow class="mb-4">
            <VCol cols="6" sm="4">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.leave.paid') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.paid_leave_minutes) }}</div>
            </VCol>
            <VCol cols="6" sm="4">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.leave.unpaid') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.unpaid_leave_minutes) }}</div>
            </VCol>
            <VCol cols="6" sm="4">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.leave.total') }}</div>
              <div class="font-weight-medium">{{ formatHours(lineDetail.total_leave_minutes) }}</div>
            </VCol>
          </VRow>
          <VDivider class="mb-4" />

          <!-- Compensation section -->
          <h6 class="text-subtitle-1 font-weight-bold mb-2">{{ $t('payroll.lineDetail.compensation.title') }}</h6>
          <VRow class="mb-4">
            <VCol cols="6">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.compensation.baseSalary') }}</div>
              <div class="font-weight-medium">{{ formatCents(lineDetail.base_salary_cents) }}</div>
            </VCol>
            <VCol cols="6">
              <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.compensation.grossBasis') }}</div>
              <div class="font-weight-medium">{{ formatCents(lineDetail.gross_basis_cents) }}</div>
            </VCol>
          </VRow>

          <!-- Calculation section -->
          <template v-if="lineDetail.calculation">
            <VDivider class="mb-4" />
            <h6 class="text-subtitle-1 font-weight-bold mb-2">{{ $t('payroll.lineDetail.calculation.title') }}</h6>
            <VRow class="mb-2">
              <VCol cols="6" sm="4">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.grossTotal') }}</div>
                <div class="font-weight-medium">{{ formatCents(lineDetail.calculation.gross_total_cents) }}</div>
              </VCol>
              <VCol cols="6" sm="4">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.contributionsEmployee') }}</div>
                <div class="font-weight-medium">{{ formatCents(lineDetail.calculation.contributions_employee_cents) }}</div>
              </VCol>
              <VCol cols="6" sm="4">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.contributionsEmployer') }}</div>
                <div class="font-weight-medium">{{ formatCents(lineDetail.calculation.contributions_employer_cents) }}</div>
              </VCol>
            </VRow>
            <VRow class="mb-4">
              <VCol cols="6" sm="3">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.tax') }}</div>
                <div class="font-weight-medium">{{ formatCents(lineDetail.calculation.tax_cents) }}</div>
              </VCol>
              <VCol cols="6" sm="3">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.netBeforeTax') }}</div>
                <div class="font-weight-medium">{{ formatCents(lineDetail.calculation.net_before_tax_cents) }}</div>
              </VCol>
              <VCol cols="6" sm="3">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.netPayable') }}</div>
                <div class="font-weight-medium text-success">{{ formatCents(lineDetail.calculation.net_payable_cents) }}</div>
              </VCol>
              <VCol cols="6" sm="3">
                <div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.totalEmployerCost') }}</div>
                <div class="font-weight-medium text-warning">{{ formatCents(lineDetail.calculation.total_cost_employer_cents) }}</div>
              </VCol>
            </VRow>

            <!-- Contribution lines table -->
            <template v-if="lineDetail.calculation.contribution_lines?.length">
              <VDivider class="mb-4" />
              <h6 class="text-subtitle-1 font-weight-bold mb-2">{{ $t('payroll.lineDetail.contributions.title') }}</h6>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th v-for="h in contribHeaders" :key="h.key" :class="h.align === 'end' ? 'text-end' : ''">{{ h.title }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(cl, idx) in lineDetail.calculation.contribution_lines" :key="idx">
                    <td>{{ cl.label }}</td>
                    <td class="text-end">{{ formatCents(cl.base_cents) }}</td>
                    <td class="text-end">{{ formatRate(cl.rate_bps) }}</td>
                    <td class="text-end">{{ formatCents(cl.employee_cents) }}</td>
                    <td class="text-end">{{ formatCents(cl.employer_cents) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </template>
          </template>
        </VCardText>
      </VCard>
    </VDialog>

    <!-- I. Validate dialog -->
    <VDialog v-model="showValidateDialog" max-width="500">
      <VCard>
        <VCardTitle>{{ $t('payroll.dialogs.validate.title') }}</VCardTitle>
        <VCardText>
          <p class="mb-4">{{ $t('payroll.dialogs.validate.message') }}</p>
          <AppTextarea v-model="validationNote" :label="$t('payroll.dialogs.validate.noteLabel')" :placeholder="$t('payroll.dialogs.validate.notePlaceholder')" rows="3" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="showValidateDialog = false">{{ $t('payroll.dialogs.cancel') }}</VBtn>
          <VBtn color="success" :loading="store.loading" @click="handleValidate">{{ $t('payroll.actions.validate') }}</VBtn>
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
