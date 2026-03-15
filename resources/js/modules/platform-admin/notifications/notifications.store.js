/**
 * PlatformNotificationStore — notification topic governance.
 * ADR-347: Platform admin manages topics, channels, and delivery.
 */
import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'

export const usePlatformNotificationStore = defineStore('platformNotification', {
  state: () => ({
    _topics: [],
    _loading: false,
  }),

  getters: {
    topics: state => state._topics,
    loading: state => state._loading,
  },

  actions: {
    async fetchTopics() {
      this._loading = true
      try {
        const data = await $platformApi('/notifications/topics')

        this._topics = data.topics ?? []
      }
      catch (e) {
        console.warn('[platform:notifications] fetchTopics failed', e)
      }
      finally {
        this._loading = false
      }
    },

    async updateTopic(key, topicData) {
      try {
        const data = await $platformApi(`/notifications/topics/${key}`, {
          method: 'PUT',
          body: topicData,
        })

        const idx = this._topics.findIndex(t => t.key === key)
        if (idx !== -1) this._topics[idx] = data.topic

        return data.topic
      }
      catch (e) {
        console.warn('[platform:notifications] updateTopic failed', e)
        throw e
      }
    },

    async toggleTopic(key) {
      try {
        const data = await $platformApi(`/notifications/topics/${key}/toggle`, {
          method: 'PUT',
        })

        const idx = this._topics.findIndex(t => t.key === key)
        if (idx !== -1) this._topics[idx] = data.topic

        return data.topic
      }
      catch (e) {
        console.warn('[platform:notifications] toggleTopic failed', e)
        throw e
      }
    },
  },
})
