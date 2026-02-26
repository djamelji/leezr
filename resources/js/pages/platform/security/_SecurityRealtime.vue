<script setup>
import { usePlatformSecurityStore } from '@/modules/platform-admin/security/security.store'

const { t } = useI18n()

const securityStore = usePlatformSecurityStore()
const loading = ref(true)
const killSwitchLoading = ref(false)
const flushLoading = ref(false)
const confirmFlushDialog = ref(false)

async function fetchAll() {
  loading.value = true
  try {
    await Promise.allSettled([
      securityStore.fetchRealtimeStatus(),
      securityStore.fetchRealtimeMetrics(),
      securityStore.fetchRealtimeConnections(),
    ])
  }
  finally {
    loading.value = false
  }
}

async function toggleKillSwitch() {
  killSwitchLoading.value = true
  try {
    await securityStore.toggleKillSwitch()
  }
  finally {
    killSwitchLoading.value = false
  }
}

async function flushData() {
  flushLoading.value = true
  confirmFlushDialog.value = false
  try {
    await securityStore.flushRealtimeData()
  }
  finally {
    flushLoading.value = false
  }
}

const categoryColors = {
  invalidation: 'primary',
  domain: 'info',
  notification: 'warning',
  audit: 'secondary',
  security: 'error',
}

const metricsHeaders = [
  { title: t('realtime.metricTopic'), key: 'topic' },
  { title: t('realtime.metricCategory'), key: 'category', width: '140px' },
  { title: t('realtime.metricCount'), key: 'count', align: 'end', width: '100px' },
]

const connectionHeaders = [
  { title: t('realtime.user'), key: 'user_id' },
  { title: t('realtime.company'), key: 'company_id' },
  { title: t('realtime.ip'), key: 'ip' },
  { title: t('realtime.connectedAt'), key: 'connected_at' },
]

onMounted(fetchAll)
</script>

