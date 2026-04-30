<script setup>
import { usePlatformAiStore } from '@/modules/platform-admin/ai/ai.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformAiStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const period = ref('7d')

const periodOptions = [
  { title: '24h', value: '24h' },
  { title: '7 jours', value: '7d' },
  { title: '30 jours', value: '30d' },
]

const topConsumersHeaders = computed(() => [
  { title: t('platformAi.operations.company'), key: 'company_name' },
  { title: t('platformAi.operations.requests'), key: 'total_requests', align: 'end' },
  { title: t('platformAi.operations.tokens'), key: 'total_tokens', align: 'end' },
  { title: t('platformAi.operations.errors'), key: 'errors', align: 'end' },
])

const queueHealthColor = computed(() => {
  if (!store.health?.queue) return 'secondary'

  return store.health.queue.healthy ? 'success' : 'error'
})

const providerHealthColor = computed(() => {
  if (!store.health?.provider) return 'secondary'

  return store.health.provider.healthy ? 'success' : 'error'
})

const overallHealthColor = computed(() => {
  if (!store.health) return 'secondary'

  return store.health.healthy ? 'success' : 'error'
})

const errorRate = computed(() => {
  const stats = store.usageStats
  if (!stats?.total_requests) return '0'
  const rate = ((stats.errors || 0) / stats.total_requests) * 100

  return rate.toFixed(1)
})

const load = async () => {
  isLoading.value = true
  try {
    await Promise.all([
      store.fetchHealth(),
      store.fetchUsage(period.value),
    ])
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

watch(period, () => load())
onMounted(() => load())
</script>

<template>
  <div>
    <!-- Period selector -->
    <div class="d-flex justify-end mb-4">
      <AppSelect
        v-model="period"
        :items="periodOptions"
        density="compact"
        style="max-inline-size: 160px;"
      />
    </div>

    <!-- Health Status Cards -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <VIcon
              :icon="store.health?.healthy ? 'tabler-circle-check' : 'tabler-alert-triangle'"
              :color="overallHealthColor"
              size="28"
              class="mb-1"
            />
            <div class="text-h6 font-weight-bold" :class="`text-${overallHealthColor}`">
              {{ store.health?.healthy ? t('platformAi.operations.healthy') : t('platformAi.operations.degraded') }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.operations.overallStatus') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold" :class="`text-${queueHealthColor}`">
              {{ store.health?.queue?.pending_jobs ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.operations.pendingJobs') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold" :class="(store.health?.queue?.failed_jobs_24h ?? 0) > 0 ? 'text-error' : 'text-success'">
              {{ store.health?.queue?.failed_jobs_24h ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.operations.failedJobs24h') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold" :class="parseFloat(errorRate) > 5 ? 'text-error' : 'text-success'">
              {{ errorRate }}%
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.operations.errorRatePercent') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <VRow class="mb-6">
      <!-- Provider Health -->
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardTitle class="d-flex align-center pa-4">
            <VIcon
              start
              icon="tabler-heartbeat"
              :color="providerHealthColor"
            />
            {{ t('platformAi.operations.providerHealth') }}
          </VCardTitle>

          <VCardText v-if="!isLoading && store.health?.provider">
            <div class="d-flex align-center gap-3 mb-3">
              <VChip
                size="small"
                variant="tonal"
                :color="providerHealthColor"
              >
                {{ store.health.provider.status }}
              </VChip>
              <span class="text-body-2 text-medium-emphasis">
                {{ store.health.provider.key }}
              </span>
            </div>
            <p
              v-if="store.health.provider.message"
              class="text-body-2 text-medium-emphasis mb-0"
            >
              {{ store.health.provider.message }}
            </p>
          </VCardText>

          <VCardText v-else-if="isLoading">
            <VSkeletonLoader type="text@2" />
          </VCardText>

          <VCardText v-else>
            <span class="text-medium-emphasis">{{ t('common.noData') }}</span>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Document Processing -->
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardTitle class="d-flex align-center pa-4">
            <VIcon
              start
              icon="tabler-file-analytics"
              color="primary"
            />
            {{ t('platformAi.operations.documentProcessing') }}
          </VCardTitle>

          <VCardText v-if="!isLoading && store.health?.documents">
            <div class="d-flex flex-wrap gap-3">
              <VChip
                size="small"
                variant="tonal"
                color="warning"
              >
                {{ t('platformAi.operations.pending') }}: {{ store.health.documents.pending }}
              </VChip>
              <VChip
                size="small"
                variant="tonal"
                color="info"
              >
                {{ t('platformAi.operations.processing') }}: {{ store.health.documents.processing }}
              </VChip>
              <VChip
                size="small"
                variant="tonal"
                color="success"
              >
                {{ t('platformAi.operations.completed') }}: {{ store.health.documents.completed }}
              </VChip>
              <VChip
                size="small"
                variant="tonal"
                color="error"
              >
                {{ t('platformAi.operations.failed') }}: {{ store.health.documents.failed }}
              </VChip>
            </div>
          </VCardText>

          <VCardText v-else-if="isLoading">
            <VSkeletonLoader type="text@2" />
          </VCardText>

          <VCardText v-else>
            <span class="text-medium-emphasis">{{ t('common.noData') }}</span>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Top Consumers -->
    <VCard>
      <VCardTitle class="pa-4">
        {{ t('platformAi.operations.topConsumers') }}
      </VCardTitle>
      <VDataTable
        :items="store.byCompany"
        :headers="topConsumersHeaders"
        :loading="isLoading"
        density="compact"
      >
        <template #item.total_tokens="{ item }">
          {{ item.total_tokens.toLocaleString() }}
        </template>

        <template #item.errors="{ item }">
          <VChip
            v-if="item.errors > 0"
            size="x-small"
            color="error"
            variant="tonal"
          >
            {{ item.errors }}
          </VChip>
          <span v-else class="text-medium-emphasis">0</span>
        </template>

        <template #no-data>
          <div class="text-center pa-6 text-medium-emphasis">
            {{ t('platformAi.operations.noCompanyData') }}
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
