import { $api } from '@/utils/api'

/**
 * ADR-100: Composable for fetching public plan/pricing data.
 * Works without authentication (public endpoints).
 */
export function usePublicPlans() {
  const plans = ref([])
  const jobdomains = ref([])
  const billingPolicy = ref({})
  const loading = ref(false)
  const previewModules = ref([])
  const previewLoading = ref(false)

  async function fetchPlans() {
    loading.value = true

    try {
      const data = await $api('/public/plans')

      plans.value = data.plans
      jobdomains.value = data.jobdomains
      billingPolicy.value = data.billing_policy || {}
    }
    finally {
      loading.value = false
    }
  }

  async function fetchPreview(jobdomainKey, planKey) {
    if (!jobdomainKey || !planKey) {
      previewModules.value = []

      return
    }

    previewLoading.value = true

    try {
      const data = await $api('/public/plans/preview', {
        params: { jobdomain: jobdomainKey, plan: planKey },
      })

      previewModules.value = data.modules
    }
    finally {
      previewLoading.value = false
    }
  }

  return { plans, jobdomains, billingPolicy, loading, previewModules, previewLoading, fetchPlans, fetchPreview }
}
