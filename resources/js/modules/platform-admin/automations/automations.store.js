import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformAutomationsStore = defineStore('platformAutomations', {
  state: () => ({
    _rules: [],
    _logs: [],
    _loading: false,
    _runningRuleId: null,
    _logsPagination: { current_page: 1, last_page: 1, total: 0 },
  }),

  getters: {
    rules: s => s._rules,
    logs: s => s._logs,
    loading: s => s._loading,
    runningRuleId: s => s._runningRuleId,
    logsPagination: s => s._logsPagination,
  },

  actions: {
    async fetchRules() {
      this._loading = true
      try {
        const data = await $platformApi('/automations')

        this._rules = data.data
      }
      finally {
        this._loading = false
      }
    },

    async updateRule(id, payload) {
      const data = await $platformApi(`/automations/${id}`, {
        method: 'PUT',
        body: payload,
      })

      // Replace updated rule in the list
      const idx = this._rules.findIndex(r => r.id === id)
      if (idx !== -1) {
        this._rules[idx] = data.data
      }

      return data
    },

    async runRule(id) {
      this._runningRuleId = id
      try {
        const data = await $platformApi(`/automations/${id}/run`, {
          method: 'POST',
        })

        // Replace updated rule in the list
        const idx = this._rules.findIndex(r => r.id === id)
        if (idx !== -1) {
          this._rules[idx] = data.data
        }

        return data
      }
      finally {
        this._runningRuleId = null
      }
    },

    async fetchLogs(id, page = 1) {
      const data = await $platformApi(`/automations/${id}/logs?page=${page}`)

      this._logs = data.data
      this._logsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },
  },
})
