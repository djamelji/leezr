import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformRealtimeStore = defineStore('platformRealtime', {
  state: () => ({
    _realtimeStatus: null,
    _realtimeMetrics: null,
    _realtimeConnections: { connections: [], by_company: {}, global_count: 0 },
  }),

  getters: {
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
  },
})
