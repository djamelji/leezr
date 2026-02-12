import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useModuleStore = defineStore('module', {
  state: () => ({
    _modules: [],
    _loaded: false,
  }),

  getters: {
    modules: state => state._modules,
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
  },

  actions: {
    async fetchModules() {
      const data = await $api('/modules')

      this._modules = data.modules
      this._loaded = true

      return data.modules
    },

    async enableModule(key) {
      const data = await $api(`/modules/${key}/enable`, {
        method: 'PUT',
      })

      this._modules = data.modules

      return data.modules
    },

    async disableModule(key) {
      const data = await $api(`/modules/${key}/disable`, {
        method: 'PUT',
      })

      this._modules = data.modules

      return data.modules
    },

    reset() {
      this._modules = []
      this._loaded = false
    },
  },
})
