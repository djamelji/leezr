import { $platformApi } from '@/utils/platformApi'

export const usePlatformAiUsageStore = defineStore('platformAiUsage', () => {
  const _usageStats = ref({})
  const _recentRequests = ref([])
  const _byProvider = ref([])
  const _byModule = ref({})
  const _byCompany = ref([])
  const _health = ref(null)

  const usageStats = computed(() => _usageStats.value)
  const recentRequests = computed(() => _recentRequests.value)
  const byProvider = computed(() => _byProvider.value)
  const byModule = computed(() => _byModule.value)
  const byCompany = computed(() => _byCompany.value)
  const health = computed(() => _health.value)

  const fetchUsage = async (period = '7d') => {
    const data = await $platformApi(`/ai/usage?period=${period}`)
    _usageStats.value = data.stats
    _recentRequests.value = data.recent_requests
    _byProvider.value = data.by_provider
    _byModule.value = data.by_module
    _byCompany.value = data.by_company ?? []
  }

  const fetchHealth = async () => {
    const data = await $platformApi('/ai/health')
    _health.value = data
  }

  return {
    _usageStats, _recentRequests, _byProvider, _byModule, _byCompany, _health,
    usageStats, recentRequests, byProvider, byModule, byCompany, health,
    fetchUsage, fetchHealth,
  }
})
