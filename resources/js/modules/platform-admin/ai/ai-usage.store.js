import { $platformApi } from '@/utils/platformApi'

export const usePlatformAiUsageStore = defineStore('platformAiUsage', () => {
  const _usageStats = ref({})
  const _recentRequests = ref([])
  const _byProvider = ref([])
  const _byModule = ref({})

  const usageStats = computed(() => _usageStats.value)
  const recentRequests = computed(() => _recentRequests.value)
  const byProvider = computed(() => _byProvider.value)
  const byModule = computed(() => _byModule.value)

  const fetchUsage = async (period = '7d') => {
    const data = await $platformApi(`/ai/usage?period=${period}`)
    _usageStats.value = data.stats
    _recentRequests.value = data.recent_requests
    _byProvider.value = data.by_provider
    _byModule.value = data.by_module
  }

  return {
    _usageStats, _recentRequests, _byProvider, _byModule,
    usageStats, recentRequests, byProvider, byModule,
    fetchUsage,
  }
})
