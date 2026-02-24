import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformTranslationsStore = defineStore('platformTranslations', {
  state: () => ({
    // Matrix
    _matrixRows: [],
    _matrixPagination: { current_page: 1, last_page: 1, per_page: 50, total: 0 },
    _matrixSection: '',
    _matrixLocales: [],

    // Bundles
    _bundles: [],
    _bundlesPagination: { current_page: 1, last_page: 1, total: 0 },
    _currentBundle: null,

    // Overrides
    _overrides: [],

    // Meta
    _availableNamespaces: [],
    _stats: {},
  }),

  getters: {
    matrixRows: state => state._matrixRows,
    matrixPagination: state => state._matrixPagination,
    matrixSection: state => state._matrixSection,
    matrixLocales: state => state._matrixLocales,
    bundles: state => state._bundles,
    bundlesPagination: state => state._bundlesPagination,
    currentBundle: state => state._currentBundle,
    overrides: state => state._overrides,
    availableNamespaces: state => state._availableNamespaces,
    stats: state => state._stats,
  },

  actions: {
    // ─── Matrix ─────────────────────────────────────────
    async fetchMatrix({ section, locales, q, page, perPage }) {
      const data = await $platformApi('/translations/matrix', {
        params: {
          section,
          locales: locales.join(','),
          q: q || undefined,
          page: page || 1,
          per_page: perPage || 50,
        },
      })

      this._matrixRows = data.rows
      this._matrixPagination = data.pagination
      this._matrixSection = data.section
      this._matrixLocales = data.locales

      return data
    },

    async updateMatrix({ section, locales, rows }) {
      const data = await $platformApi('/translations/matrix', {
        method: 'PUT',
        body: { section, locales, rows },
      })

      return data
    },

    // ─── Bundles ────────────────────────────────────────
    async fetchBundles(params = {}) {
      const data = await $platformApi('/translations', { params })

      this._bundles = data.data
      this._bundlesPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }

      return data
    },

    async fetchBundle(locale, namespace) {
      const data = await $platformApi(`/translations/${locale}/${namespace}`)

      this._currentBundle = data

      return data
    },

    async updateBundle(id, payload) {
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

    // ─── Overrides ──────────────────────────────────────
    async fetchOverrides(marketKey, locale) {
      const data = await $platformApi(`/translations/overrides/${marketKey}/${locale}`)

      this._overrides = data

      return data
    },

    async upsertOverrides(marketKey, payload) {
      const data = await $platformApi(`/translations/overrides/${marketKey}`, {
        method: 'PUT',
        body: payload,
      })

      return data
    },

    async deleteOverride(id) {
      const data = await $platformApi(`/translations/overrides/${id}`, {
        method: 'DELETE',
      })

      this._overrides = this._overrides.filter(o => o.id !== id)

      return data
    },

    // ─── Stats ────────────────────────────────────────────
    async fetchStats() {
      const data = await $platformApi('/translations/stats')

      this._stats = data.locales || {}

      return data
    },

    // ─── Namespace list ─────────────────────────────────
    async loadNamespaces() {
      if (this._availableNamespaces.length > 0)
        return

      // Fetch namespaces from backend (static JSON + DB)
      const data = await $platformApi('/translations/namespaces')

      this._availableNamespaces = data
    },
  },
})
