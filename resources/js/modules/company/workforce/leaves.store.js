import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useLeavesStore = defineStore('workforceLeaves', {
  state: () => ({
    _leaveRequests: [],
    _totalRequests: 0,
    _currentRequest: null,
    _leaveTypes: [],
    _allLeaveTypes: [],
    _balances: [],
    _statistics: null,
    _calendar: [],
    _loading: false,
  }),

  getters: {
    leaveRequests: state => state._leaveRequests,
    totalRequests: state => state._totalRequests,
    currentRequest: state => state._currentRequest,
    leaveTypes: state => state._leaveTypes,
    allLeaveTypes: state => state._allLeaveTypes,
    balances: state => state._balances,
    statistics: state => state._statistics,
    calendar: state => state._calendar,
    loading: state => state._loading,
  },

  actions: {
    // ── Leave Requests ───────────────────────────────────────
    async fetchLeaveRequests({ employeeId, status, from, to, perPage, page } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (employeeId) params.set('employee_id', employeeId)
        if (status) params.set('status', status)
        if (from) params.set('from', from)
        if (to) params.set('to', to)
        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)

        const qs = params.toString()
        const data = await $api(`/workforce/leaves${qs ? `?${qs}` : ''}`)

        this._leaveRequests = data.data ?? []
        this._totalRequests = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchLeaveRequest(id) {
      this._loading = true
      try {
        const data = await $api(`/workforce/leaves/${id}`)

        this._currentRequest = data

        return data
      } finally {
        this._loading = false
      }
    },

    async createLeaveRequest({ employeeId, leaveTypeId, dateFrom, dateTo, daysCountHundredths, reason }) {
      const data = await $api('/workforce/leaves', {
        method: 'POST',
        body: {
          employee_id: employeeId,
          leave_type_id: leaveTypeId,
          date_from: dateFrom,
          date_to: dateTo,
          days_count_hundredths: daysCountHundredths,
          reason,
        },
      })

      this._leaveRequests.unshift(data)
      this._totalRequests++

      return data
    },

    async approveLeaveRequest(id, { reviewNote } = {}) {
      const data = await $api(`/workforce/leaves/${id}/approve`, {
        method: 'POST',
        body: { review_note: reviewNote },
      })

      const idx = this._leaveRequests.findIndex(r => r.id === id)
      if (idx !== -1) this._leaveRequests[idx] = data
      if (this._currentRequest?.id === id) this._currentRequest = data

      return data
    },

    async rejectLeaveRequest(id, { reviewNote } = {}) {
      const data = await $api(`/workforce/leaves/${id}/reject`, {
        method: 'POST',
        body: { review_note: reviewNote },
      })

      const idx = this._leaveRequests.findIndex(r => r.id === id)
      if (idx !== -1) this._leaveRequests[idx] = data
      if (this._currentRequest?.id === id) this._currentRequest = data

      return data
    },

    async cancelLeaveRequest(id, { cancellationReason } = {}) {
      const data = await $api(`/workforce/leaves/${id}/cancel`, {
        method: 'POST',
        body: { cancellation_reason: cancellationReason },
      })

      const idx = this._leaveRequests.findIndex(r => r.id === id)
      if (idx !== -1) this._leaveRequests[idx] = data
      if (this._currentRequest?.id === id) this._currentRequest = data

      return data
    },

    // ── Leave Types ──────────────────────────────────────────
    async fetchLeaveTypes({ all = false, force = false } = {}) {
      const target = all ? '_allLeaveTypes' : '_leaveTypes'
      if (!force && this[target].length > 0) return this[target]

      const data = await $api(`/workforce/leaves/types${all ? '?all=1' : ''}`)
      this[target] = data.data ?? data

      return this[target]
    },

    async createLeaveType(payload) {
      const data = await $api('/workforce/leaves/types', { method: 'POST', body: payload })
      this._allLeaveTypes.push(data)
      this._leaveTypes = [] // invalidate cache

      return data
    },

    async updateLeaveType(id, payload) {
      const data = await $api(`/workforce/leaves/types/${id}`, { method: 'PUT', body: payload })
      const idx = this._allLeaveTypes.findIndex(t => t.id === id)
      if (idx !== -1) this._allLeaveTypes[idx] = data
      this._leaveTypes = [] // invalidate cache

      return data
    },

    async deleteLeaveType(id) {
      await $api(`/workforce/leaves/types/${id}`, { method: 'DELETE' })
      this._allLeaveTypes = this._allLeaveTypes.filter(t => t.id !== id)
      this._leaveTypes = [] // invalidate cache
    },

    // ── Calendar ──────────────────────────────────────────────
    async fetchCalendar({ from, to }) {
      this._loading = true
      try {
        const data = await $api(`/workforce/leaves/calendar?from=${from}&to=${to}`)
        this._calendar = data.data ?? data

        return this._calendar
      } finally {
        this._loading = false
      }
    },

    // ── Balances ─────────────────────────────────────────────
    async fetchBalances(employeeId, { year } = {}) {
      const params = new URLSearchParams()

      if (year) params.set('year', year)

      const qs = params.toString()
      const data = await $api(`/workforce/employees/${employeeId}/leave-balances${qs ? `?${qs}` : ''}`)

      this._balances = data.data ?? data

      return data
    },

    // ── Statistics ───────────────────────────────────────────
    async fetchStatistics({ year } = {}) {
      const params = new URLSearchParams()

      if (year) params.set('year', year)

      const qs = params.toString()
      const data = await $api(`/workforce/leaves/statistics${qs ? `?${qs}` : ''}`)

      this._statistics = data

      return data
    },

    // ── Reset ────────────────────────────────────────────────
    clearCurrentRequest() {
      this._currentRequest = null
    },
  },
})
