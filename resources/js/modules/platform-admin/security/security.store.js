import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformSecurityStore = defineStore('platformSecurity', {
  state: () => ({
    _alerts: [],
    _alertsPagination: { current_page: 1, last_page: 1, total: 0 },
    _alertTypes: {},
  }),

  getters: {
    alerts: state => state._alerts,
    alertsPagination: state => state._alertsPagination,
    alertTypes: state => state._alertTypes,
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

    _updateAlertInList(alert) {
      const idx = this._alerts.findIndex(a => a.id === alert.id)
      if (idx !== -1)
        this._alerts[idx] = alert
    },
  },
})
