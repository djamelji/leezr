<script setup>
// PRODUCT-UX-2 / LOT C2 (ADR-680) — « Préparation collaborateur » panel.
// Structured, honest view of ONE employee's payroll preparation for a period:
// identity/situation, work time (+ lock/source), absences, compensation (gated by
// compensation_read), anomalies, data provenance, and contextual actions that lead
// to the SOURCE (timesheet, contract, leave, fiche) while preserving the period.
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  detail: { type: Object, default: null },
  run: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  canReadComp: { type: Boolean, default: false },
  canRecompute: { type: Boolean, default: false },
})
const emit = defineEmits(['update:modelValue', 'go-timesheet', 'go-contract', 'go-fiche', 'go-leave', 'recompute', 'refresh'])

const { t, locale } = useI18n()

const fmtCents = c => (c == null ? '—' : new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(c / 100))
const fmtHours = m => (m == null ? '—' : `${Math.floor(m / 60)}h${String(m % 60).padStart(2, '0')}`)
const fmtDays = h => (h == null ? '—' : `${(h / 100).toFixed(2)} j`)

const emp = computed(() => props.detail?.employee)
const empName = computed(() => (emp.value ? `${emp.value.first_name} ${emp.value.last_name}` : '—'))
const calc = computed(() => props.detail?.calculation)
const anomalies = computed(() => props.detail?.anomalies ?? [])
const hasErrorAnomaly = computed(() => anomalies.value.some(a => a.severity === 'error'))
// The server strips confidential amounts and flags compensation_hidden when the
// viewer lacks compensation_read — never present "0", present "masked".
const compHidden = computed(() => props.detail?.compensation_hidden === true || !props.canReadComp)

const period = computed(() => {
  const r = props.detail?.payroll_run ?? props.run
  if (!r) return ''
  const o = { day: '2-digit', month: 'short', year: 'numeric' }
  return `${new Date(r.period_start).toLocaleDateString(locale.value, o)} — ${new Date(r.period_end).toLocaleDateString(locale.value, o)}`
})

// Provenance rows — honest description of where each prepared element comes from.
const provenance = computed(() => [
  { key: 'time', source: t('payroll.prov.timesheet'), scope: period.value, state: t('payroll.prov.lockedRequired') },
  { key: 'leave', source: t('payroll.prov.leaveFromTimesheet'), scope: period.value, state: t('payroll.prov.derived') },
  { key: 'comp', source: t('payroll.prov.contractPlan'), scope: period.value, state: t('payroll.prov.snapshotAtCompute') },
])

const close = () => emit('update:modelValue', false)
</script>

