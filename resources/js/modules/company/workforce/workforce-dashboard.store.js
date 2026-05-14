import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useWorkforceDashboardStore = defineStore('workforceDashboard', {
  state: () => ({
    _statistics: null,
    _loading: false,
  }),

  getters: {
    statistics: state => state._statistics,
    loading: state => state._loading,
    employees: state => state._statistics?.employees ?? { total: 0, active: 0, active_contracts: 0 },
    timesheets: state => state._statistics?.timesheets ?? {},
    shifts: state => state._statistics?.shifts ?? {},
    leave: state => state._statistics?.leave ?? {},
    payroll: state => state._statistics?.payroll ?? {},
    dsn: state => state._statistics?.dsn ?? {},
    documents: state => state._statistics?.documents ?? {},
    badges: state => state._statistics?.badges ?? {},
  },

  actions: {
    async fetchStatistics() {
      this._loading = true
      try {
        const { data } = await $api('/workforce/dashboard/statistics')
        this._statistics = data
      }
      finally {
        this._loading = false
      }
    },

    clearStatistics() {
      this._statistics = null
    },
  },
})
