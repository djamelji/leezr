import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useTimesheetsStore = defineStore('workforceTimesheets', {
  state: () => ({
    _periods: [],
    _totalPeriods: 0,
    _currentPeriod: null,
    _statistics: null,
    _timeEntries: [],
    _activeEntries: [],
    _loading: false,
  }),

  getters: {
    periods: state => state._periods,
    totalPeriods: state => state._totalPeriods,
    currentPeriod: state => state._currentPeriod,
    statistics: state => state._statistics,
    timeEntries: state => state._timeEntries,
    activeEntries: state => state._activeEntries,
    loading: state => state._loading,
  },

  actions: {
    // ── Timesheets ───────────────────────────────────────────
    async fetchTimesheets({ periodStart, periodEnd, status, perPage, page } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (periodStart) params.set('period_start', periodStart)
        if (periodEnd) params.set('period_end', periodEnd)
        if (status) params.set('status', status)
        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)

        const qs = params.toString()
        const data = await $api(`/company/workforce/timesheets${qs ? `?${qs}` : ''}`)

        this._periods = data.data ?? []
        this._totalPeriods = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchTimesheet(id) {
      this._loading = true
      try {
        const data = await $api(`/company/workforce/timesheets/${id}`)

        this._currentPeriod = data

        return data
      } finally {
        this._loading = false
      }
    },

    async generateTimesheet({ employeeId, periodStart, periodEnd }) {
      const data = await $api('/company/workforce/timesheets', {
        method: 'POST',
        body: { employee_id: employeeId, period_start: periodStart, period_end: periodEnd },
      })

      this._periods.unshift(data)
      this._totalPeriods++

      return data
    },

    async submitTimesheet(id) {
      const data = await $api(`/company/workforce/timesheets/${id}/submit`, {
        method: 'POST',
      })

      const idx = this._periods.findIndex(p => p.id === id)
      if (idx !== -1) this._periods[idx] = data
      if (this._currentPeriod?.id === id) this._currentPeriod = data

      return data
    },

    async approveTimesheet(id, { approvalNote } = {}) {
      const data = await $api(`/company/workforce/timesheets/${id}/approve`, {
        method: 'POST',
        body: { approval_note: approvalNote },
      })

      const idx = this._periods.findIndex(p => p.id === id)
      if (idx !== -1) this._periods[idx] = data
      if (this._currentPeriod?.id === id) this._currentPeriod = data

      return data
    },

    async rejectTimesheet(id, { rejectionReason }) {
      const data = await $api(`/company/workforce/timesheets/${id}/reject`, {
        method: 'POST',
        body: { rejection_reason: rejectionReason },
      })

      const idx = this._periods.findIndex(p => p.id === id)
      if (idx !== -1) this._periods[idx] = data
      if (this._currentPeriod?.id === id) this._currentPeriod = data

      return data
    },

    async reopenTimesheet(id) {
      const data = await $api(`/company/workforce/timesheets/${id}/reopen`, {
        method: 'POST',
      })

      const idx = this._periods.findIndex(p => p.id === id)
      if (idx !== -1) this._periods[idx] = data
      if (this._currentPeriod?.id === id) this._currentPeriod = data

      return data
    },

    async lockTimesheet(id) {
      const data = await $api(`/company/workforce/timesheets/${id}/lock`, {
        method: 'POST',
      })

      const idx = this._periods.findIndex(p => p.id === id)
      if (idx !== -1) this._periods[idx] = data
      if (this._currentPeriod?.id === id) this._currentPeriod = data

      return data
    },

    async fetchStatistics({ periodStart, periodEnd }) {
      const params = new URLSearchParams()

      if (periodStart) params.set('period_start', periodStart)
      if (periodEnd) params.set('period_end', periodEnd)

      const qs = params.toString()
      const data = await $api(`/company/workforce/timesheets/statistics${qs ? `?${qs}` : ''}`)

      this._statistics = data

      return data
    },

    // ── Time Entries ─────────────────────────────────────────
    async fetchTimeEntries({ employeeId, from, to }) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (employeeId) params.set('employee_id', employeeId)
        if (from) params.set('from', from)
        if (to) params.set('to', to)

        const qs = params.toString()
        const data = await $api(`/company/workforce/time-entries${qs ? `?${qs}` : ''}`)

        this._timeEntries = data.data ?? data

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchActiveEntries() {
      const data = await $api('/company/workforce/time-entries/active')

      this._activeEntries = data.data ?? data

      return data
    },

    async clockIn({ employeeId, source }) {
      const data = await $api('/company/workforce/time-entries/clock-in', {
        method: 'POST',
        body: { employee_id: employeeId, source },
      })

      this._activeEntries.push(data)

      return data
    },

    async clockOut(id) {
      const data = await $api(`/company/workforce/time-entries/${id}/clock-out`, {
        method: 'POST',
      })

      const idx = this._activeEntries.findIndex(e => e.id === id)
      if (idx !== -1) this._activeEntries.splice(idx, 1)

      return data
    },

    async startBreak(id, { type }) {
      const data = await $api(`/company/workforce/time-entries/${id}/break/start`, {
        method: 'POST',
        body: { type },
      })

      const idx = this._activeEntries.findIndex(e => e.id === id)
      if (idx !== -1) this._activeEntries[idx] = data

      return data
    },

    async endBreak(id) {
      const data = await $api(`/company/workforce/time-entries/${id}/break/end`, {
        method: 'POST',
      })

      const idx = this._activeEntries.findIndex(e => e.id === id)
      if (idx !== -1) this._activeEntries[idx] = data

      return data
    },

    async createManualEntry({ employeeId, date, clockIn, clockOut }) {
      const data = await $api('/company/workforce/time-entries', {
        method: 'POST',
        body: { employee_id: employeeId, date, clock_in: clockIn, clock_out: clockOut },
      })

      this._timeEntries.push(data)

      return data
    },

    // ── Reset ────────────────────────────────────────────────
    clearCurrentPeriod() {
      this._currentPeriod = null
    },
  },
})
