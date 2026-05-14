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
const validationNote = ref('')

// ── Computed ─────────────────────────────────────────────────────
const run = computed(() => store.currentRun)
const calculationTotals = computed(() => store.currentRunTotals)
const hasCalculations = computed(() => store.currentRunCalculations?.length > 0)

// ── Workflow stepper ─────────────────────────────────────────────
const workflowSteps = computed(() => {
  const statusOrder = ['draft', 'computed', 'validated', 'exported']
  const currentIndex = statusOrder.indexOf(run.value?.status)

  return [
    { key: 'draft', label: t('payroll.workflow.draft'), icon: 'tabler-edit', color: 'secondary' },
    { key: 'computed', label: t('payroll.workflow.computed'), icon: 'tabler-calculator', color: 'info' },
    { key: 'validated', label: t('payroll.workflow.validated'), icon: 'tabler-check', color: 'success' },
    { key: 'exported', label: t('payroll.workflow.exported'), icon: 'tabler-download', color: 'primary' },
  ].map((step, i) => ({
    ...step,
    reached: i <= currentIndex,
    active: i === currentIndex,
  }))
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

// ── Helpers (display only, no calculation) ───────────────────────
function formatCents(cents) {
  if (cents == null)
    return '\u2014'

  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(cents / 100)
}

function formatHours(minutes) {
  if (minutes == null)
    return '\u2014'

  const h = Math.floor(minutes / 60)
  const m = minutes % 60

  return `${h}h${m.toString().padStart(2, '0')}`
}

function formatPeriod(r) {
  if (!r)
    return ''

  const start = new Date(r.period_start).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' })
  const end = new Date(r.period_end).toLocaleDateString(locale.value, { day: '2-digit', month: 'short', year: 'numeric' })

  return `${start} \u2014 ${end}`
}

// ── Data loading ─────────────────────────────────────────────────
async function reload() {
  await Promise.all([
    store.fetchRun(route.params.id),
    store.fetchLines(route.params.id),
    store.fetchCalculations(route.params.id),
  ])
}

function onLineOptionsUpdate(options) {
  store.fetchLines(route.params.id, {
    page: options.page,
    perPage: options.itemsPerPage,
  })
}

// ── Action handlers ──────────────────────────────────────────────
async function handleCompute() {
  try {
    await store.computeRun(run.value.id)
    toast(t('payroll.computed'), 'success')
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

async function handleComputeCalculations() {
  try {
    await store.computeCalculations(run.value.id)
    toast(t('payroll.calculationsComputed'), 'success')
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

async function handleValidate() {
  try {
    await store.validateRun(run.value.id, validationNote.value || null)
    toast(t('payroll.validated'), 'success')
    showValidateDialog.value = false
    validationNote.value = ''
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

async function handleExport() {
  try {
    await store.exportRun(run.value.id)
    toast(t('payroll.exported'), 'success')
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

async function handleRecompute() {
  try {
    await store.recomputeRun(run.value.id)
    toast(t('payroll.recomputed'), 'success')
    await reload()
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

async function handleDelete() {
  try {
    await store.deleteRun(run.value.id)
    toast(t('payroll.deleted'), 'success')
    router.push({ name: 'company-workforce-payroll' })
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  }
}

// ── Lifecycle ────────────────────────────────────────────────────
onMounted(() => reload())

onBeforeUnmount(() => store.clearCurrentRun())
</script>

<template>
  <div v-if="run">
    <!-- A. Back button -->
    <VBtn
      variant="text"
      :to="{ name: 'company-workforce-payroll' }"
      prepend-icon="tabler-arrow-left"
      class="mb-4"
    >
      {{ $t('payroll.backToList') }}
    </VBtn>

    <!-- B. Header card -->
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex align-center justify-space-between flex-wrap gap-4">
          <!-- Run info -->
          <div>
            <h4 class="text-h4 font-weight-bold mb-1">
              {{ formatPeriod(run) }}
            </h4>
            <div class="d-flex align-center gap-3">
              <StatusChip
                domain="payrollRun"
                :status="run.status"
              />
              <span class="text-body-2 text-medium-emphasis">
                {{ run.employee_count }} {{ $t('payroll.employees') }}
              </span>
            </div>
          </div>

          <!-- Workflow actions -->
          <div class="d-flex gap-2 flex-wrap">
            <!-- Compute: only if draft -->
            <VBtn
              v-if="run.status === 'draft'"
              v-can="'workforce.payroll_prepare'"
              color="info"
              :loading="store.loading"
              @click="handleCompute"
            >
              <VIcon
                start
                icon="tabler-calculator"
              />
              {{ $t('payroll.actions.compute') }}
            </VBtn>

            <!-- Compute Calculations: only if computed AND no calculations yet -->
            <VBtn
              v-if="run.status === 'computed' && !hasCalculations"
              v-can="'workforce.payroll_prepare'"
              color="info"
              variant="outlined"
              :loading="store.loading"
              @click="handleComputeCalculations"
            >
              <VIcon
                start
                icon="tabler-math-function"
              />
              {{ $t('payroll.actions.computeCalculations') }}
            </VBtn>

            <!-- Validate: only if computed AND calculations done -->
            <VBtn
              v-if="run.status === 'computed' && hasCalculations"
              v-can="'workforce.payroll_validate'"
              color="success"
              :loading="store.loading"
              @click="showValidateDialog = true"
            >
              <VIcon
                start
                icon="tabler-check"
              />
              {{ $t('payroll.actions.validate') }}
            </VBtn>

            <!-- Export: only if validated -->
            <VBtn
              v-if="run.status === 'validated'"
              v-can="'workforce.payroll_export'"
              color="primary"
              :loading="store.loading"
              @click="handleExport"
            >
              <VIcon
                start
                icon="tabler-download"
              />
              {{ $t('payroll.actions.export') }}
            </VBtn>

            <!-- Recompute: only if computed -->
            <VBtn
              v-if="run.status === 'computed'"
              v-can="'workforce.payroll_prepare'"
              color="warning"
              variant="outlined"
              :loading="store.loading"
              @click="handleRecompute"
            >
              <VIcon
                start
                icon="tabler-refresh"
              />
              {{ $t('payroll.actions.recompute') }}
            </VBtn>

            <!-- Delete: only if draft -->
            <VBtn
              v-if="run.status === 'draft'"
              v-can="'workforce.payroll_prepare'"
              color="error"
              variant="outlined"
              :loading="store.loading"
              @click="showDeleteDialog = true"
            >
              <VIcon
                start
                icon="tabler-trash"
              />
              {{ $t('payroll.actions.delete') }}
            </VBtn>
          </div>
        </div>
      </VCardText>
    </VCard>

    <!-- C. Workflow stepper -->
    <VCard class="mb-6">
      <VCardText>
        <div class="d-flex align-center justify-space-between">
          <template
            v-for="(step, i) in workflowSteps"
            :key="step.key"
          >
            <div class="d-flex align-center gap-2">
              <VAvatar
                :color="step.reached ? step.color : 'grey-lighten-2'"
                :variant="step.active ? 'elevated' : 'tonal'"
                size="36"
              >
                <VIcon
                  :icon="step.icon"
                  size="20"
                />
              </VAvatar>
              <span
                :class="step.reached ? 'font-weight-bold' : 'text-medium-emphasis'"
                class="text-body-2"
              >
                {{ step.label }}
              </span>
            </div>
            <VDivider
              v-if="i < workflowSteps.length - 1"
              class="mx-2"
            />
          </template>
        </div>
      </VCardText>
    </VCard>

    <!-- D. Totals cards row -->
    <VRow class="mb-6">
      <!-- Gross Total -->
      <VCol
        cols="12"
        sm="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-report-money"
              size="32"
              color="primary"
              class="mb-2"
            />
            <div class="text-body-2 text-medium-emphasis mb-1">
              {{ $t('payroll.totals.gross') }}
            </div>
            <div class="text-h5 font-weight-bold text-primary">
              {{ formatCents(run.total_gross_cents) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Net Payable -->
      <VCol
        cols="12"
        sm="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-wallet"
              size="32"
              color="success"
              class="mb-2"
            />
            <div class="text-body-2 text-medium-emphasis mb-1">
              {{ $t('payroll.totals.netPayable') }}
            </div>
            <div class="text-h5 font-weight-bold text-success">
              {{ formatCents(calculationTotals?.net_payable_cents) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Employer Cost -->
      <VCol
        cols="12"
        sm="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-building-bank"
              size="32"
              color="warning"
              class="mb-2"
            />
            <div class="text-body-2 text-medium-emphasis mb-1">
              {{ $t('payroll.totals.employerCost') }}
            </div>
            <div class="text-h5 font-weight-bold text-warning">
              {{ formatCents(calculationTotals?.total_cost_employer_cents) }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Anomalies -->
      <VCol
        cols="12"
        sm="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-alert-triangle"
              size="32"
              :color="run.anomaly_count > 0 ? 'error' : 'grey'"
              class="mb-2"
            />
            <div class="text-body-2 text-medium-emphasis mb-1">
              {{ $t('payroll.totals.anomalies') }}
            </div>
            <div
              class="text-h5 font-weight-bold"
              :class="run.anomaly_count > 0 ? 'text-error' : 'text-grey'"
            >
              {{ run.anomaly_count ?? 0 }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- E. Anomaly banner -->
    <VAlert
      v-if="run.anomaly_count > 0 && run.anomalies?.length"
      type="warning"
      variant="tonal"
      class="mb-6"
      closable
    >
      <VAlertTitle>{{ $t('payroll.anomalies.title') }}</VAlertTitle>
      <div
        v-for="anomaly in run.anomalies"
        :key="`${anomaly.employee_id}-${anomaly.type}`"
        class="mt-1"
      >
        <VIcon
          :icon="anomaly.severity === 'error' ? 'tabler-alert-circle' : 'tabler-alert-triangle'"
          :color="anomaly.severity === 'error' ? 'error' : 'warning'"
          size="16"
          class="me-1"
        />
        {{ $t(`payroll.anomalies.types.${anomaly.type}`, anomaly.type) }}
        <span
          v-if="anomaly.employee_name"
          class="text-medium-emphasis"
        >
          &mdash; {{ anomaly.employee_name }}
        </span>
      </div>
    </VAlert>

    <!-- F. Employee lines table -->
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ $t('payroll.lines.title') }}</span>
        <VChip
          size="small"
          color="primary"
          variant="tonal"
        >
          {{ store.currentRunTotalLines }} {{ $t('payroll.employees') }}
        </VChip>
      </VCardTitle>

      <VDataTableServer
        :headers="lineHeaders"
        :items="store.currentRunLines"
        :items-length="store.currentRunTotalLines"
        :loading="store.loading"
        :items-per-page="15"
        @update:options="onLineOptionsUpdate"
      >
        <!-- Employee column -->
        <template #item.employee="{ item }">
          <div>
            <div class="font-weight-medium">
              {{ item.employee_name }}
            </div>
            <div
              v-if="item.employee_number"
              class="text-body-2 text-medium-emphasis"
            >
              {{ item.employee_number }}
            </div>
          </div>
        </template>

        <!-- Worked hours -->
        <template #item.worked_minutes="{ item }">
          {{ formatHours(item.worked_minutes) }}
        </template>

        <!-- Overtime -->
        <template #item.total_overtime_minutes="{ item }">
          <span :class="item.total_overtime_minutes > 0 ? 'text-warning font-weight-medium' : ''">
            {{ formatHours(item.total_overtime_minutes) }}
          </span>
        </template>

        <!-- Gross basis -->
        <template #item.gross_basis_cents="{ item }">
          {{ formatCents(item.gross_basis_cents) }}
        </template>

        <!-- Net payable (from calculation) -->
        <template #item.net_payable="{ item }">
          {{ formatCents(item.net_payable_cents) }}
        </template>

        <!-- Employer cost (from calculation) -->
        <template #item.employer_cost="{ item }">
          {{ formatCents(item.employer_cost_cents) }}
        </template>

        <!-- Anomalies -->
        <template #item.anomalies="{ item }">
          <VIcon
            v-if="item.has_anomalies"
            icon="tabler-alert-triangle"
            color="warning"
            size="20"
          />
          <span
            v-else
            class="text-medium-emphasis"
          >&mdash;</span>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- G. Validate dialog -->
    <VDialog
      v-model="showValidateDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle>{{ $t('payroll.dialogs.validate.title') }}</VCardTitle>
        <VCardText>
          <p class="mb-4">
            {{ $t('payroll.dialogs.validate.message') }}
          </p>
          <AppTextarea
            v-model="validationNote"
            :label="$t('payroll.dialogs.validate.noteLabel')"
            :placeholder="$t('payroll.dialogs.validate.notePlaceholder')"
            rows="3"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="showValidateDialog = false"
          >
            {{ $t('payroll.dialogs.cancel') }}
          </VBtn>
          <VBtn
            color="success"
            :loading="store.loading"
            @click="handleValidate"
          >
            {{ $t('payroll.actions.validate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- H. Delete confirmation dialog -->
    <VDialog
      v-model="showDeleteDialog"
      max-width="400"
    >
      <VCard>
        <VCardTitle>{{ $t('payroll.dialogs.delete.title') }}</VCardTitle>
        <VCardText>
          <p>{{ $t('payroll.dialogs.delete.message') }}</p>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="showDeleteDialog = false"
          >
            {{ $t('payroll.dialogs.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="store.loading"
            @click="handleDelete"
          >
            {{ $t('payroll.actions.delete') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>

  <!-- Loading state -->
  <div
    v-else-if="store.loading"
    class="d-flex justify-center align-center py-16"
  >
    <VProgressCircular
      indeterminate
      size="48"
      color="primary"
    />
  </div>
</template>
