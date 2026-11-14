import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { $platformApi } from '@/utils/platformApi'

export const useNavStore = defineStore('nav', {
  state: () => ({
    _platformGroups: [],
    _companyGroups: [],
    _platformWidgets: [],
    _companyWidgets: [],
    _platformFooterLinks: [],
    _companyFooterLinks: [],
    _companyActions: [],
    _platformLoaded: false,
    _companyLoaded: false,
  }),

  getters: {
    platformGroups: state => state._platformGroups,
    companyGroups: state => state._companyGroups,
    platformWidgets: state => state._platformWidgets,
    companyWidgets: state => state._companyWidgets,
    platformFooterLinks: state => state._platformFooterLinks,
    companyFooterLinks: state => state._companyFooterLinks,

    /** ADR-775 — contextual actions the user may perform (server-filtered : module + RBAC). */
    companyActions: state => state._companyActions,

    /** ADR-775 — personal entries shown in the avatar menu (groups placed in 'menu'). */
    companyPersonalMenu: state => state._companyGroups.filter(g => g.placement === 'menu').flatMap(g => g.items),
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
      this._platformWidgets = data.header_widgets || []
      this._platformFooterLinks = data.footer_links || []
      this._platformLoaded = true

      return data.groups
    },

    async fetchCompanyNav({ signal, cached } = {}) {
      // ADR-775 : the WHOLE payload is cached and restored (groups, widgets, footer links, actions).
      // Before, a cache hit restored the groups only — widgets and footer links vanished on reload.
      // An array is the previous cache format (groups only) : refetch rather than restore half a menu.
      const data = cached && !Array.isArray(cached) ? cached : await $api('/nav', { signal })

      this._companyGroups = data.groups || []
      this._companyWidgets = data.header_widgets || []
      this._companyFooterLinks = data.footer_links || []
      this._companyActions = data.actions || []
      this._companyLoaded = true

      return data
    },

    reset() {
      this._platformGroups = []
      this._companyGroups = []
      this._platformWidgets = []
      this._companyWidgets = []
      this._platformFooterLinks = []
      this._companyFooterLinks = []
      this._companyActions = []
      this._platformLoaded = false
      this._companyLoaded = false
    },
  },
})
