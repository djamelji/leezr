<script setup>
import { usePlatformAlertStore } from '@/modules/platform-admin/alerts/alerts.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatDateTime } from '@/utils/datetime'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveKey: 'platform-alerts',
    module: 'platform.alerts',
  },
})

const { t } = useI18n()
const router = useRouter()
const { toast } = useAppToast()
const store = usePlatformAlertStore()

// ── Filters ──────────────────────────────────────────
const sourceFilter = ref(null)
const severityFilter = ref(null)
const statusFilter = ref('active')
const search = ref('')
const page = ref(1)

const sourceOptions = [
  { title: t('alerts.billing'), value: 'billing' },
  { title: t('alerts.support'), value: 'support' },
  { title: t('alerts.ai'), value: 'ai' },
  { title: t('alerts.infra'), value: 'infra' },
  { title: t('alerts.security'), value: 'security' },
  { title: t('alerts.business'), value: 'business' },
]

const severityOptions = [
  { title: t('alerts.critical'), value: 'critical' },
  { title: t('alerts.warning'), value: 'warning' },
  { title: t('alerts.info'), value: 'info' },
]

const statusOptions = [
  { title: t('alerts.active'), value: 'active' },
  { title: t('alerts.acknowledged'), value: 'acknowledged' },
  { title: t('alerts.resolved'), value: 'resolved' },
  { title: t('alerts.dismissed'), value: 'dismissed' },
]

// ── Table ────────────────────────────────────────────
const headers = computed(() => [
  { title: t('alerts.severity'), key: 'severity', width: 120 },
  { title: t('alerts.source'), key: 'source', width: 130 },
  { title: t('alerts.alertTitle'), key: 'title' },
  { title: t('alerts.company'), key: 'company', width: 160, sortable: false },
  { title: t('alerts.createdAt'), key: 'created_at', width: 170 },
  { title: t('alerts.status'), key: 'status', width: 140 },
  { title: t('alerts.actions'), key: 'actions', sortable: false, width: 140 },
])

// ── Colors / Icons ───────────────────────────────────
const severityColors = {
  critical: 'error',
  warning: 'warning',
  info: 'info',
}

const severityIcons = {
  critical: 'tabler-alert-triangle',
  warning: 'tabler-alert-circle',
  info: 'tabler-info-circle',
}

const sourceIcons = {
  billing: 'tabler-receipt',
  support: 'tabler-headset',
  ai: 'tabler-robot',
  infra: 'tabler-server',
  security: 'tabler-shield',
  business: 'tabler-briefcase',
}

const sourceColors = {
  billing: 'primary',
  support: 'info',
  ai: 'secondary',
  infra: 'warning',
  security: 'error',
  business: 'success',
}

const statusColors = {
  active: 'error',
  acknowledged: 'warning',
  resolved: 'success',
  dismissed: 'secondary',
}

// ── Target navigation ────────────────────────────────
const targetRoutes = {
  Invoice: id => ({ name: 'platform-billing-invoices-id', params: { id } }),
  SupportTicket: id => ({ name: 'platform-support-id', params: { id } }),
  Subscription: () => ({ name: 'platform-billing', query: { tab: 'subscriptions' } }),
  Payment: () => ({ name: 'platform-billing', query: { tab: 'payments' } }),
}

const navigateToTarget = alert => {
  if (!alert.target_type || !alert.target_id) return

  const routeFn = targetRoutes[alert.target_type]
  if (routeFn) {
    router.push(routeFn(alert.target_id))
  }
}

const hasTarget = alert => {
  return alert.target_type && alert.target_id && targetRoutes[alert.target_type]
}

// ── Actions ──────────────────────────────────────────
const actionLoading = ref(null)

