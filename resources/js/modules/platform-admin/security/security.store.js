import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformSecurityStore = defineStore('platformSecurity', {
  state: () => ({
    _alerts: [],
    _alertsPagination: { current_page: 1, last_page: 1, total: 0 },
    _alertTypes: {},
    _realtimeStatus: null,
    _realtimeMetrics: null,
    _realtimeConnections: { connections: [], by_company: {}, global_count: 0 },
  }),

  getters: {
    alerts: state => state._alerts,
    alertsPagination: state => state._alertsPagination,
    alertTypes: state => state._alertTypes,
    realtimeStatus: state => state._realtimeStatus,
    realtimeMetrics: state => state._realtimeMetrics,
    realtimeConnections: state => state._realtimeConnections,
    metricsItems: state => {
      if (!state._realtimeMetrics?.events) return []

      return Object.entries(state._realtimeMetrics.events).map(([key, count]) => {
        const raw = key.replace('events_total:', '')
        const separatorIdx = raw.lastIndexOf(':')
        const topic = separatorIdx > 0 ? raw.substring(0, separatorIdx) : raw
        const category = separatorIdx > 0 ? raw.substring(separatorIdx + 1) : ''

        return { topic, category, count }
      })
    },
  },

  actions: {
    async fetchAlerts(params = {}) {
      const data = await $platformApi('/security/alerts', { params })

      this._alerts = data.data
      this._alertsPagination = {
        current_page: data.current_page,
        last_page: data.last_page,
        total: data.total,
      }
    },

    async fetchAlertTypes() {
      const data = await $platformApi('/security/alert-types')

      this._alertTypes = data.alert_types
    },

    async acknowledgeAlert(id) {
      const data = await $platformApi(`/security/alerts/${id}/acknowledge`, {
        method: 'PUT',
      })

      this._updateAlertInList(data.alert)

      return data
    },

    async resolveAlert(id) {
      const data = await $platformApi(`/security/alerts/${id}/resolve`, {
        method: 'PUT',
      })

      this._updateAlertInList(data.alert)

      return data
    },

    async markFalsePositive(id) {
      const data = await $platformApi(`/security/alerts/${id}/false-positive`, {
        method: 'PUT',
      })

      this._updateAlertInList(data.alert)

      return data
    },

    async fetchRealtimeStatus() {
      this._realtimeStatus = await $platformApi('/realtime/status')
    },

    async fetchRealtimeMetrics() {
      this._realtimeMetrics = await $platformApi('/realtime/metrics')
    },

    async fetchRealtimeConnections() {
      this._realtimeConnections = await $platformApi('/realtime/connections')
    },

    async toggleKillSwitch() {
      const active = !this._realtimeStatus?.kill_switch

      await $platformApi('/realtime/kill-switch', { method: 'POST', body: { active } })
      await this.fetchRealtimeStatus()
    },

    async flushRealtimeData() {
      await $platformApi('/realtime/flush', { method: 'POST' })
      await Promise.allSettled([
        this.fetchRealtimeStatus(),
        this.fetchRealtimeMetrics(),
        this.fetchRealtimeConnections(),
      ])
    },

    _updateAlertInList(alert) {
      const idx = this._alerts.findIndex(a => a.id === alert.id)
      if (idx !== -1)
        this._alerts[idx] = alert
    },
  },
})
