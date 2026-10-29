import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const usePlanningStore = defineStore('workforcePlanning', {
  state: () => ({
    _shifts: [],
    _weekShifts: [],
    _templates: [],
    _allTemplates: [],
    _locations: [],
    _allLocations: [],
    _statistics: null,
    _loading: false,
  }),

  getters: {
    shifts: state => state._shifts,
    weekShifts: state => state._weekShifts,
    templates: state => state._templates,
    allTemplates: state => state._allTemplates,
    locations: state => state._locations,
    allLocations: state => state._allLocations,
    statistics: state => state._statistics,
    loading: state => state._loading,
  },

  actions: {
    // ── Shifts ────────────────────────────────────────────────
    async fetchShifts({ employeeId, status, from, to, perPage, page } = {}) {
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
        const data = await $api(`/workforce/planning/shifts${qs ? `?${qs}` : ''}`)
        this._shifts = data.data ?? []
        return data
      } finally {
        this._loading = false
      }
    },

    // W-G4A : tableau Planning (shifts + absences + conflits), scope + filtres serveur, bornes de dates.
    async fetchBoard({ from, to, departmentId, employeeId, locationId, status } = {}) {
      const params = new URLSearchParams({ from, to })
      if (departmentId) params.set('department_id', departmentId)
      if (employeeId) params.set('employee_id', employeeId)
      if (locationId) params.set('location_id', locationId)
      if (status) params.set('status', status)

      return await $api(`/workforce/planning/shifts/board?${params.toString()}`)
    },

    // W-G4B : suggestions de collaborateurs disponibles pour une (ré)affectation.
    async fetchAvailability({ start, end, excludeShiftId, jobRoleId } = {}) {
      const params = new URLSearchParams({ start, end })
      if (excludeShiftId) params.set('exclude_shift_id', excludeShiftId)
      if (jobRoleId) params.set('job_role_id', jobRoleId)

      return await $api(`/workforce/planning/shifts/availability?${params.toString()}`)
    },

    // W-G4B : premier jour de semaine (préférence Company résolue).
    async fetchWeekStart() {
      return await $api('/workforce/config/week-start')
    },
    async setWeekStart(weekStartDay) {
      return await $api('/workforce/config/week-start', { method: 'PUT', body: { week_start_day: weekStartDay } })
    },

    // W-G4A : comparaison prévu / réalisé (shifts vs pointages).
    async fetchComparison({ from, to, employeeId } = {}) {
      const params = new URLSearchParams({ from, to })
      if (employeeId) params.set('employee_id', employeeId)

      return await $api(`/workforce/planning/shifts/comparison?${params.toString()}`)
    },

    async fetchWeekShifts(date) {
      this._loading = true
      try {
        const data = await $api(`/workforce/planning/shifts/week?date=${date}`)
        this._weekShifts = data.data ?? data
        return this._weekShifts
      } finally {
        this._loading = false
      }
    },

    async fetchStatistics({ from, to } = {}) {
      const params = new URLSearchParams()
      if (from) params.set('from', from)
      if (to) params.set('to', to)
      const qs = params.toString()
      const data = await $api(`/workforce/planning/shifts/statistics${qs ? `?${qs}` : ''}`)
      this._statistics = data
      return data
    },

    async createShift(payload) {
      const data = await $api('/workforce/planning/shifts', { method: 'POST', body: payload })
      this._shifts.unshift(data)
      this._weekShifts = []
      return data
    },

    async updateShift(id, payload) {
      const data = await $api(`/workforce/planning/shifts/${id}`, { method: 'PUT', body: payload })
      const idx = this._shifts.findIndex(s => s.id === id)
      if (idx !== -1) this._shifts[idx] = data
      this._weekShifts = []
      return data
    },

    async deleteShift(id) {
      await $api(`/workforce/planning/shifts/${id}`, { method: 'DELETE' })
      this._shifts = this._shifts.filter(s => s.id !== id)
      this._weekShifts = []
    },

    async publishShifts(shiftIds) {
      const data = await $api('/workforce/planning/shifts/publish', {
        method: 'POST',
        body: { shift_ids: shiftIds },
      })
      this._weekShifts = []
      return data
    },

    async cancelShift(id, reason = null) {
      const data = await $api(`/workforce/planning/shifts/${id}/cancel`, {
        method: 'POST',
        body: { reason },
      })
      const idx = this._shifts.findIndex(s => s.id === id)
      if (idx !== -1) this._shifts[idx] = data
      this._weekShifts = []
      return data
    },

    async bulkCreateShifts(payload) {
      const data = await $api('/workforce/planning/shifts/bulk', { method: 'POST', body: payload })
      this._weekShifts = []
      return data
    },

    // ── Templates ─────────────────────────────────────────────
    async fetchTemplates({ all = false, force = false } = {}) {
      const target = all ? '_allTemplates' : '_templates'
      if (!force && this[target].length > 0) return this[target]
      const data = await $api(`/workforce/planning/templates${all ? '?all=1' : ''}`)
      this[target] = data.data ?? data
      return this[target]
    },

    async createTemplate(payload) {
      const data = await $api('/workforce/planning/templates', { method: 'POST', body: payload })
      this._allTemplates.push(data)
      this._templates = []
      return data
    },

    async updateTemplate(id, payload) {
      const data = await $api(`/workforce/planning/templates/${id}`, { method: 'PUT', body: payload })
      const idx = this._allTemplates.findIndex(t => t.id === id)
      if (idx !== -1) this._allTemplates[idx] = data
      this._templates = []
      return data
    },

    async deleteTemplate(id) {
      await $api(`/workforce/planning/templates/${id}`, { method: 'DELETE' })
      this._allTemplates = this._allTemplates.filter(t => t.id !== id)
      this._templates = []
    },

    // ── Locations ─────────────────────────────────────────────
    async fetchLocations({ all = false, force = false } = {}) {
      const target = all ? '_allLocations' : '_locations'
      if (!force && this[target].length > 0) return this[target]
      const data = await $api(`/workforce/planning/locations${all ? '?all=1' : ''}`)
      this[target] = data.data ?? data
      return this[target]
    },

    async createLocation(payload) {
      const data = await $api('/workforce/planning/locations', { method: 'POST', body: payload })
      this._allLocations.push(data)
      this._locations = []
      return data
    },

    async updateLocation(id, payload) {
      const data = await $api(`/workforce/planning/locations/${id}`, { method: 'PUT', body: payload })
      const idx = this._allLocations.findIndex(l => l.id === id)
      if (idx !== -1) this._allLocations[idx] = data
      this._locations = []
      return data
    },

    async deleteLocation(id) {
      await $api(`/workforce/planning/locations/${id}`, { method: 'DELETE' })
      this._allLocations = this._allLocations.filter(l => l.id !== id)
      this._locations = []
    },
  },
})