const acknowledgeAlert = async alert => {
  actionLoading.value = `ack-${alert.id}`
  try {
    await store.acknowledge(alert.id)
    toast(t('alerts.acknowledgedSuccess'), 'success')
  }
  catch {
    toast(t('alerts.actionFailed'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const resolveAlert = async alert => {
  actionLoading.value = `resolve-${alert.id}`
  try {
    await store.resolve(alert.id)
    toast(t('alerts.resolvedSuccess'), 'success')
  }
  catch {
    toast(t('alerts.actionFailed'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const dismissAlert = async alert => {
  actionLoading.value = `dismiss-${alert.id}`
  try {
    await store.dismiss(alert.id)
    toast(t('alerts.dismissedSuccess'), 'success')
  }
  catch {
    toast(t('alerts.actionFailed'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

// ── Load ─────────────────────────────────────────────
const loadAlerts = () => {
  const params = { page: page.value }

  if (sourceFilter.value) params.source = sourceFilter.value
  if (severityFilter.value) params.severity = severityFilter.value
  if (statusFilter.value) params.status = statusFilter.value

  store.fetchAlerts(params)
}

watch([sourceFilter, severityFilter, statusFilter, search], () => {
  page.value = 1
  loadAlerts()
})

watch(page, () => {
  loadAlerts()
})

onMounted(() => {
  loadAlerts()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('alerts.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('alerts.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        prepend-icon="tabler-refresh"
        :loading="store.loading"
        @click="loadAlerts()"
      >
        {{ t('alerts.refresh') }}
      </VBtn>
    </div>

    <!-- KPI Cards -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-alert-triangle"
              size="28"
              class="mb-1 text-error"
            />
            <div class="text-h4 font-weight-bold text-error">
              {{ store.kpis.active_critical }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('alerts.activeCritical') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-bell-ringing"
              size="28"
              class="mb-1 text-warning"
            />
            <div class="text-h4 font-weight-bold text-warning">
              {{ store.kpis.active_total }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('alerts.activeTotal') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              icon="tabler-check"
              size="28"
              class="mb-1 text-success"
            />
            <div class="text-h4 font-weight-bold text-success">
              {{ store.kpis.resolved_24h }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('alerts.resolved24h') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Table Card -->
    <VCard>
      <VCardTitle class="d-flex align-center pa-5">
        <VIcon
          icon="tabler-bell"
          class="me-2"
        />
        {{ t('alerts.title') }}
      </VCardTitle>

      <VDivider />

      <!-- Filters -->
      <VCardText>
        <VRow class="mb-4">
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="sourceFilter"
              :items="sourceOptions"
              :placeholder="t('alerts.allSources')"
              clearable
              @click:clear="sourceFilter = null"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="severityFilter"
              :items="severityOptions"
              :placeholder="t('alerts.allSeverities')"
              clearable
              @click:clear="severityFilter = null"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="t('alerts.allStatuses')"
              clearable
              @click:clear="statusFilter = null"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <div class="text-body-2 text-medium-emphasis d-flex align-center h-100">
              {{ store.pagination.total }} {{ t('alerts.alertsCount') }}
            </div>
          </VCol>
        </VRow>

        <!-- Data Table -->
        <VDataTable
          :headers="headers"
          :items="store.alerts"
          :loading="store.loading"
          item-value="id"
          class="text-no-wrap"
        >
          <!-- Severity -->
          <template #item.severity="{ item }">
            <VChip
              :color="severityColors[item.severity]"
              size="small"
              :prepend-icon="severityIcons[item.severity]"
            >
              {{ t(`alerts.${item.severity}`) }}
            </VChip>
          </template>

          <!-- Source -->
          <template #item.source="{ item }">
            <VChip
              :color="sourceColors[item.source]"
              size="small"
              variant="tonal"
              :prepend-icon="sourceIcons[item.source]"
            >
              {{ t(`alerts.${item.source}`) }}
            </VChip>
          </template>

          <!-- Title -->
          <template #item.title="{ item }">
            <div
              class="d-flex flex-column"
              style="max-width: 340px"
            >
              <span
                class="font-weight-medium text-truncate"
                :class="{ 'cursor-pointer text-primary': hasTarget(item) }"
                @click="navigateToTarget(item)"
              >
                {{ item.title }}
              </span>
              <span
                v-if="item.description"
                class="text-body-2 text-medium-emphasis text-truncate"
              >
                {{ item.description }}
              </span>
            </div>
          </template>

          <!-- Company -->
          <template #item.company="{ item }">
            <RouterLink
              v-if="item.company"
              :to="{ name: 'platform-companies-id', params: { id: item.company_id } }"
              class="text-decoration-none font-weight-medium"
            >
              {{ item.company.name }}
            </RouterLink>
            <span
              v-else
              class="text-medium-emphasis"
            >
              --
            </span>
          </template>

          <!-- Created At -->
          <template #item.created_at="{ item }">
            <span class="text-body-2">
              {{ formatDateTime(item.created_at) }}
            </span>
          </template>

          <!-- Status -->
          <template #item.status="{ item }">
            <VChip
              :color="statusColors[item.status]"
              size="small"
              variant="tonal"
            >
              {{ t(`alerts.${item.status}`) }}
            </VChip>
          </template>

          <!-- Actions -->
          <template #item.actions="{ item }">
            <div class="d-flex gap-1">
              <!-- Acknowledge -->
              <VBtn
                v-if="item.status === 'active'"
                icon
                size="small"
                variant="text"
                color="warning"
                :loading="actionLoading === `ack-${item.id}`"
                :disabled="!!actionLoading"
                @click.stop="acknowledgeAlert(item)"
              >
                <VIcon icon="tabler-eye" />
                <VTooltip activator="parent">
                  {{ t('alerts.acknowledge') }}
                </VTooltip>
              </VBtn>

              <!-- Resolve -->
              <VBtn
                v-if="item.status === 'active' || item.status === 'acknowledged'"
                icon
                size="small"
                variant="text"
                color="success"
                :loading="actionLoading === `resolve-${item.id}`"
                :disabled="!!actionLoading"
                @click.stop="resolveAlert(item)"
              >
                <VIcon icon="tabler-check" />
                <VTooltip activator="parent">
                  {{ t('alerts.resolve') }}
                </VTooltip>
              </VBtn>

              <!-- Dismiss -->
              <VBtn
                v-if="item.status === 'active'"
                icon
                size="small"
                variant="text"
                color="secondary"
                :loading="actionLoading === `dismiss-${item.id}`"
                :disabled="!!actionLoading"
                @click.stop="dismissAlert(item)"
              >
                <VIcon icon="tabler-x" />
                <VTooltip activator="parent">
                  {{ t('alerts.dismiss') }}
                </VTooltip>
              </VBtn>
            </div>
          </template>

          <!-- Empty state -->
          <template #no-data>
            <div class="text-center pa-8">
              <VIcon
                icon="tabler-bell-off"
                size="48"
                class="mb-4 text-medium-emphasis"
              />
              <p class="text-body-1 font-weight-medium">
                {{ t('alerts.noAlerts') }}
              </p>
              <p class="text-body-2 text-medium-emphasis">
                {{ t('alerts.noAlertsDescription') }}
              </p>
            </div>
          </template>
        </VDataTable>

        <!-- Pagination -->
        <div
          v-if="store.pagination.last_page > 1"
          class="d-flex justify-center mt-4"
        >
          <VPagination
            v-model="page"
            :length="store.pagination.last_page"
            :total-visible="5"
            density="compact"
          />
        </div>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
/* Critical row subtle highlight */
:deep(.v-data-table__tr:has(.v-chip--variant-elevated[class*="bg-error"])) {
  background-color: rgba(var(--v-theme-error), 0.04);
}
</style>
