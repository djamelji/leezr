import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const usePayrollStore = defineStore('workforcePayroll', {
  state: () => ({
    _runs: [],
    _totalRuns: 0,
    _currentRun: null,
    _currentRunLines: [],
    _currentRunTotalLines: 0,
    _currentRunCalculations: null,
    _currentRunTotals: null,
    _statistics: null,
    _loading: false,
  }),

  getters: {
    runs: state => state._runs,
    totalRuns: state => state._totalRuns,
    currentRun: state => state._currentRun,
    currentRunLines: state => state._currentRunLines,
    currentRunTotalLines: state => state._currentRunTotalLines,
    currentRunCalculations: state => state._currentRunCalculations,
    currentRunTotals: state => state._currentRunTotals,
    statistics: state => state._statistics,
    loading: state => state._loading,
  },

  actions: {
    // ── Payroll Runs ───────────────────────────────────────────
    async fetchRuns({ status, periodStart, periodEnd, perPage, page } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (status) params.set('status', status)
        if (periodStart) params.set('period_start', periodStart)
        if (periodEnd) params.set('period_end', periodEnd)
        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)

        const qs = params.toString()
        const data = await $api(`/company/workforce/payroll${qs ? `?${qs}` : ''}`)

        this._runs = data.data ?? []
        this._totalRuns = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchRun(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/payroll/${id}`)

        this._currentRun = data.run ?? data
        this._currentRunTotals = {
          lines_summary: data.lines_summary ?? null,
          calculation_totals: data.calculation_totals ?? null,
        }

        return data
      } finally {
        this._loading = false
      }
    },

    async createRun({ periodStart, periodEnd }) {
      this._loading = true
      try {
        const data = await $api('/company/workforce/payroll', {
          method: 'POST',
          body: { period_start: periodStart, period_end: periodEnd },
        })

        this._runs.unshift(data)
        this._totalRuns++

        return data
      } finally {
        this._loading = false
      }
    },

    async computeRun(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/payroll/${id}/compute`, {
          method: 'POST',
        })

        const idx = this._runs.findIndex(r => r.id === id)
        if (idx !== -1) this._runs[idx] = data
        if (this._currentRun?.id === id) this._currentRun = data

        return data
      } finally {
        this._loading = false
      }
    },

    async computeCalculations(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/payroll/${id}/compute-calculations`, {
          method: 'POST',
        })

        const idx = this._runs.findIndex(r => r.id === id)
        if (idx !== -1) this._runs[idx] = data
        if (this._currentRun?.id === id) this._currentRun = data

        return data
      } finally {
        this._loading = false
      }
    },

    async validateRun(id, validationNote = null) {
      this._loading = true
      try {
        const body = {}

        if (validationNote) body.validation_note = validationNote

        const data = await $api(`/company/workforce/payroll/${id}/validate`, {
          method: 'POST',
          body,
        })

        const idx = this._runs.findIndex(r => r.id === id)
        if (idx !== -1) this._runs[idx] = data
        if (this._currentRun?.id === id) this._currentRun = data

        return data
      } finally {
        this._loading = false
      }
    },

    async exportRun(id, exportFormat = 'json') {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/payroll/${id}/export`, {
          method: 'POST',
          body: { export_format: exportFormat },
        })

        const idx = this._runs.findIndex(r => r.id === id)
        if (idx !== -1) this._runs[idx] = data
        if (this._currentRun?.id === id) this._currentRun = data

        return data
      } finally {
        this._loading = false
      }
    },

    async recomputeRun(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/payroll/${id}/recompute`, {
          method: 'POST',
        })

        const idx = this._runs.findIndex(r => r.id === id)
        if (idx !== -1) this._runs[idx] = data
        if (this._currentRun?.id === id) this._currentRun = data

        return data
      } finally {
        this._loading = false
      }
    },

    async deleteRun(id) {
      this._loading = true
      try {
        await $api(`/company/workforce/payroll/${id}`, {
          method: 'DELETE',
        })

        const idx = this._runs.findIndex(r => r.id === id)
        if (idx !== -1) {
          this._runs.splice(idx, 1)
          this._totalRuns--
        }
      } finally {
        this._loading = false
      }
    },

    // ── Run Lines ──────────────────────────────────────────────
    async fetchLines(runId, { perPage, page } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)

        const qs = params.toString()
        const data = await $api(`/company/workforce/payroll/${runId}/lines${qs ? `?${qs}` : ''}`)

        this._currentRunLines = data.data ?? []
        this._currentRunTotalLines = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Calculations ───────────────────────────────────────────
    async fetchCalculations(runId) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/payroll/${runId}/calculations`)

        this._currentRunCalculations = data.calculations ?? null
        this._currentRunTotals = data.totals ?? null

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Statistics ─────────────────────────────────────────────
    async fetchStatistics() {
      this._loading = true
      try {
        const data = await $api('/company/workforce/payroll/statistics')

        this._statistics = data

        return data
      } finally {
        this._loading = false
      }
    },

    // ── Reset ──────────────────────────────────────────────────
    clearCurrentRun() {
      this._currentRun = null
      this._currentRunLines = []
      this._currentRunCalculations = null
      this._currentRunTotals = null
    },
  },
})
