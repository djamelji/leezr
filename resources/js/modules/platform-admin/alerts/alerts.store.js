import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformAlertStore = defineStore('platformAlerts', {
  state: () => ({
    _alerts: [],
    _kpis: { active_critical: 0, active_total: 0, resolved_24h: 0 },
    _pagination: { current_page: 1, last_page: 1, total: 0 },
    _loading: false,
    _lastParams: {},

    // Badge counts for nav
    _badgeCount: 0,
    _badgeCritical: 0,
  }),

  getters: {
    alerts: s => s._alerts,
    kpis: s => s._kpis,
    pagination: s => s._pagination,
    loading: s => s._loading,
    badgeCount: s => s._badgeCount,
    badgeCritical: s => s._badgeCritical,
  },

  actions: {
    async fetchAlerts(params = {}) {
      this._loading = true
      this._lastParams = params

      try {
        const query = new URLSearchParams(
          Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v !== '')),
        ).toString()

        const data = await $platformApi(`/alerts?${query}`)

        this._alerts = data.alerts.data
        this._pagination = {
          current_page: data.alerts.current_page,
          last_page: data.alerts.last_page,
          total: data.alerts.total,
        }
        this._kpis = data.kpis
      }
      finally {
        this._loading = false
      }
    },

    async fetchBadgeCount() {
      const data = await $platformApi('/alerts/count')

      this._badgeCount = data.count
      this._badgeCritical = data.critical
    },

    async acknowledge(id) {
      await $platformApi(`/alerts/${id}/acknowledge`, { method: 'PUT' })
      await this.fetchAlerts(this._lastParams)
    },

    async resolve(id) {
      await $platformApi(`/alerts/${id}/resolve`, { method: 'PUT' })
      await this.fetchAlerts(this._lastParams)
    },

    async dismiss(id) {
      await $platformApi(`/alerts/${id}/dismiss`, { method: 'PUT' })
      await this.fetchAlerts(this._lastParams)
    },
  },
})
