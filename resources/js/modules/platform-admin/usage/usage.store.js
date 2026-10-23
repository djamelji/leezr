import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

/**
 * ADR-601 — Platform "Usage & Consumption" read store.
 * Wraps the EXISTING endpoints only (no new backend): daily-aggregated platform usage
 * (/usage/overview) and per-company detail (/usage/company/{id}), both gated server-side by
 * view_usage_monitoring. AI real-time observability lives in the separate AI usage store
 * (view_ai) so the two permission scopes never mix.
 */
export const usePlatformUsageStore = defineStore('platformUsage', () => {
  const _overview = ref(null)
  const _companyDetail = ref(null)

  const overview = computed(() => _overview.value)
  const companyDetail = computed(() => _companyDetail.value)

  async function fetchOverview(days = 30) {
    _overview.value = await $platformApi(`/usage/overview?days=${days}`)

    return _overview.value
  }

  async function fetchCompanyDetail(companyId, days = 30) {
    _companyDetail.value = await $platformApi(`/usage/company/${companyId}?days=${days}`)

    return _companyDetail.value
  }

  return { _overview, _companyDetail, overview, companyDetail, fetchOverview, fetchCompanyDetail }
})
