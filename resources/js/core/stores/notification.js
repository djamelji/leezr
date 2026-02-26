/**
 * NotificationStore — ring buffer for realtime notifications.
 *
 * ADR-126: Receives notification envelopes from the SSE channel
 * router and exposes them to the UI with read/dismiss management.
 */

import { defineStore } from 'pinia'

const MAX_NOTIFICATIONS = 100

export const useNotificationStore = defineStore('notification', {
  state: () => ({
    /** @type {Array<{id: string, topic: string, payload: Object, timestamp: number, read: boolean}>} */
    _items: [],
  }),

  getters: {
    notifications: state => state._items,

    unreadCount: state => state._items.filter(n => !n.read).length,
  },

  actions: {
    /**
     * Push a new notification envelope (called by NotificationHandler).
     * @param {Object} envelope - SSE event envelope
     */
    _push(envelope) {
      this._items.unshift({
        id: envelope.id || `n_${Date.now()}`,
        topic: envelope.topic,
        payload: envelope.payload || {},
        timestamp: envelope.timestamp || Date.now() / 1000,
        read: false,
      })

      // Ring buffer: trim oldest
      if (this._items.length > MAX_NOTIFICATIONS) {
        this._items = this._items.slice(0, MAX_NOTIFICATIONS)
      }
    },

    markRead(id) {
      const item = this._items.find(n => n.id === id)
      if (item) item.read = true
    },

    markAllRead() {
      this._items.forEach(n => { n.read = true })
    },

    dismiss(id) {
      this._items = this._items.filter(n => n.id !== id)
    },
  },
})
