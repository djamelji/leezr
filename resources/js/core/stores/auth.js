import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { refreshCsrf } from '@/utils/csrf'

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

    async register({ name, email, password, password_confirmation, company_name }) {
      await refreshCsrf()

      const data = await $api('/register', {
        method: 'POST',
        body: { name, email, password, password_confirmation, company_name },
      })

      this._persistUser(data.user)
      this._companies = [{
        id: data.company.id,
        name: data.company.name,
        slug: data.company.slug,
        role: 'owner',
      }]
      this._persistCompanyId(data.company.id)

      return data
    },

    async login({ email, password }) {
      await refreshCsrf()

      const data = await $api('/login', {
        method: 'POST',
        body: { email, password },
      })

      this._persistUser(data.user)
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
    },

    async fetchMe() {
      try {
        const data = await $api('/me')

        this._persistUser(data.user)
        this._hydrated = true

        return data.user
      }
      catch {
        this._persistUser(null)
        this._hydrated = true

        return null
      }
    },

    async fetchMyCompanies() {
      const data = await $api('/my-companies')

      this._companies = data.companies

      // Auto-select first company if none selected
      if (!this._currentCompanyId && data.companies.length > 0) {
        this._persistCompanyId(data.companies[0].id)
      }

      return data.companies
    },

    switchCompany(companyId) {
      this._persistCompanyId(companyId)
    },
  },
})
