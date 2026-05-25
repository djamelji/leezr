import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useEmployeesStore = defineStore('workforceEmployees', {
  state: () => ({
    _employees: [],
    _totalEmployees: 0,
    _currentEmployee: null,
    _contracts: [],
    _conventions: [],
    _departments: [],
    _jobRoles: [],
    _summary: null,
    _loading: false,
  }),

  getters: {
    employees: state => state._employees,
    totalEmployees: state => state._totalEmployees,
    currentEmployee: state => state._currentEmployee,
    contracts: state => state._contracts,
    conventions: state => state._conventions,
    departments: state => state._departments,
    jobRoles: state => state._jobRoles,
    summary: state => state._summary,
    loading: state => state._loading,

    currentContract: state => {
      if (!state._currentEmployee) return null

      return state._currentEmployee.contracts?.find(c => c.is_current) ?? null
    },

    hasActiveContract: state => {
      if (!state._currentEmployee) return false
      const current = state._currentEmployee.contracts?.find(c => c.is_current)

      return current?.status === 'active'
    },
  },

  actions: {
    // ── Employees CRUD ─────────────────────────────────────
    async fetchEmployees({ search, status, perPage, page, sortBy, sortDir } = {}) {
      this._loading = true
      try {
        const params = new URLSearchParams()

        if (search) params.set('search', search)
        if (status) params.set('status', status)
        if (perPage) params.set('per_page', perPage)
        if (page) params.set('page', page)
        if (sortBy) params.set('sort_by', sortBy)
        if (sortDir) params.set('sort_dir', sortDir)

        const qs = params.toString()
        const data = await $api(`/workforce/employees${qs ? `?${qs}` : ''}`)

        this._employees = data.data ?? []
        this._totalEmployees = data.total ?? 0

        return data
      } finally {
        this._loading = false
      }
    },

    async fetchEmployee(id) {
      this._loading = true
      try {
        const data = await $api(`/workforce/employees/${id}`)

        this._currentEmployee = data

        return data
      } finally {
        this._loading = false
      }
    },

    async createEmployee(payload) {
      const data = await $api('/workforce/employees', {
        method: 'POST',
        body: payload,
      })

      this._employees.push(data)
      this._totalEmployees++

      return data
    },

    async updateEmployee(id, payload) {
      const data = await $api(`/workforce/employees/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._employees.findIndex(e => e.id === id)
      if (idx !== -1) this._employees[idx] = data
      if (this._currentEmployee?.id === id) this._currentEmployee = data

      return data
    },

    async terminateEmployee(id, { reason, termination_date }) {
      const data = await $api(`/workforce/employees/${id}/terminate`, {
        method: 'POST',
        body: { reason, termination_date },
      })

      const idx = this._employees.findIndex(e => e.id === id)
      if (idx !== -1) this._employees[idx] = data
      if (this._currentEmployee?.id === id) this._currentEmployee = data

      return data
    },

    // ── Summary ────────────────────────────────────────────
    async fetchSummary() {
      const data = await $api('/workforce/employees/summary')

      this._summary = data

      return data
    },

    // ── Contracts ──────────────────────────────────────────
    async fetchContracts(employeeId) {
      const data = await $api(`/workforce/employees/${employeeId}/contracts`)

      this._contracts = data

      return data
    },

    async createContract(employeeId, payload) {
      const data = await $api(`/workforce/employees/${employeeId}/contracts`, {
        method: 'POST',
        body: payload,
      })

      this._contracts.unshift(data)
      // Refresh employee to update currentContract
      await this.fetchEmployee(employeeId)

      return data
    },

    async activateContract(employeeId, contractId) {
      const data = await $api(`/workforce/employees/${employeeId}/contracts/${contractId}/activate`, {
        method: 'POST',
      })

      const idx = this._contracts.findIndex(c => c.id === contractId)
      if (idx !== -1) this._contracts[idx] = data

      return data
    },

    // ── Conventions collectives ────────────────────────────
    async fetchConventions() {
      if (this._conventions.length > 0) return this._conventions

      const data = await $api('/workforce/conventions')

      this._conventions = data

      return data
    },

    // ── Departments ────────────────────────────────────────
    async fetchDepartments() {
      const data = await $api('/workforce/departments')

      this._departments = data

      return data
    },

    async createDepartment(payload) {
      const data = await $api('/workforce/departments', {
        method: 'POST',
        body: payload,
      })

      this._departments.push(data)

      return data
    },

    async updateDepartment(id, payload) {
      const data = await $api(`/workforce/departments/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._departments.findIndex(d => d.id === id)
      if (idx !== -1) this._departments[idx] = data

      return data
    },

    async deleteDepartment(id) {
      await $api(`/workforce/departments/${id}`, { method: 'DELETE' })
      this._departments = this._departments.filter(d => d.id !== id)
    },

    // ── Job Roles ────────────────────────────────────────
    async fetchJobRoles() {
      const data = await $api('/workforce/job-roles')

      this._jobRoles = data

      return data
    },

    async createJobRole(payload) {
      const data = await $api('/workforce/job-roles', {
        method: 'POST',
        body: payload,
      })

      this._jobRoles.push(data)

      return data
    },

    async updateJobRole(id, payload) {
      const data = await $api(`/workforce/job-roles/${id}`, {
        method: 'PUT',
        body: payload,
      })

      const idx = this._jobRoles.findIndex(r => r.id === id)
      if (idx !== -1) this._jobRoles[idx] = data

      return data
    },

    async deleteJobRole(id) {
      await $api(`/workforce/job-roles/${id}`, { method: 'DELETE' })
      this._jobRoles = this._jobRoles.filter(r => r.id !== id)
    },

    // ── Reset ──────────────────────────────────────────────
    clearCurrentEmployee() {
      this._currentEmployee = null
      this._contracts = []
    },
  },
})