<template>
  <VNavigationDrawer class="wf-scroll-drawer"
    :model-value="modelValue"
    temporary
    location="end"
    width="460"
    @update:model-value="v => emit('update:modelValue', v)"
  >
    <div class="d-flex align-center pa-4">
      <div class="flex-grow-1">
        <h5 class="text-h6 font-weight-bold mb-0">{{ $t('payroll.prep.panelTitle') }}</h5>
        <div class="text-body-2 text-medium-emphasis">{{ period }}</div>
      </div>
      <VBtn icon variant="text" size="small" @click="close"><VIcon icon="tabler-x" /></VBtn>
    </div>
    <VDivider />

    <div v-if="loading" class="d-flex justify-center py-10">
      <VProgressCircular indeterminate color="primary" />
    </div>

    <div v-else-if="detail" class="pa-4">
      <!-- Identity & situation -->
      <div class="d-flex align-center gap-3 mb-4">
        <VAvatar color="primary" variant="tonal" size="42"><VIcon icon="tabler-user" /></VAvatar>
        <div>
          <div class="font-weight-bold">{{ empName }}</div>
          <div v-if="emp?.employee_number" class="text-body-2 text-medium-emphasis">{{ emp.employee_number }}</div>
        </div>
      </div>

      <!-- Anomalies (readiness) -->
      <VAlert
        v-if="anomalies.length"
        :type="hasErrorAnomaly ? 'error' : 'warning'"
        variant="tonal"
        density="comfortable"
        class="mb-4"
      >
        <div class="font-weight-bold mb-1">{{ $t('payroll.prep.anomaliesTitle') }}</div>
        <div v-for="(a, i) in anomalies" :key="i" class="text-body-2">
          <VIcon :icon="a.severity === 'error' ? 'tabler-alert-circle' : 'tabler-alert-triangle'" size="14" class="me-1" />
          {{ $t(`payroll.anomalies.types.${a.type}`, a.type) }}
        </div>
      </VAlert>
      <VAlert v-else type="success" variant="tonal" density="compact" class="mb-4">
        <VIcon icon="tabler-check" size="16" class="me-1" />{{ $t('payroll.prep.noAnomaly') }}
      </VAlert>

      <!-- Work time -->
      <div class="text-overline text-medium-emphasis">{{ $t('payroll.prep.sectionTime') }}</div>
      <VRow class="mb-1">
        <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.worked') }}</div><div class="font-weight-medium">{{ fmtHours(detail.worked_minutes) }}</div></VCol>
        <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.overtime') }}</div><div class="font-weight-medium">{{ fmtHours(detail.total_overtime_minutes) }}</div></VCol>
        <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.planned') }}</div><div class="font-weight-medium">{{ fmtHours(detail.planned_minutes) }}</div></VCol>
        <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.time.breaks') }}</div><div class="font-weight-medium">{{ fmtHours(detail.break_minutes) }}</div></VCol>
      </VRow>

      <!-- Absences -->
      <VDivider class="my-3" />
      <div class="text-overline text-medium-emphasis">{{ $t('payroll.prep.sectionLeave') }}</div>
      <VRow class="mb-1">
        <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.leave.paid') }}</div><div class="font-weight-medium">{{ fmtDays(detail.paid_leave_days_hundredths) }}</div></VCol>
        <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.leave.unpaid') }}</div><div class="font-weight-medium">{{ fmtDays(detail.unpaid_leave_days_hundredths) }}</div></VCol>
      </VRow>

      <!-- Compensation (gated) -->
      <VDivider class="my-3" />
      <div class="text-overline text-medium-emphasis">{{ $t('payroll.prep.sectionComp') }}</div>
      <VAlert v-if="compHidden" type="info" variant="tonal" density="compact" class="mb-2">
        <VIcon icon="tabler-lock" size="16" class="me-1" />{{ $t('payroll.prep.compHidden') }}
      </VAlert>
      <template v-else>
        <VRow class="mb-1">
          <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.compensation.baseSalary') }}</div><div class="font-weight-medium">{{ fmtCents(detail.base_salary_cents) }}</div></VCol>
          <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.compensation.grossBasis') }}</div><div class="font-weight-medium">{{ fmtCents(detail.gross_basis_cents) }}</div></VCol>
        </VRow>
        <template v-if="calc">
          <VRow class="mb-1">
            <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.netPayable') }}</div><div class="font-weight-medium text-success">{{ fmtCents(calc.net_payable_cents) }}</div></VCol>
            <VCol cols="6"><div class="text-body-2 text-medium-emphasis">{{ $t('payroll.lineDetail.calculation.totalEmployerCost') }}</div><div class="font-weight-medium text-warning">{{ fmtCents(calc.total_cost_employer_cents) }}</div></VCol>
          </VRow>
          <div class="text-caption text-medium-emphasis mt-1">
            <VIcon icon="tabler-info-circle" size="13" class="me-1" />{{ $t('payroll.prep.estimateNote') }}
          </div>
        </template>
      </template>

      <!-- Variables (honestly absent) -->
      <VDivider class="my-3" />
      <div class="text-overline text-medium-emphasis">{{ $t('payroll.prep.sectionVariables') }}</div>
      <div class="text-body-2 text-medium-emphasis">
        <VIcon icon="tabler-circle-dashed" size="14" class="me-1" />{{ $t('payroll.prep.variablesAbsent') }}
      </div>

      <!-- Provenance -->
      <VDivider class="my-3" />
      <div class="text-overline text-medium-emphasis">{{ $t('payroll.prep.sectionProvenance') }}</div>
      <VTable density="compact" class="text-body-2">
        <tbody>
          <tr v-for="p in provenance" :key="p.key">
            <td class="ps-0 font-weight-medium">{{ p.source }}</td>
            <td class="text-medium-emphasis">{{ p.state }}</td>
          </tr>
        </tbody>
      </VTable>

      <!-- Contextual actions -->
      <VDivider class="my-3" />
      <div class="text-overline text-medium-emphasis mb-2">{{ $t('payroll.prep.sectionActions') }}</div>
      <div class="d-flex flex-column gap-2">
        <VBtn
          :color="hasErrorAnomaly ? 'error' : 'primary'"
          variant="tonal"
          prepend-icon="tabler-clock-edit"
          @click="emit('go-timesheet')"
        >
          {{ hasErrorAnomaly ? $t('payroll.prep.actFixSource') : $t('payroll.prep.actSeeTimesheet') }}
        </VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-file-text" @click="emit('go-contract')">{{ $t('payroll.prep.actContract') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-calendar-off" @click="emit('go-leave')">{{ $t('payroll.prep.actLeave') }}</VBtn>
        <VBtn variant="outlined" prepend-icon="tabler-user-circle" @click="emit('go-fiche')">{{ $t('payroll.prep.actFiche') }}</VBtn>
        <VBtn
          v-if="canRecompute"
          color="warning"
          variant="text"
          prepend-icon="tabler-refresh"
          @click="emit('recompute')"
        >
          {{ $t('payroll.prep.actRecompute') }}
        </VBtn>
      </div>
    </div>
  </VNavigationDrawer>
</template>
