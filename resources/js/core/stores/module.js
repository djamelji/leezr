import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useModuleStore = defineStore('module', {
  state: () => ({
    _modules: [],
    _companyPlanKey: null,
    _addonSubscriptions: [], // ADR-340
    _addonDeactivationTiming: 'end_of_period', // ADR-341: policy-driven
    _loaded: false,
  }),

  getters: {
    modules: state => state._modules,
    companyPlanKey: state => state._companyPlanKey,
    activeModules: state => state._modules.filter(m => m.is_active),

    /**
     * Nav items from all active modules (for dynamic navigation).
     */
    activeNavItems: state => {
      return state._modules
        .filter(m => m.is_active)
        .flatMap(m => m.capabilities?.nav_items || [])
    },

    /**
     * Route names belonging to active modules.
     */
    activeRouteNames: state => {
      return state._modules
        .filter(m => m.is_active)
        .flatMap(m => m.capabilities?.route_names || [])
    },

    /**
     * Check if a module is active by key.
     */
    isActive: state => key => {
      return state._modules.some(m => m.key === key && m.is_active)
    },

    /** ADR-340: Get addon subscription info for deactivation dialog */
    getAddonSub: state => key => {
      return state._addonSubscriptions.find(s => s.module_key === key) ?? null
    },

    /** ADR-341: Policy-driven deactivation timing */
    addonDeactivationTiming: state => state._addonDeactivationTiming,
  },

  actions: {
    async fetchModules(options = {}) {
      // Cache fast-path: hydrate from cached data without API call
      if (options.cached) {
        this._modules = options.cached
        this._loaded = true

        return options.cached
      }

      const data = await $api('/modules', { signal: options.signal })

      this._modules = data.modules
      this._companyPlanKey = data.company_plan_key ?? null
      this._addonSubscriptions = data.addon_subscriptions ?? []
      this._addonDeactivationTiming = data.addon_deactivation_timing ?? 'end_of_period'
      this._loaded = true

      return data.modules
    },

    async enableModule(key) {
      const data = await $api(`/modules/${key}/enable`, {
        method: 'PUT',
      })

      this._modules = data.modules

      // ADR-341: Refresh addon subscriptions after toggle
      await this.fetchModules()

      return data.modules
    },

    async disableModule(key) {
      const data = await $api(`/modules/${key}/disable`, {
        method: 'PUT',
      })

      this._modules = data.modules

      // ADR-341: Refresh addon subscriptions after toggle
      await this.fetchModules()

      return data.modules
    },

    async fetchModuleSettings(key) {
      return await $api(`/modules/${key}/settings`)
    },

    async updateModuleSettings(key, settings) {
      return await $api(`/modules/${key}/settings`, {
        method: 'PUT',
        body: { settings },
      })
    },

    async fetchQuote(keys) {
      const params = keys.map(k => `keys[]=${encodeURIComponent(k)}`).join('&')

      return await $api(`/modules/quote?${params}`)
    },

    async fetchDeactivationPreview(key) {
      return await $api(`/modules/${key}/deactivation-preview`)
    },

    reset() {
      this._modules = []
      this._addonSubscriptions = []
      this._addonDeactivationTiming = 'end_of_period'
      this._loaded = false
    },
  },
})
