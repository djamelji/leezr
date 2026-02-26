import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useCompanyAuditStore = defineStore('companyAudit', {
  state: () => ({
    _logs: [],
    _pagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    logs: state => state._logs,
    pagination: state => state._pagination,
  },

  actions: {
    async fetchLogs(params = {}) {
      const data = await $api('/audit', { params })

      this._logs = data.data
      this._pagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },
  },
})
