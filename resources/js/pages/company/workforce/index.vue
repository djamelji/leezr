<script setup>
import { useWorkforceDashboardStore } from '@/modules/company/workforce/workforce-dashboard.store'
import { useAppToast } from '@/composables/useAppToast'
import WorkforceModuleCard from './_WorkforceModuleCard.vue'
import WorkforceActionCard from './_WorkforceActionCard.vue'
import ComplianceWarningBanner from './_ComplianceWarningBanner.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t } = useI18n()
const router = useRouter()
const dashboardStore = useWorkforceDashboardStore()
const { toast } = useAppToast()

// ── Load dashboard statistics ────────────────────────────────
const loadDashboard = async () => {
  try {
    await dashboardStore.fetchStatistics()
  } catch {
    toast(t('workforceDashboard.loadError'), 'error')
  }
}

onMounted(() => {
  loadDashboard()
})

const loading = computed(() => dashboardStore.loading)
const badges = computed(() => dashboardStore.badges)
const employees = computed(() => dashboardStore.employees)
const timesheets = computed(() => dashboardStore.timesheets)
const payroll = computed(() => dashboardStore.payroll)
const leave = computed(() => dashboardStore.leave)

// ── KPI cards ────────────────────────────────────────────────
const kpiCards = computed(() => [
  {
    key: 'employees',
    title: 'workforceDashboard.kpi.activeEmployees',
    value: employees.value.active,
    total: employees.value.total,
    icon: 'tabler-users-group',
    color: 'primary',
    subtitle: 'workforceDashboard.kpi.totalEmployees',
  },
  {
    key: 'contracts',
    title: 'workforceDashboard.kpi.activeContracts',
    value: employees.value.active_contracts,
    icon: 'tabler-file-check',
    color: 'info',
  },
  {
    key: 'timesheetCompletion',
    title: 'workforceDashboard.kpi.timesheetCompletion',
    value: `${Math.round(badges.value.timesheet_completion || 0)}%`,
    icon: 'tabler-clipboard-check',
    color: timesheets.value.completion_rate >= 80 ? 'success' : timesheets.value.completion_rate >= 50 ? 'warning' : 'error',
  },
  {
    key: 'pendingActions',
    title: 'workforceDashboard.kpi.pendingActions',
    value: (badges.value.pending_leaves || 0) + (badges.value.draft_payrolls || 0) + (badges.value.pending_dsn || 0),
    icon: 'tabler-alert-circle',
    color: 'warning',
  },
])

// ── Pending items breakdown ──────────────────────────────────
const pendingItems = computed(() => {
  const items = []

  if (badges.value.pending_leaves > 0) {
    items.push({
      key: 'leaves',
      label: 'workforceDashboard.pending.leaves',
      count: badges.value.pending_leaves,
      icon: 'tabler-beach',
      color: 'success',
      to: { name: 'company-workforce-leave' },
    })
  }

  if (badges.value.draft_payrolls > 0) {
    items.push({
      key: 'payrolls',
      label: 'workforceDashboard.pending.payrolls',
      count: badges.value.draft_payrolls,
      icon: 'tabler-receipt',
      color: 'error',
      to: { name: 'company-workforce-payroll' },
    })
  }

  if (badges.value.pending_dsn > 0) {
    items.push({
      key: 'dsn',
      label: 'workforceDashboard.pending.dsn',
      count: badges.value.pending_dsn,
      icon: 'tabler-send',
      color: 'warning',
      to: { name: 'company-workforce-dsn' },
    })
  }

  return items
})

// ── Module cards with live stats ─────────────────────────────
const modules = computed(() => {
  const payrollByStatus = payroll.value.by_status ?? {}
  const leaveData = leave.value ?? {}

  const pendingLeaveCount = leaveData.pending?.count ?? 0
  const approvedLeaveCount = leaveData.approved?.count ?? 0

  return [
    {
      key: 'employees',
      title: 'workforce.modules.employees.title',
      description: 'workforce.modules.employees.description',
      icon: 'tabler-users-group',
      color: 'primary',
      to: { name: 'company-workforce-employees' },
      permission: 'workforce.view',
      stats: [
        { value: employees.value.active, label: 'workforceDashboard.stats.active', color: 'success' },
        { value: employees.value.total, label: 'workforceDashboard.stats.total', color: 'primary' },
      ],
    },
    {
      key: 'time',
      title: 'workforce.modules.time.title',
      description: 'workforce.modules.time.description',
      icon: 'tabler-clock',
      color: 'info',
      to: { name: 'company-workforce-time' },
      permission: 'workforce.view',
      stats: [
        { value: timesheets.value.total ?? 0, label: 'workforceDashboard.stats.timesheets', color: 'info' },
        { value: `${Math.round(timesheets.value.completion_rate ?? 0)}%`, label: 'workforceDashboard.stats.completion', color: 'success' },
      ],
    },
    {
      key: 'planning',
      title: 'workforce.modules.planning.title',
      description: 'workforce.modules.planning.description',
      icon: 'tabler-calendar-event',
      color: 'warning',
      to: { name: 'company-workforce-planning' },
      permission: 'workforce.planning_view',
      stats: [],
    },
    {
      key: 'leave',
      title: 'workforce.modules.leave.title',
      description: 'workforce.modules.leave.description',
      icon: 'tabler-beach',
      color: 'success',
      to: { name: 'company-workforce-leave' },
      permission: 'workforce.leave_request',
      stats: [
        { value: pendingLeaveCount, label: 'workforceDashboard.stats.pending', color: 'warning' },
        { value: approvedLeaveCount, label: 'workforceDashboard.stats.approved', color: 'success' },
      ],
    },
    {
      key: 'payroll',
      title: 'workforce.modules.payroll.title',
      description: 'workforce.modules.payroll.description',
      icon: 'tabler-receipt',
      color: 'error',
      to: { name: 'company-workforce-payroll' },
      permission: 'workforce.payroll_prepare',
      stats: [
        { value: payrollByStatus.draft?.count ?? 0, label: 'workforceDashboard.stats.draft', color: 'secondary' },
        { value: payrollByStatus.validated?.count ?? 0, label: 'workforceDashboard.stats.validated', color: 'success' },
      ],
    },
    {
      key: 'documents',
      title: 'workforce.modules.documents.title',
      description: 'workforce.modules.documents.description',
      icon: 'tabler-file-certificate',
      color: 'secondary',
      to: { name: 'company-workforce-documents' },
      permission: 'workforce_documents.view',
      stats: [],
    },
  ]
})

