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

const recentHeaders = computed(() => [
  { title: t('platformAi.provider'), key: 'provider', width: 100 },
  { title: t('platformAi.model'), key: 'model', width: 140 },
  { title: 'Capability', key: 'capability', width: 130 },
  { title: t('platformAi.latency'), key: 'latency_ms', width: 100 },
  { title: t('platformAi.statusLabel'), key: 'status', width: 100 },
  { title: 'Module', key: 'module_key', width: 120 },
  { title: 'Date', key: 'created_at', width: 160 },
])

const load = async () => {
  isLoading.value = true
  try {
    await store.fetchUsage(period.value)
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

    <!-- KPI Cards -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">
              {{ store.usageStats?.total_requests ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.totalRequests') }}
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
            <div class="text-h4 font-weight-bold">
              {{ store.usageStats?.avg_latency_ms ?? 0 }}ms
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.avgLatency') }}
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
            <div class="text-h4 font-weight-bold">
              {{ store.usageStats?.errors ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.errorRate') }}
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
            <div class="text-h4 font-weight-bold">
              {{ (store.usageStats?.total_input_tokens ?? 0) + (store.usageStats?.total_output_tokens ?? 0) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('platformAi.totalTokens') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Recent Requests Table -->
    <VCard>
      <VCardTitle class="pa-4">
        {{ t('platformAi.recentRequests') }}
      </VCardTitle>
      <VDataTable
        :items="store.recentRequests"
        :headers="recentHeaders"
        :loading="isLoading"
        density="compact"
      >
        <template #item.latency_ms="{ item }">
          {{ item.latency_ms }}ms
        </template>

        <template #item.status="{ item }">
          <VChip
            size="x-small"
            :color="item.status === 'success' ? 'success' : 'error'"
          >
            {{ item.status }}
          </VChip>
        </template>

        <template #item.created_at="{ item }">
          <span class="text-caption">{{ new Date(item.created_at).toLocaleString() }}</span>
        </template>

        <template #no-data>
          <div class="text-center pa-6 text-medium-emphasis">
            {{ t('platformAi.noRequests') }}
          </div>
        </template>
      </VDataTable>
    </VCard>
  </div>
</template>
