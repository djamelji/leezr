import { $platformApi } from '@/utils/platformApi'

export const usePlatformAiProvidersStore = defineStore('platformAiProviders', () => {
  const _providers = ref([])
  const _routing = ref({})
  const _availableProviders = ref([])
  const _config = ref({})
  const _configDefaults = ref({})

  const providers = computed(() => _providers.value)
  const routing = computed(() => _routing.value)
  const availableProviders = computed(() => _availableProviders.value)
  const config = computed(() => _config.value)
  const configDefaults = computed(() => _configDefaults.value)

  const fetchProviders = async () => {
    const data = await $platformApi('/ai/providers')
    _providers.value = data.providers
  }

  const installProvider = async providerKey => {
    const data = await $platformApi(`/ai/providers/${providerKey}/install`, { method: 'PUT' })
    await fetchProviders()

    return data
  }

  const activateProvider = async providerKey => {
    const data = await $platformApi(`/ai/providers/${providerKey}/activate`, { method: 'PUT' })
    await fetchProviders()

    return data
  }

  const deactivateProvider = async providerKey => {
    const data = await $platformApi(`/ai/providers/${providerKey}/deactivate`, { method: 'PUT' })
    await fetchProviders()

    return data
  }

  const updateProviderCredentials = async (providerKey, credentials) => {
    const data = await $platformApi(`/ai/providers/${providerKey}/credentials`, { method: 'PUT', body: { credentials } })
    await fetchProviders()

    return data
  }

  const runHealthCheck = async providerKey => {
    const data = await $platformApi(`/ai/providers/${providerKey}/health-check`, { method: 'POST' })
    await fetchProviders()

    return data
  }

  const fetchRouting = async () => {
    const data = await $platformApi('/ai/routing')
    _routing.value = data.routing
    _availableProviders.value = data.available_providers
  }

  const updateRouting = async routingPayload => {
    const data = await $platformApi('/ai/routing', { method: 'PUT', body: { routing: routingPayload } })
    _routing.value = routingPayload

    return data
  }

  const fetchConfig = async () => {
    const data = await $platformApi('/ai/config')
    _config.value = data.config
    _configDefaults.value = data.defaults
  }

  const updateConfig = async payload => {
    const data = await $platformApi('/ai/config', { method: 'PUT', body: payload })
    _config.value = data.config

    return data
  }

  return {
    _providers, _routing, _availableProviders, _config, _configDefaults,
    providers, routing, availableProviders, config, configDefaults,
    fetchProviders, installProvider, activateProvider, deactivateProvider,
    updateProviderCredentials, runHealthCheck,
    fetchRouting, updateRouting, fetchConfig, updateConfig,
  }
})
