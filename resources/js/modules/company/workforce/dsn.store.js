import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useDsnStore = defineStore('workforceDsn', {
  state: () => ({
    _declarations: [],
    _totalDeclarations: 0,
    _currentDeclaration: null,
    _currentValidation: null,
    _currentHistory: [],
    _statistics: null,
    _loading: false,
  }),

  getters: {
    declarations: state => state._declarations,
    totalDeclarations: state => state._totalDeclarations,
    currentDeclaration: state => state._currentDeclaration,
    currentValidation: state => state._currentValidation,
    currentHistory: state => state._currentHistory,
    statistics: state => state._statistics,
    loading: state => state._loading,
  },

  actions: {
    // ── Declarations ─────────────────────────────────────────
    async fetchDeclarations({ status, periodMonth, perPage, page } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (status) params.set('status', status)
        if (periodMonth) params.set('period_month', periodMonth)
        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)

        const qs = params.toString()
        const data = await $api(`/company/workforce/dsn${qs ? `?${qs}` : ''}`)

        this._declarations = data.data ?? []
        this._totalDeclarations = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchDeclaration(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/dsn/${id}`)

        this._currentDeclaration = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Statistics ───────────────────────────────────────────
    async fetchStatistics() {
      this._loading = true
      try {
        const data = await $api('/company/workforce/dsn/statistics')

        this._statistics = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Export & Submit ──────────────────────────────────────
    async exportDsn(payrollRunId) {
      this._loading = true
      try {
        const data = await $api('/company/workforce/dsn/export', {
          method: 'POST',
          body: { payroll_run_id: payrollRunId },
        })

        this._declarations.unshift(data)
        this._totalDeclarations++

        return data
      } finally {
        this._loading = false
      }
    },

    async submitDeclaration(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/dsn/${id}/submit`, {
          method: 'POST',
        })

        const idx = this._declarations.findIndex(d => d.id === id)
        if (idx !== -1) this._declarations[idx] = data
        if (this._currentDeclaration?.id === id) this._currentDeclaration = data

        return data
      } finally {
        this._loading = false
      }
    },

    async pollDeclaration(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/dsn/${id}/poll`, {
          method: 'POST',
        })

        if (data.declaration) {
          const idx = this._declarations.findIndex(d => d.id === id)
          if (idx !== -1) this._declarations[idx] = data.declaration
          if (this._currentDeclaration?.id === id) this._currentDeclaration = data.declaration
        }

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Validation & History ────────────────────────────────
    async fetchValidation(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/dsn/${id}/validation`)

        this._currentValidation = data

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchHistory(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/dsn/${id}/history`)

        this._currentHistory = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Reset ────────────────────────────────────────────────
    clearCurrentDeclaration() {
      this._currentDeclaration = null
      this._currentValidation = null
      this._currentHistory = []
    },
  },
})
