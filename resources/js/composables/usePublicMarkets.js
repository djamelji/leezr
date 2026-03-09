import { $api } from '@/utils/api'

/**
 * ADR-290: Composable for fetching active markets and their legal statuses.
 * Works without authentication (public endpoints).
 */
export function usePublicMarkets() {
  const markets = ref([])
  const legalStatuses = ref([])
  const loading = ref(false)
  const legalStatusesLoading = ref(false)

  async function fetchMarkets() {
    loading.value = true

    try {
      const data = await $api('/public/markets')

      markets.value = data
    }
    finally {
      loading.value = false
    }
  }

  async function fetchLegalStatuses(marketKey) {
    if (!marketKey) {
      legalStatuses.value = []

      return
    }

    legalStatusesLoading.value = true

    try {
      const data = await $api(`/public/markets/${marketKey}`)

      legalStatuses.value = data.legal_statuses || []
    }
    catch {
      legalStatuses.value = []
    }
    finally {
      legalStatusesLoading.value = false
    }
  }

  return { markets, legalStatuses, loading, legalStatusesLoading, fetchMarkets, fetchLegalStatuses }
}
