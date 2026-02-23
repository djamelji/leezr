import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformMarketsStore = defineStore('platformMarkets', {
  state: () => ({
    _markets: [],
    _currentMarket: null,
    _marketCompanies: [],
    _marketCompaniesPagination: { current_page: 1, last_page: 1, total: 0 },
    _languages: [],
    _translations: [],
    _translationsPagination: { current_page: 1, last_page: 1, total: 0 },
    _fxRates: [],
  }),

  getters: {
    markets: state => state._markets,
    currentMarket: state => state._currentMarket,
    marketCompanies: state => state._marketCompanies,
    marketCompaniesPagination: state => state._marketCompaniesPagination,
    languages: state => state._languages,
    translations: state => state._translations,
    translationsPagination: state => state._translationsPagination,
    fxRates: state => state._fxRates,
  },

  actions: {
    // ─── Markets CRUD ──────────────────────────────────
    async fetchMarkets() {
      this._markets = await $platformApi('/markets')
    },

    async fetchMarket(key, companiesPage = 1) {
      const data = await $platformApi(`/markets/${key}`, {
        params: { companies_page: companiesPage },
      })

      this._currentMarket = data.market
      this._marketCompanies = data.companies.data
      this._marketCompaniesPagination = {
        current_page: data.companies.current_page,
        last_page: data.companies.last_page,
        total: data.companies.total,
      }

      return data
    },

    async createMarket(payload) {
      const data = await $platformApi('/markets', {
        method: 'POST',
        body: payload,
      })

      this._markets.push(data.market)

      return data
    },

    async updateMarket(id, payload) {
      const data = await $platformApi(`/markets/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._markets.findIndex(m => m.id === id)
      if (idx !== -1)
        this._markets[idx] = data.market

      if (this._currentMarket?.id === id)
        this._currentMarket = data.market

      return data
    },

    async toggleActive(id) {
      const data = await $platformApi(`/markets/${id}/toggle-active`, {
        method: 'PUT',
      })

      const idx = this._markets.findIndex(m => m.id === id)
      if (idx !== -1)
        this._markets[idx] = data.market

      if (this._currentMarket?.id === id)
        this._currentMarket = data.market

      return data
    },

    async setDefault(id) {
      const data = await $platformApi(`/markets/${id}/set-default`, {
        method: 'PUT',
      })

      // Update all markets to reflect new default
      this._markets.forEach(m => {
        m.is_default = m.id === id
      })

      if (this._currentMarket?.id === id)
        this._currentMarket = data.market

      return data
    },

    // ─── Legal Statuses ────────────────────────────────
    async createLegalStatus(marketKey, payload) {
      const data = await $platformApi(`/markets/${marketKey}/legal-statuses`, {
        method: 'POST',
        body: payload,
      })

      if (this._currentMarket?.key === marketKey) {
        this._currentMarket.legal_statuses = [
          ...(this._currentMarket.legal_statuses || []),
          data.legal_status,
        ]
      }

      return data
    },

    async updateLegalStatus(id, payload) {
      const data = await $platformApi(`/legal-statuses/${id}`, {
        method: 'PUT',
        body: payload,
      })

      if (this._currentMarket?.legal_statuses) {
        const idx = this._currentMarket.legal_statuses.findIndex(ls => ls.id === id)
        if (idx !== -1)
          this._currentMarket.legal_statuses[idx] = data.legal_status
      }

      return data
    },

    async deleteLegalStatus(id) {
      const data = await $platformApi(`/legal-statuses/${id}`, {
        method: 'DELETE',
      })

      if (this._currentMarket?.legal_statuses) {
        this._currentMarket.legal_statuses = this._currentMarket.legal_statuses.filter(ls => ls.id !== id)
      }

      return data
    },

    async reorderLegalStatuses(marketKey, ids) {
      return await $platformApi(`/markets/${marketKey}/legal-statuses/reorder`, {
        method: 'PUT',
        body: { ids },
      })
    },

    // ─── Languages ─────────────────────────────────────
    async fetchLanguages() {
      this._languages = await $platformApi('/languages')
    },

    async createLanguage(payload) {
      const data = await $platformApi('/languages', {
        method: 'POST',
        body: payload,
      })

      this._languages.push(data.language)

      return data
    },

    async updateLanguage(id, payload) {
      const data = await $platformApi(`/languages/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._languages.findIndex(l => l.id === id)
      if (idx !== -1)
        this._languages[idx] = data.language

      return data
    },

    async deleteLanguage(id) {
      const data = await $platformApi(`/languages/${id}`, {
        method: 'DELETE',
      })

      this._languages = this._languages.filter(l => l.id !== id)

      return data
    },

    async toggleLanguageActive(id) {
      const data = await $platformApi(`/languages/${id}/toggle-active`, {
        method: 'PUT',
      })

      const idx = this._languages.findIndex(l => l.id === id)
      if (idx !== -1)
        this._languages[idx] = data.language

      return data
    },

    // ─── Translations ──────────────────────────────────
    async fetchTranslations(params = {}) {
      const data = await $platformApi('/translations', { params })

      this._translations = data.data
      this._translationsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }

      return data
    },

    async fetchTranslation(locale, namespace) {
      return await $platformApi(`/translations/${locale}/${namespace}`)
    },

    async updateTranslation(id, payload) {
      return await $platformApi(`/translations/${id}`, {
        method: 'PUT',
        body: payload,
      })
    },

    async importPreview(formData) {
      return await $platformApi('/translations/import-preview', {
        method: 'POST',
        body: formData,
      })
    },

    async importApply(formData) {
      return await $platformApi('/translations/import-apply', {
        method: 'POST',
        body: formData,
      })
    },

    async exportLocale(locale) {
      return await $platformApi(`/translations/export/${locale}`)
    },

    // ─── FX Rates ──────────────────────────────────────
    async fetchFxRates() {
      const data = await $platformApi('/fx-rates')

      this._fxRates = data.rates
    },

    async refreshFxRates() {
      return await $platformApi('/fx-rates/refresh', { method: 'POST' })
    },
  },
})
