import { usePlatformAiProvidersStore } from './ai-providers.store'
import { usePlatformAiUsageStore } from './ai-usage.store'

export const usePlatformAiStore = defineStore('platformAi', () => {
  const providersStore = usePlatformAiProvidersStore()
  const usageStore = usePlatformAiUsageStore()

  return {
    // Providers sub-store
    ...storeToRefs(providersStore),
    fetchProviders: providersStore.fetchProviders,
    installProvider: providersStore.installProvider,
    activateProvider: providersStore.activateProvider,
    deactivateProvider: providersStore.deactivateProvider,
    updateProviderCredentials: providersStore.updateProviderCredentials,
    runHealthCheck: providersStore.runHealthCheck,
    fetchRouting: providersStore.fetchRouting,
    updateRouting: providersStore.updateRouting,
    fetchConfig: providersStore.fetchConfig,
    updateConfig: providersStore.updateConfig,

    // Usage sub-store
    ...storeToRefs(usageStore),
    fetchUsage: usageStore.fetchUsage,
    fetchHealth: usageStore.fetchHealth,
  }
})
