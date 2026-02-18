import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { applyTheme } from '@/composables/useApplyTheme'
import { refreshCsrf } from '@/utils/csrf'
import { postBroadcast } from '@/core/runtime/broadcast'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    _user: useCookie('userData').value || null,
    _companies: [],
    _currentCompanyId: useCookie('currentCompanyId').value || null,
    _hydrated: false,
  }),

  getters: {
    user: state => state._user,
    isLoggedIn: state => !!state._user,
    companies: state => state._companies,
    currentCompanyId: state => state._currentCompanyId,
    currentCompany: state => state._companies.find(c => c.id === Number(state._currentCompanyId)),
    isOwner: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.role === 'owner'
    },
    permissions: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.company_role?.permissions || []
    },
    roleLevel: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))
      if (company?.role === 'owner') return 'management'
      if (company?.company_role?.is_administrative) return 'management'

      return 'operational'
    },
  },

  actions: {
    _persistUser(user) {
      this._user = user
      useCookie('userData').value = user
    },

    _persistCompanyId(id) {
      this._currentCompanyId = id
      useCookie('currentCompanyId').value = id
    },

    hasPermission(key) {
      if (this.isOwner) return true

      return Array.isArray(this.permissions) && this.permissions.includes(key)
    },

    async register({ first_name, last_name, email, password, password_confirmation, company_name }) {
      await refreshCsrf()

      const data = await $api('/register', {
        method: 'POST',
        body: { first_name, last_name, email, password, password_confirmation, company_name },
      })

      this._persistUser(data.user)
      this._companies = [{
        id: data.company.id,
        name: data.company.name,
        slug: data.company.slug,
        role: 'owner',
        company_role: null,
      }]
      this._persistCompanyId(data.company.id)
      applyTheme(data.ui_theme)

      return data
    },

    async login({ email, password }) {
      await refreshCsrf()

      const data = await $api('/login', {
        method: 'POST',
        body: { email, password },
      })

      this._persistUser(data.user)
      applyTheme(data.ui_theme)
      await this.fetchMyCompanies()

      return data
    },

    async logout() {
      try {
        await $api('/logout', { method: 'POST' })
      }
      catch {
        // Ignore errors on logout
      }

      this._persistUser(null)
      this._companies = []
      this._persistCompanyId(null)
      this._hydrated = false
      postBroadcast('logout')
    },

    async fetchMe({ signal } = {}) {
      try {
        const data = await $api('/me', { _authCheck: true, signal })

        this._persistUser(data.user)
        applyTheme(data.ui_theme)
        this._hydrated = true

        return data.user
      }
      catch {
        this._persistUser(null)
        this._hydrated = true

        return null
      }
    },

    async fetchMyCompanies(options = {}) {
      // Cache fast-path: hydrate from cached data without API call
      if (options.cached) {
        this._companies = options.cached
        if (!this._currentCompanyId && options.cached.length > 0) {
          this._persistCompanyId(options.cached[0].id)
        }

        return options.cached
      }

      const data = await $api('/my-companies', { signal: options.signal })

      this._companies = data.companies

      // Auto-select first company if none selected
      if (!this._currentCompanyId && data.companies.length > 0) {
        this._persistCompanyId(data.companies[0].id)
      }

      return data.companies
    },

    switchCompany(companyId) {
      this._persistCompanyId(companyId)
      postBroadcast('company-switch', { companyId })
    },
  },
})
