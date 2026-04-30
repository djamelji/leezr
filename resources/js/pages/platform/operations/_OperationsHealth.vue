<script setup>
/**
 * P2-1: System Health Page — aggregated infrastructure health dashboard.
 * Backend: SystemHealthController::index() → GET /platform/system/health
 */
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()

const isLoading = ref(true)
const healthData = ref(null)
const error = ref(null)

const sectionIcons = {
  email: 'tabler-mail',
  ai: 'tabler-brain',
  queues: 'tabler-stack-2',
  scheduler: 'tabler-clock-play',
  database: 'tabler-database',
  disk: 'tabler-server',
  alerts: 'tabler-alert-triangle',
}

const statusColor = status => {
  if (status === 'healthy') return 'success'
  if (status === 'warning') return 'warning'

  return 'error'
}

const statusLabel = status => {
  if (status === 'healthy') return t('systemHealth.healthy')
  if (status === 'warning') return t('systemHealth.warning')

  return t('systemHealth.critical')
}

const overallMessage = computed(() => {
  if (!healthData.value) return ''
  const s = healthData.value.status
  if (s === 'healthy') return t('systemHealth.overallHealthy')
  if (s === 'warning') return t('systemHealth.overallWarning')

  return t('systemHealth.overallCritical')
})

const detailKeys = {
  email: [
    { key: 'sent_24h', label: 'systemHealth.emailSent' },
    { key: 'failed_24h', label: 'systemHealth.emailFailed' },
    { key: 'fail_rate', label: 'systemHealth.emailFailRate', suffix: '%' },
  ],
  ai: [
    { key: 'provider', label: 'systemHealth.aiProvider' },
    { key: 'provider_status', label: 'systemHealth.aiProviderStatus' },
    { key: 'queue_pending', label: 'systemHealth.aiQueuePending' },
    { key: 'queue_failed_24h', label: 'systemHealth.aiQueueFailed' },
  ],
  queues: [
    { key: 'default_pending', label: 'systemHealth.queueDefaultPending' },
    { key: 'ai_pending', label: 'systemHealth.queueAiPending' },
    { key: 'default_failed_24h', label: 'systemHealth.queueDefaultFailed' },
    { key: 'ai_failed_24h', label: 'systemHealth.queueAiFailed' },
  ],
  scheduler: [
    { key: 'runs_2h', label: 'systemHealth.schedulerRuns' },
    { key: 'failed_2h', label: 'systemHealth.schedulerFailed' },
    { key: 'last_run_at', label: 'systemHealth.schedulerLastRun' },
  ],
  database: [
    { key: 'latency_ms', label: 'systemHealth.dbLatency', suffix: 'ms' },
    { key: 'connection', label: 'systemHealth.dbConnection' },
  ],
  disk: [
    { key: 'used_percent', label: 'systemHealth.diskUsed', suffix: '%' },
    { key: 'free_gb', label: 'systemHealth.diskFree', suffix: ' GB' },
  ],
  alerts: [
    { key: 'active_critical', label: 'systemHealth.alertsCritical' },
    { key: 'active_total', label: 'systemHealth.alertsTotal' },
  ],
}

const formatDetailValue = (val, suffix) => {
  if (val === null || val === undefined) return t('systemHealth.never')

  return `${val}${suffix || ''}`
}

const load = async () => {
  isLoading.value = true
  error.value = null
  try {
    healthData.value = await $platformApi('/system/health')
  }
  catch {
    error.value = t('common.loadError')
  }
  finally {
    isLoading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('systemHealth.title') }}
        </h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('systemHealth.subtitle') }}
        </p>
      </div>
      <VBtn
        variant="tonal"
        size="small"
        :loading="isLoading"
        @click="load"
      >
        <VIcon
          start
          icon="tabler-refresh"
          size="18"
        />
        {{ t('systemHealth.refresh') }}
      </VBtn>
    </div>

    <!-- Overall status banner -->
    <VAlert
      v-if="healthData"
      :type="healthData.status === 'healthy' ? 'success' : healthData.status === 'warning' ? 'warning' : 'error'"
      variant="tonal"
      class="mb-6"
    >
      <div class="d-flex align-center gap-2">
        <VChip
          :color="statusColor(healthData.status)"
          size="small"
        >
          {{ statusLabel(healthData.status) }}
        </VChip>
        <span>{{ overallMessage }}</span>
        <VSpacer />
        <span class="text-body-2 text-disabled">
          {{ t('systemHealth.lastCheck') }}: {{ healthData.checked_at ? new Date(healthData.checked_at).toLocaleTimeString() : '—' }}
        </span>
      </div>
    </VAlert>

    <!-- Error state -->
    <VAlert
      v-if="error"
      type="error"
      variant="tonal"
      class="mb-6"
    >
      {{ error }}
    </VAlert>

    <!-- Loading -->
    <VSkeletonLoader
      v-if="isLoading && !healthData"
      type="card, card, card"
    />

    <!-- Section cards -->
    <VRow
      v-if="healthData"
      class="card-grid card-grid-sm"
    >
      <VCol
        v-for="section in healthData.sections"
        :key="section.key"
        cols="12"
        md="4"
        lg="3"
      >
        <VCard>
          <VCardText>
            <div class="d-flex align-center gap-3 mb-3">
              <VAvatar
                :color="statusColor(section.status)"
                variant="tonal"
                size="40"
              >
                <VIcon
                  :icon="sectionIcons[section.key] || 'tabler-circle'"
                  size="22"
                />
              </VAvatar>
              <div>
                <div class="text-body-1 font-weight-medium">
                  {{ t(`systemHealth.${section.key}`) }}
                </div>
                <VChip
                  :color="statusColor(section.status)"
                  size="x-small"
                  variant="tonal"
                >
                  {{ statusLabel(section.status) }}
                </VChip>
              </div>
            </div>

            <!-- Details -->
            <VList
              v-if="section.details && !section.details.error"
              density="compact"
              class="pa-0"
            >
              <VListItem
                v-for="detail in (detailKeys[section.key] || [])"
                :key="detail.key"
                density="compact"
                class="px-0"
              >
                <template #prepend>
                  <span class="text-body-2 text-medium-emphasis">{{ t(detail.label) }}</span>
                </template>
                <template #append>
                  <span class="text-body-2 font-weight-medium">
                    {{ formatDetailValue(section.details[detail.key], detail.suffix) }}
                  </span>
                </template>
              </VListItem>
            </VList>

            <!-- Error in section -->
            <div
              v-else-if="section.details?.error"
              class="text-body-2 text-error"
            >
              {{ t('systemHealth.error') }}: {{ section.details.error }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
