import { $api } from '@/utils/api'

/**
 * ADR-300: Composable for fetching public addon modules for registration.
 * Works without authentication (public endpoint).
 */
export function usePublicAddons() {
  const addons = ref([])
  const currency = ref('EUR')
  const loading = ref(false)

  async function fetchAddons(jobdomainKey, planKey, marketKey) {
    if (!jobdomainKey || !planKey) {
      addons.value = []

      return
    }

    loading.value = true

    try {
      const params = { jobdomain: jobdomainKey, plan: planKey }

      if (marketKey)
        params.market = marketKey

      const data = await $api('/public/addons', { params })

      addons.value = data.addons
      currency.value = data.currency || 'EUR'
    }
    finally {
      loading.value = false
    }
  }

  function reset() {
    addons.value = []
  }

  return { addons, currency, loading, fetchAddons, reset }
}
