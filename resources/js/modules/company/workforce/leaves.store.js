import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useLeavesStore = defineStore('workforceLeaves', {
  state: () => ({
    _leaveRequests: [],
    _totalRequests: 0,
    _currentRequest: null,
    _leaveTypes: [],
    _balances: [],
    _statistics: null,
    _loading: false,
  }),

  getters: {
    leaveRequests: state => state._leaveRequests,
    totalRequests: state => state._totalRequests,
    currentRequest: state => state._currentRequest,
    leaveTypes: state => state._leaveTypes,
    balances: state => state._balances,
    statistics: state => state._statistics,
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
        const data = await $api(`/company/workforce/leaves${qs ? `?${qs}` : ''}`)

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
        const data = await $api(`/company/workforce/leaves/${id}`)

        this._currentRequest = data

        return data
      } finally {
        this._loading = false
      }
    },

    async createLeaveRequest({ employeeId, leaveTypeId, dateFrom, dateTo, daysCountHundredths, reason }) {
      const data = await $api('/company/workforce/leaves', {
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
      const data = await $api(`/company/workforce/leaves/${id}/approve`, {
        method: 'POST',
        body: { review_note: reviewNote },
      })

      const idx = this._leaveRequests.findIndex(r => r.id === id)
      if (idx !== -1) this._leaveRequests[idx] = data
      if (this._currentRequest?.id === id) this._currentRequest = data

      return data
    },

    async rejectLeaveRequest(id, { reviewNote } = {}) {
      const data = await $api(`/company/workforce/leaves/${id}/reject`, {
        method: 'POST',
        body: { review_note: reviewNote },
      })

      const idx = this._leaveRequests.findIndex(r => r.id === id)
      if (idx !== -1) this._leaveRequests[idx] = data
      if (this._currentRequest?.id === id) this._currentRequest = data

      return data
    },

    async cancelLeaveRequest(id, { cancellationReason } = {}) {
      const data = await $api(`/company/workforce/leaves/${id}/cancel`, {
        method: 'POST',
        body: { cancellation_reason: cancellationReason },
      })

      const idx = this._leaveRequests.findIndex(r => r.id === id)
      if (idx !== -1) this._leaveRequests[idx] = data
      if (this._currentRequest?.id === id) this._currentRequest = data

      return data
    },

    // ── Leave Types (cached) ─────────────────────────────────
    async fetchLeaveTypes() {
      if (this._leaveTypes.length > 0) return this._leaveTypes

      const data = await $api('/company/workforce/leaves/types')

      this._leaveTypes = data.data ?? data

      return this._leaveTypes
    },

    // ── Balances ─────────────────────────────────────────────
    async fetchBalances(employeeId, { year } = {}) {
      const params = new URLSearchParams()

      if (year) params.set('year', year)

      const qs = params.toString()
      const data = await $api(`/company/workforce/employees/${employeeId}/leave-balances${qs ? `?${qs}` : ''}`)

      this._balances = data.data ?? data

      return data
    },

    // ── Statistics ───────────────────────────────────────────
    async fetchStatistics({ year } = {}) {
      const params = new URLSearchParams()

      if (year) params.set('year', year)

      const qs = params.toString()
      const data = await $api(`/company/workforce/leaves/statistics${qs ? `?${qs}` : ''}`)

      this._statistics = data

      return data
    },

    // ── Reset ────────────────────────────────────────────────
    clearCurrentRequest() {
      this._currentRequest = null
    },
  },
})
