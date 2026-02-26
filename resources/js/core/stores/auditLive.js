import { defineStore } from 'pinia'

/**
 * ADR-130: Live audit event ring buffer.
 *
 * Receives real-time audit events via SSE ChannelRouter.
 * Used by the platform audit dashboard for live updates.
 */
export const useAuditLiveStore = defineStore('auditLive', {
  state: () => ({
    _items: [],
  }),

  getters: {
    items: state => state._items,
    count: state => state._items.length,
  },

  actions: {
    /**
     * Push an audit envelope from the realtime channel.
     * Ring buffer: max 200 items, newest first.
     */
    _push(envelope) {
      this._items.unshift({
        id: envelope.id,
        topic: envelope.topic,
        payload: envelope.payload,
        timestamp: envelope.timestamp,
      })

      if (this._items.length > 200) {
        this._items.length = 200
      }
    },

    clear() {
      this._items = []
    },
  },
})
