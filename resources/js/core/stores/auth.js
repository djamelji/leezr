import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { applyTheme } from '@/composables/useApplyTheme'
import { useThemeStore } from '@/modules/core/theme/theme.store'
import { refreshCsrf } from '@/utils/csrf'
import { postBroadcast } from '@/core/runtime/broadcast'
import { useWorldStore } from '@/core/stores/world'
import { cacheClear } from '@/core/runtime/cache'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    _user: useCookie('userData').value || null,
    _companies: [],
    _currentCompanyId: useCookie('currentCompanyId').value || null,
    _hydrated: false,
    _sessionConfig: null,
  }),

  getters: {
    user: state => state._user,
    isLoggedIn: state => !!state._user,
    sessionConfig: state => state._sessionConfig,
    companies: state => state._companies,
    currentCompanyId: state => state._currentCompanyId,
    currentCompany: state => state._companies.find(c => c.id === Number(state._currentCompanyId)),
    isOwner: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.role === 'owner'
    },
    isAdministrative: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.is_administrative === true
    },
    permissions: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.company_role?.permissions || []
    },
    roleLevel: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.is_administrative ? 'management' : 'operational'
    },
    // ADR-357: Workspace resolved by backend — NO business logic in frontend
    workspace: state => {
      const company = state._companies.find(c => c.id === Number(state._currentCompanyId))

      return company?.workspace || 'dashboard'
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

    async register({ first_name, last_name, email, password, password_confirmation, company_name, jobdomain_key, plan_key, billing_interval, market_key, legal_status_key, dynamic_fields, addon_keys, billing_same_as_company }) {
      await refreshCsrf()

      const data = await $api('/register', {
        method: 'POST',
        body: { first_name, last_name, email, password, password_confirmation, company_name, jobdomain_key, plan_key, billing_interval, market_key, legal_status_key, dynamic_fields, addon_keys, billing_same_as_company },
      })

      this._persistUser(data.user)
      this._companies = [{
        id: data.company.id,
        name: data.company.name,
        slug: data.company.slug,
        role: 'owner',
        is_administrative: true,
        company_role: null,
        plan_key: data.company.plan_key ?? 'starter',
      }]
      this._persistCompanyId(data.company.id)
      applyTheme(data.ui_theme, data.theme_preference)
      useThemeStore().init(data.theme_preference, 'company')
      this._sessionConfig = data.ui_session ?? null

      return data
    },

    async confirmRegistrationPayment(paymentMethodId, subscriptionId) {
      return await $api('/register/confirm-payment', {
        method: 'POST',
        body: { payment_method_id: paymentMethodId, subscription_id: subscriptionId },
      })
    },

    async login({ email, password }) {
      await refreshCsrf()

      const data = await $api('/login', {
        method: 'POST',
        body: { email, password },
      })

      // ADR-351: 2FA required — don't persist yet
      if (data.requires_2fa) {
        return data
      }

      this._persistUser(data.user)
      applyTheme(data.ui_theme, data.theme_preference)
      useThemeStore().init(data.theme_preference, 'company')
      this._sessionConfig = data.ui_session ?? null
      cacheClear() // Clear stale SWR cache before fetching new user's companies
      await this.fetchMyCompanies()

      return data
    },

    async verify2fa(code) {
      const data = await $api('/2fa/verify', {
        method: 'POST',
        body: { code },
      })

      this._persistUser(data.user)
      applyTheme(data.ui_theme, data.theme_preference)
      useThemeStore().init(data.theme_preference, 'company')
      this._sessionConfig = data.ui_session ?? null
      cacheClear() // Clear stale SWR cache before fetching new user's companies
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
      cacheClear() // Prevent stale SWR cache from serving old user's data after re-login
      postBroadcast('logout')
    },

    async fetchMe({ signal } = {}) {
      try {
        const data = await $api('/me', { _authCheck: true, signal })

        this._persistUser(data.user)
        applyTheme(data.ui_theme, data.theme_preference)
        useThemeStore().init(data.theme_preference, 'company')
        this._sessionConfig = data.ui_session ?? null
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

      // ADR-435: Sync worldStore with current company's market
      this._syncMarket()

      return data.companies
    },

    switchCompany(companyId) {
      this._persistCompanyId(companyId)
      // ADR-435: Apply market before reload/broadcast
      this._syncMarket()
      postBroadcast('company-switch', { companyId })
    },

    /**
     * ADR-435: Sync worldStore with the current company's market data.
     * Called after fetchMyCompanies and on company switch.
     */
    _syncMarket() {
      const company = this.currentCompany
      if (company?.market) {
        useWorldStore().applyMarket(company.market)
      }
    },
  },
})
