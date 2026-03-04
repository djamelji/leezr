import { defineStore } from 'pinia'
import { $api } from '@/utils/api'

/**
 * Compliance store — document request queue for company dashboard widgets.
 *
 * Fetches GET /api/company/document-requests/queue and exposes
 * computed getters consumed by compliance widgets (self-contained).
 */
export const useCompanyComplianceStore = defineStore('companyCompliance', {
  state: () => ({
    _queue: [],
    _loading: false,
  }),

  getters: {
    queue: state => state._queue,
    isLoading: state => state._loading,

    pendingCount: state =>
      state._queue.filter(r => r.status === 'requested').length,

    submittedCount: state =>
      state._queue.filter(r => r.status === 'submitted').length,

    overdueCount: state => {
      const cutoff = Date.now() - 48 * 60 * 60 * 1000

      return state._queue.filter(
        r => r.status === 'requested' && new Date(r.requested_at).getTime() < cutoff,
      ).length
    },

    compliancePercent: state => {
      if (!state._queue.length) return 0
      const submitted = state._queue.filter(r => r.status === 'submitted').length

      return Math.round((submitted / state._queue.length) * 100)
    },

    groupedByRole: state => {
      const groups = {}

      for (const r of state._queue) {
        const key = r.role?.key || '_none'

        if (!groups[key])
          groups[key] = { role: r.role, items: [] }
        groups[key].items.push(r)
      }

      return groups
    },

    groupedByType: state => {
      const groups = {}

      for (const r of state._queue) {
        const code = r.document_type.code

        if (!groups[code])
          groups[code] = { documentType: r.document_type, items: [] }
        groups[code].items.push(r)
      }

      return groups
    },
  },

  actions: {
    async fetchQueue() {
      this._loading = true

      try {
        const data = await $api('/company/document-requests/queue')

        this._queue = data.queue
      }
      finally {
        this._loading = false
      }
    },
  },
})