// ── Quick actions ────────────────────────────────────────────
const quickActions = computed(() => [
  {
    key: 'add-employee',
    title: 'workforce.actions.addEmployee.title',
    description: 'workforce.actions.addEmployee.description',
    icon: 'tabler-user-plus',
    color: 'primary',
    actionLabel: 'workforce.actions.addEmployee.action',
    to: { name: 'company-workforce-employees' },
  },
  {
    key: 'create-payroll',
    title: 'workforce.actions.createPayroll.title',
    description: 'workforce.actions.createPayroll.description',
    icon: 'tabler-calculator',
    color: 'error',
    actionLabel: 'workforce.actions.createPayroll.action',
    to: { name: 'company-workforce-payroll' },
  },
  {
    key: 'view-timesheets',
    title: 'workforce.actions.viewTimesheets.title',
    description: 'workforce.actions.viewTimesheets.description',
    icon: 'tabler-clipboard-check',
    color: 'info',
    actionLabel: 'workforce.actions.viewTimesheets.action',
    to: { name: 'company-workforce-time' },
  },
])

// ── Compliance warnings (fed by API) ─────────────────────────
const complianceWarnings = ref([])
</script>

<template>
  <div>
    <!-- Page Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('workforceDashboard.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('workforceDashboard.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        color="primary"
        prepend-icon="tabler-refresh"
        :loading="loading"
        @click="loadDashboard"
      >
        {{ $t('workforceDashboard.refresh') }}
      </VBtn>
    </div>

    <!-- Compliance Warnings -->
    <ComplianceWarningBanner
      v-if="complianceWarnings.length"
      :warnings="complianceWarnings"
      class="mb-6"
    />

    <!-- KPI Cards -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        v-for="kpi in kpiCards"
        :key="kpi.key"
        cols="6"
        sm="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              :color="kpi.color"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                :icon="kpi.icon"
                size="26"
              />
            </VAvatar>
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t(kpi.title) }}
              </div>
              <div class="d-flex align-center gap-2">
                <span
                  class="text-h5 font-weight-bold"
                  :class="`text-${kpi.color}`"
                >
                  <VSkeletonLoader
                    v-if="loading && !kpi.value"
                    type="text"
                    width="40"
                  />
                  <template v-else>
                    {{ kpi.value }}
                  </template>
                </span>
                <span
                  v-if="kpi.total !== undefined"
                  class="text-body-2 text-medium-emphasis"
                >
                  / {{ kpi.total }}
                </span>
              </div>
              <div
                v-if="kpi.subtitle"
                class="text-caption text-disabled"
              >
                {{ $t(kpi.subtitle) }}
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Pending Actions Banner -->
    <VAlert
      v-if="pendingItems.length"
      type="warning"
      variant="tonal"
      class="mb-6"
    >
      <template #text>
        <div class="d-flex align-center flex-wrap gap-4">
          <span class="text-body-1 font-weight-medium">
            {{ $t('workforceDashboard.pendingActionsTitle') }}
          </span>
          <div class="d-flex flex-wrap gap-2">
            <VChip
              v-for="item in pendingItems"
              :key="item.key"
              :color="item.color"
              :prepend-icon="item.icon"
              variant="tonal"
              class="cursor-pointer"
              @click="router.push(item.to)"
            >
              {{ item.count }} {{ $t(item.label) }}
            </VChip>
          </div>
        </div>
      </template>
    </VAlert>

    <!-- Module Cards with Live Stats -->
    <h6 class="text-h6 mb-4">
      {{ $t('workforce.hub.modulesTitle') }}
    </h6>

    <VRow class="card-grid card-grid-md mb-6">
      <VCol
        v-for="mod in modules"
        :key="mod.key"
        cols="12"
        sm="6"
        md="4"
      >
        <WorkforceModuleCard
          :title="mod.title"
          :description="mod.description"
          :icon="mod.icon"
          :color="mod.color"
          :to="mod.to"
          :stats="mod.stats"
          :coming-soon="mod.comingSoon"
        />
      </VCol>
    </VRow>

    <!-- Quick Actions -->
    <h6 class="text-h6 mb-4">
      {{ $t('workforce.hub.actionsTitle') }}
    </h6>

    <VRow class="mb-6">
      <VCol
        v-for="action in quickActions"
        :key="action.key"
        cols="12"
        md="4"
      >
        <WorkforceActionCard
          :title="action.title"
          :description="action.description"
          :icon="action.icon"
          :color="action.color"
          :action-label="action.actionLabel"
          :to="action.to"
        />
      </VCol>
    </VRow>
  </div>
</template>
