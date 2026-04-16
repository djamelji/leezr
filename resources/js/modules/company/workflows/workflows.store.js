import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

export const useWorkflowsStore = defineStore('companyWorkflows', {
  state: () => ({
    _rules: [],
    _currentRule: null,
    _logs: [],
    _triggers: {},
    _loading: {
      rules: false,
      currentRule: false,
      logs: false,
      saving: false,
    },
    _pagination: { current_page: 1, last_page: 1, total: 0 },
    _logsPagination: { current_page: 1, last_page: 1, total: 0 },
    _error: null,
  }),

  getters: {
    rules: s => s._rules,
    currentRule: s => s._currentRule,
    logs: s => s._logs,
    triggers: s => s._triggers,
    loading: s => s._loading,
    pagination: s => s._pagination,
    logsPagination: s => s._logsPagination,
    error: s => s._error,

    triggerOptions: s => Object.entries(s._triggers).map(([topic, meta]) => ({
      title: meta.label,
      value: topic,
    })),
  },

  actions: {
    async fetchRules(params = {}) {
      this._loading.rules = true
      this._error = null
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $api(`/workflows?${query}`)

        this._rules = data.data || []
        if (data.triggers) {
          this._triggers = data.triggers
        }
      }
      catch (e) {
        this._error = e.message || 'Failed to fetch workflows'
        this._rules = []
      }
      finally {
        this._loading.rules = false
      }
    },

    async fetchTriggers() {
      try {
        const data = await $api('/workflows/triggers')

        this._triggers = data.data || {}
      }
      catch {
        // Non-blocking
      }
    },

    async fetchRule(id) {
      this._loading.currentRule = true
      this._error = null
      try {
        const data = await $api(`/workflows/${id}`)

        this._currentRule = data.data || data
      }
      catch (e) {
        this._error = e.message || 'Failed to fetch workflow'
        this._currentRule = null
      }
      finally {
        this._loading.currentRule = false
      }
    },

    async fetchLogs(ruleId, params = {}) {
      this._loading.logs = true
      try {
        const query = new URLSearchParams(params).toString()
        const data = await $api(`/workflows/${ruleId}/logs?${query}`)

        this._logs = data.data || []
        this._logsPagination = {
          current_page: data.current_page || 1,
          last_page: data.last_page || 1,
          total: data.total || 0,
        }
      }
      catch {
        this._logs = []
      }
      finally {
        this._loading.logs = false
      }
    },

    async createRule(payload) {
      this._loading.saving = true
      this._error = null
      try {
        const data = await $api('/workflows', {
          method: 'POST',
          body: payload,
        })

        const rule = data.data || data
        this._rules.unshift(rule)

        return rule
      }
      catch (e) {
        this._error = e.message || 'Failed to create workflow'
        throw e
      }
      finally {
        this._loading.saving = false
      }
    },

    async updateRule(id, payload) {
      this._loading.saving = true
      this._error = null
      try {
        const data = await $api(`/workflows/${id}`, {
          method: 'PUT',
          body: payload,
        })

        const updated = data.data || data
        const idx = this._rules.findIndex(r => r.id === id)
        if (idx !== -1) {
          this._rules[idx] = updated
        }
        if (this._currentRule?.id === id) {
          this._currentRule = updated
        }

        return updated
      }
      catch (e) {
        this._error = e.message || 'Failed to update workflow'
        throw e
      }
      finally {
        this._loading.saving = false
      }
    },

    async toggleEnabled(rule) {
      try {
        const data = await $api(`/workflows/${rule.id}`, {
          method: 'PUT',
          body: { enabled: !rule.enabled },
        })

        const updated = data.data || data
        const idx = this._rules.findIndex(r => r.id === rule.id)
        if (idx !== -1) {
          this._rules[idx] = updated
        }

        return updated
      }
      catch (e) {
        this._error = e.message || 'Failed to toggle workflow'
        throw e
      }
    },

    async deleteRule(id) {
      this._loading.saving = true
      try {
        await $api(`/workflows/${id}`, { method: 'DELETE' })
        this._rules = this._rules.filter(r => r.id !== id)
        if (this._currentRule?.id === id) {
          this._currentRule = null
        }
      }
      catch (e) {
        this._error = e.message || 'Failed to delete workflow'
        throw e
      }
      finally {
        this._loading.saving = false
      }
    },
  },
})
