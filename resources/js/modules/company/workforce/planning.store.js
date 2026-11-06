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
    _coverage: [],
    _coverageCategories: [],
    _activityPresets: [],
    _weekTemplates: [],
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
    coverage: state => state._coverage,
    coverageCategories: state => state._coverageCategories,
    activityPresets: state => state._activityPresets,
    weekTemplates: state => state._weekTemplates,
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

    // ADR-720 — application groupée de modèle : prévisualisation (classification + sureffactif cumulatif).
    async bulkApplyTemplatePreview({ templateId, cells }) {
      return await $api('/workforce/planning/shifts/bulk-template/preview', {
        method: 'POST',
        body: { schedule_template_id: templateId, cells },
      })
    },
    // ADR-720 — commit atomique (brouillons) ; confirmation sureffectif via ack serveur.
    async bulkApplyTemplate({ templateId, cells, ack = null, reason = null }) {
      return await $api('/workforce/planning/shifts/bulk-template', {
        method: 'POST',
        body: { schedule_template_id: templateId, cells, overstaffing_ack: ack, overstaffing_reason: reason },
      })
    },

    // ADR-722 — annuler / rétablir une opération (commande inverse serveur).
    async undoOperation(id, { ack = null, reason = null } = {}) {
      return await $api(`/workforce/planning/operations/${id}/undo`, { method: 'POST', body: { overstaffing_ack: ack, overstaffing_reason: reason } })
    },
    async redoOperation(id, { ack = null, reason = null } = {}) {
      return await $api(`/workforce/planning/operations/${id}/redo`, { method: 'POST', body: { overstaffing_ack: ack, overstaffing_reason: reason } })
    },

    // ADR-721 — copie de shifts (opérations explicites source→cible) : prévisualisation + commit.
    async copyShiftsPreview(operations) {
      return await $api('/workforce/planning/shifts/copy/preview', { method: 'POST', body: { operations } })
    },
    async copyShifts({ operations, ack = null, reason = null }) {
      return await $api('/workforce/planning/shifts/copy', { method: 'POST', body: { operations, overstaffing_ack: ack, overstaffing_reason: reason } })
    },
    // ADR-721 — copie de SEMAINE (décalage jour, 1er jour Company) : prévisualisation + commit.
    async copyWeekPreview(payload) {
      return await $api('/workforce/planning/shifts/copy-week/preview', { method: 'POST', body: payload })
    },
    async copyWeekApply(payload) {
      return await $api('/workforce/planning/shifts/copy-week/apply', { method: 'POST', body: payload })
    },

    // ADR-717 — candidats au remplacement d'un shift impacté par une absence (fenêtre non assurée incluse).
    async fetchReplacementCandidates(shiftId) {
      return await $api(`/workforce/planning/shifts/${shiftId}/replacement-candidates`)
    },
    // ADR-717 — crée + publie le shift de remplacement sur la fenêtre choisie.
    async replaceShift(shiftId, { employeeId, startAt, endAt }) {
      return await $api(`/workforce/planning/shifts/${shiftId}/replace`, {
        method: 'POST',
        body: { employee_id: employeeId, start_at: startAt, end_at: endAt },
      })
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

    // SP1a.1 — copie de semaine (nouveaux brouillons) et duplication d'un shift.
    async copyWeek({ fromWeekStart, toWeekStart }) {
      const data = await $api('/workforce/planning/shifts/copy-week', {
        method: 'POST',
        body: { from_week_start: fromWeekStart, to_week_start: toWeekStart },
      })
      this._weekShifts = []
      return data
    },

    async duplicateShift(id, payload = {}) {
      const data = await $api(`/workforce/planning/shifts/${id}/duplicate`, { method: 'POST', body: payload })
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

    // ── Coverage (besoins de personnel) — Smart Planning SP1a ──
    async fetchCoverage() {
      const data = await $api('/workforce/planning/coverage')
      this._coverage = data.data ?? data
      return this._coverage
    },

    async fetchCoverageSummary({ from, to } = {}) {
      const params = new URLSearchParams({ from, to })
      return await $api(`/workforce/planning/coverage/summary?${params.toString()}`)
    },

    async createCoverage(payload) {
      const data = await $api('/workforce/planning/coverage', { method: 'POST', body: payload })
      this._coverage.push(data)
      return data
    },

    async updateCoverage(id, payload) {
      const data = await $api(`/workforce/planning/coverage/${id}`, { method: 'PUT', body: payload })
      const idx = this._coverage.findIndex(c => c.id === id)
      if (idx !== -1) this._coverage[idx] = data
      return data
    },

    async deleteCoverage(id) {
      await $api(`/workforce/planning/coverage/${id}`, { method: 'DELETE' })
      this._coverage = this._coverage.filter(c => c.id !== id)
    },

    async replicateCoverage(requirementId, days) {
      return await $api('/workforce/planning/coverage/replicate', { method: 'POST', body: { requirement_id: requirementId, days } })
    },

    // ── Coverage categories (catalogue) — Lot A ──
    async fetchCoverageCategories() {
      const data = await $api('/workforce/planning/coverage-categories')
      this._coverageCategories = data.data ?? data
      return this._coverageCategories
    },
    async createCoverageCategory(payload) {
      const data = await $api('/workforce/planning/coverage-categories', { method: 'POST', body: payload })
      this._coverageCategories.push(data)
      return data
    },
    async updateCoverageCategory(id, payload) {
      const data = await $api(`/workforce/planning/coverage-categories/${id}`, { method: 'PUT', body: payload })
      const idx = this._coverageCategories.findIndex(c => c.id === id)
      if (idx !== -1) this._coverageCategories[idx] = data
      return data
    },
    async deleteCoverageCategory(id) {
      await $api(`/workforce/planning/coverage-categories/${id}`, { method: 'DELETE' })
      this._coverageCategories = this._coverageCategories.filter(c => c.id !== id)
    },

    // ── Activity presets (catalogue de tâches types) — M2 (ADR-725) ──
    async fetchActivityPresets() {
      const data = await $api('/workforce/planning/activity-presets')
      this._activityPresets = data.data ?? data
      return this._activityPresets
    },
    async createActivityPreset(payload) {
      const data = await $api('/workforce/planning/activity-presets', { method: 'POST', body: payload })
      this._activityPresets.push(data)
      return data
    },
    async updateActivityPreset(id, payload) {
      const data = await $api(`/workforce/planning/activity-presets/${id}`, { method: 'PUT', body: payload })
      const idx = this._activityPresets.findIndex(p => p.id === id)
      if (idx !== -1) this._activityPresets[idx] = data
      return data
    },
    async deleteActivityPreset(id) {
      await $api(`/workforce/planning/activity-presets/${id}`, { method: 'DELETE' })
      this._activityPresets = this._activityPresets.filter(p => p.id !== id)
    },

    // ── Week templates (semaines types) — M3 (ADR-726) ──
    async fetchWeekTemplates() {
      const data = await $api('/workforce/planning/week-templates')
      this._weekTemplates = data.data ?? data
      return this._weekTemplates
    },
    async createWeekTemplate(payload) {
      const data = await $api('/workforce/planning/week-templates', { method: 'POST', body: payload })
      this._weekTemplates.push(data)
      return data
    },
    async updateWeekTemplate(id, payload) {
      const data = await $api(`/workforce/planning/week-templates/${id}`, { method: 'PUT', body: payload })
      const idx = this._weekTemplates.findIndex(w => w.id === id)
      if (idx !== -1) this._weekTemplates[idx] = data
      return data
    },
    async deleteWeekTemplate(id) {
      await $api(`/workforce/planning/week-templates/${id}`, { method: 'DELETE' })
      this._weekTemplates = this._weekTemplates.filter(w => w.id !== id)
    },
    async captureWeek(payload) {
      const data = await $api('/workforce/planning/week-templates/capture', { method: 'POST', body: payload })
      this._weekTemplates.push(data)
      return data
    },
    async previewWeekTemplate(id, targetDate) {
      return await $api(`/workforce/planning/week-templates/${id}/preview`, { method: 'POST', body: { target_date: targetDate } })
    },
    async applyWeekTemplate(id, payload) {
      return await $api(`/workforce/planning/week-templates/${id}/apply`, { method: 'POST', body: payload })
    },
    async repeatPreviewWeekTemplate(id, payload) {
      return await $api(`/workforce/planning/week-templates/${id}/repeat-preview`, { method: 'POST', body: payload })
    },
    async repeatApplyWeekTemplate(id, payload) {
      return await $api(`/workforce/planning/week-templates/${id}/repeat-apply`, { method: 'POST', body: payload })
    },

    // ── Scheduled activities (multi-activités) — Smart Planning SP1b ──
    async fetchActivities({ shiftId, employeeId, date } = {}) {
      const params = new URLSearchParams()
      if (shiftId) params.set('shift_id', shiftId)
      if (employeeId) params.set('employee_id', employeeId)
      if (date) params.set('date', date)
      const qs = params.toString()

      return await $api(`/workforce/planning/activities${qs ? `?${qs}` : ''}`)
    },

    async createActivity(payload) {
      this._weekShifts = []

      return await $api('/workforce/planning/activities', { method: 'POST', body: payload })
    },

    async updateActivity(id, payload) {
      this._weekShifts = []

      return await $api(`/workforce/planning/activities/${id}`, { method: 'PUT', body: payload })
    },

    async cancelActivity(id) {
      this._weekShifts = []

      return await $api(`/workforce/planning/activities/${id}/cancel`, { method: 'POST' })
    },

    async duplicateActivity(id, payload = {}) {
      this._weekShifts = []

      return await $api(`/workforce/planning/activities/${id}/duplicate`, { method: 'POST', body: payload })
    },

    async deleteActivity(id) {
      this._weekShifts = []
      await $api(`/workforce/planning/activities/${id}`, { method: 'DELETE' })
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
