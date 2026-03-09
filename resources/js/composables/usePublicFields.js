import { $api } from '@/utils/api'

/**
 * ADR-290: Composable for fetching public field definitions for registration.
 * Works without authentication (public endpoint).
 */
export function usePublicFields() {
  const fields = ref([])
  const loading = ref(false)

  async function fetchFields(jobdomainKey, marketKey) {
    if (!jobdomainKey) {
      fields.value = []

      return
    }

    loading.value = true

    try {
      const params = { jobdomain: jobdomainKey }

      if (marketKey)
        params.market = marketKey

      const data = await $api('/public/fields', { params })

      fields.value = data.fields
    }
    finally {
      loading.value = false
    }
  }

  function reset() {
    fields.value = []
  }

  return { fields, loading, fetchFields, reset }
}
