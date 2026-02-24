<script setup>
import { usePlatformMarketsStore } from '@/modules/platform-admin/markets/markets.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const marketsStore = usePlatformMarketsStore()
const { toast } = useAppToast()

const fxLoading = ref(true)
const fxRefreshing = ref(false)

const fxHeaders = [
  { title: t('fxRates.baseCurrency'), key: 'base_currency', width: '120px' },
  { title: t('fxRates.targetCurrency'), key: 'target_currency', width: '120px' },
  { title: t('fxRates.rate'), key: 'rate' },
  { title: t('fxRates.lastUpdated'), key: 'fetched_at' },
]

onMounted(async () => {
  try {
    await marketsStore.fetchFxRates()
  }
  catch {
    // Silent
  }
  finally {
    fxLoading.value = false
  }
})

const handleRefreshFx = async () => {
  fxRefreshing.value = true

  try {
    await marketsStore.refreshFxRates()
    toast(t('fxRates.refreshing'), 'success')

    // Refetch after a short delay to show updated data
    setTimeout(async () => {
      await marketsStore.fetchFxRates()
      fxRefreshing.value = false
    }, 2000)
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
    fxRefreshing.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center justify-space-between">
      <span>{{ t('fxRates.title') }}</span>
      <VBtn
        size="small"
        variant="outlined"
        prepend-icon="tabler-refresh"
        :loading="fxRefreshing"
        @click="handleRefreshFx"
      >
        {{ t('fxRates.refresh') }}
      </VBtn>
    </VCardTitle>
    <VDataTable
      :headers="fxHeaders"
      :items="marketsStore.fxRates"
      :loading="fxLoading"
      class="text-no-wrap"
      density="compact"
    >
      <template #item.rate="{ item }">
        <span class="font-weight-medium">{{ Number(item.rate).toFixed(4) }}</span>
      </template>

      <template #item.fetched_at="{ item }">
        <span class="text-medium-emphasis">{{ item.fetched_at || '—' }}</span>
      </template>

      <template #no-data>
        <div class="text-center pa-4 text-medium-emphasis">
          {{ t('fxRates.noRates') }}
        </div>
      </template>
    </VDataTable>
  </VCard>
</template>
