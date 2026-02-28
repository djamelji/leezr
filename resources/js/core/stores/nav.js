import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { $platformApi } from '@/utils/platformApi'

export const useNavStore = defineStore('nav', {
  state: () => ({
    _platformGroups: [],
    _companyGroups: [],
    _platformLoaded: false,
    _companyLoaded: false,
  }),

  getters: {
    platformGroups: state => state._platformGroups,
    companyGroups: state => state._companyGroups,
    platformLoaded: state => state._platformLoaded,
    companyLoaded: state => state._companyLoaded,

    /** Hydration source of truth — used by router guard + layout gate. */
    isHydrated: state => scope => {
      if (scope === 'platform') return state._platformLoaded
      if (scope === 'company') return state._companyLoaded

      return true
    },
  },

  actions: {
    async fetchPlatformNav({ signal } = {}) {
      const data = await $platformApi('/nav', { signal })

      this._platformGroups = data.groups
      this._platformLoaded = true

      return data.groups
    },

    async fetchCompanyNav({ signal, cached } = {}) {
      if (cached) {
        this._companyGroups = cached
        this._companyLoaded = true

        return cached
      }

      const data = await $api('/nav', { signal })

      this._companyGroups = data.groups
      this._companyLoaded = true

      return data.groups
    },

    reset() {
      this._platformGroups = []
      this._companyGroups = []
      this._platformLoaded = false
      this._companyLoaded = false
    },
  },
})