<template>
  <div>
    <VRow class="mb-6">
      <VCol cols="12">
        <div class="d-flex align-center justify-space-between">
          <div>
            <h5 class="text-h5">
              {{ t('realtime.title') }}
            </h5>
            <p class="text-body-1 mb-0">
              {{ t('realtime.subtitle') }}
            </p>
          </div>
          <div class="d-flex gap-2">
            <VBtn
              color="warning"
              variant="tonal"
              :loading="killSwitchLoading"
              @click="toggleKillSwitch"
            >
              <VIcon
                start
                :icon="securityStore.realtimeStatus?.kill_switch ? 'tabler-player-pause' : 'tabler-player-play'"
                :class="{ 'blink': !securityStore.realtimeStatus?.kill_switch }"
              />
              {{ securityStore.realtimeStatus?.kill_switch ? t('realtime.reactivate') : t('realtime.killSwitch') }}
            </VBtn>
            <VBtn
              color="error"
              variant="tonal"
              :loading="flushLoading"
              @click="confirmFlushDialog = true"
            >
              <VIcon
                start
                icon="tabler-trash"
              />
              {{ t('realtime.flush') }}
            </VBtn>
          </div>
        </div>
      </VCol>
    </VRow>

    <!-- Status Cards -->
    <VRow>
      <VCol
        cols="12"
        md="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="primary"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-broadcast" />
            </VAvatar>
            <div>
              <p class="text-body-2 mb-0">
                {{ t('realtime.driver') }}
              </p>
              <h6 class="text-h6">
                {{ securityStore.realtimeStatus?.driver ?? '—' }}
              </h6>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              :color="securityStore.realtimeStatus?.kill_switch ? 'error' : 'success'"
              variant="tonal"
              rounded
            >
              <VIcon :icon="securityStore.realtimeStatus?.kill_switch ? 'tabler-shield-off' : 'tabler-shield-check'" />
            </VAvatar>
            <div>
              <p class="text-body-2 mb-0">
                {{ t('realtime.status') }}
              </p>
              <h6 class="text-h6">
                {{ securityStore.realtimeStatus?.kill_switch ? t('realtime.stopped') : t('realtime.active') }}
              </h6>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="info"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-plug-connected" />
            </VAvatar>
            <div>
              <p class="text-body-2 mb-0">
                {{ t('realtime.connections') }}
              </p>
              <h6 class="text-h6">
                {{ securityStore.realtimeConnections.global_count }}
              </h6>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="3"
      >
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              color="secondary"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-list-numbers" />
            </VAvatar>
            <div>
              <p class="text-body-2 mb-0">
                {{ t('realtime.topics') }}
              </p>
              <h6 class="text-h6">
                {{ securityStore.realtimeStatus?.topic_count ?? 0 }}
              </h6>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Transport Card -->
    <VRow class="mt-4">
      <VCol cols="12">
        <VCard>
          <VCardText class="d-flex align-center gap-3">
            <VAvatar
              :color="securityStore.realtimeStatus?.transport === 'pubsub' ? 'success' : 'secondary'"
              variant="tonal"
              rounded
            >
              <VIcon icon="tabler-transfer" />
            </VAvatar>
            <div>
              <p class="text-body-2 mb-0">
                {{ t('realtime.transportLabel') }}
              </p>
              <h6 class="text-h6">
                {{ securityStore.realtimeStatus?.transport ?? '—' }}
              </h6>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Event Metrics -->
    <VRow class="mt-4">
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="tabler-chart-bar" />
            {{ t('realtime.eventMetrics') }}
          </VCardTitle>
          <VDataTable
            :headers="metricsHeaders"
            :items="securityStore.metricsItems"
            :loading="loading"
            density="comfortable"
            :items-per-page="-1"
            hide-default-footer
          >
            <template #item.topic="{ item }">
              {{ t(`realtime.topics.${item.topic}`, item.topic) }}
            </template>
            <template #item.category="{ item }">
              <VChip
                size="small"
                variant="tonal"
                :color="categoryColors[item.category] ?? 'default'"
              >
                {{ t(`realtime.categories.${item.category}`, item.category) }}
              </VChip>
            </template>
            <template #item.count="{ item }">
              <span class="text-body-2 font-weight-medium">
                {{ item.count }}
              </span>
            </template>
            <template #no-data>
              <div class="text-center pa-4 text-disabled">
                {{ t('realtime.noMetrics') }}
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="tabler-clock" />
            {{ t('realtime.latency') }}
          </VCardTitle>
          <VCardText>
            <div class="d-flex gap-8">
              <div>
                <p class="text-body-2 text-disabled mb-1">
                  {{ t('realtime.publishLatency') }}
                </p>
                <h6 class="text-h6">
                  {{ securityStore.realtimeMetrics?.latency?.publish?.avg ? `${Math.round(securityStore.realtimeMetrics.latency.publish.avg)}ms` : '—' }}
                </h6>
              </div>
              <div>
                <p class="text-body-2 text-disabled mb-1">
                  {{ t('realtime.deliveryLatency') }}
                </p>
                <h6 class="text-h6">
                  {{ securityStore.realtimeMetrics?.latency?.delivery?.avg ? `${Math.round(securityStore.realtimeMetrics.latency.delivery.avg)}ms` : '—' }}
                </h6>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Connections Table -->
    <VRow class="mt-4">
      <VCol cols="12">
        <VCard>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="tabler-plug-connected" />
            {{ t('realtime.activeConnections') }}
          </VCardTitle>
          <VDataTable
            :headers="connectionHeaders"
            :items="securityStore.realtimeConnections.connections"
            :loading="loading"
            density="comfortable"
            :items-per-page="-1"
            hide-default-footer
          >
            <template #no-data>
              <div class="text-center pa-4 text-disabled">
                {{ t('realtime.noConnections') }}
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- Confirm Flush Dialog -->
    <VDialog
      v-model="confirmFlushDialog"
      max-width="400"
    >
      <VCard>
        <VCardTitle>{{ t('realtime.confirmFlush') }}</VCardTitle>
        <VCardText>{{ t('realtime.confirmFlushMessage') }}</VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="confirmFlushDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            @click="flushData"
          >
            {{ t('realtime.flush') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.blink {
  animation: blink-animation 1s ease-in-out infinite;
}

@keyframes blink-animation {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.2; }
}
</style>
