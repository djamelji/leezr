import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformAuditStore = defineStore('platformAudit', {
  state: () => ({
    _platformLogs: [],
    _platformLogsPagination: { current_page: 1, last_page: 1, total: 0 },
    _companyLogs: [],
    _companyLogsPagination: { current_page: 1, last_page: 1, total: 0 },
    _actions: [],
  }),

  getters: {
    platformLogs: state => state._platformLogs,
    platformLogsPagination: state => state._platformLogsPagination,
    companyLogs: state => state._companyLogs,
    companyLogsPagination: state => state._companyLogsPagination,
    actions: state => state._actions,
  },

  actions: {
    async fetchPlatformLogs(params = {}) {
      const data = await $platformApi('/audit/platform', { params })

      this._platformLogs = data.data
      this._platformLogsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    async fetchCompanyLogs(params = {}) {
      const data = await $platformApi('/audit/companies', { params })

      this._companyLogs = data.data
      this._companyLogsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    async fetchActions() {
      const data = await $platformApi('/audit/actions')

      this._actions = data.actions
    },
  },
})
