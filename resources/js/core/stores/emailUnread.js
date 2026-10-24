/**
 * EmailUnreadStore — authoritative unread-message counter for the Platform nav badge (ADR-610).
 *
 * Source of truth = GET /email/inbox/unread-count (received + is_read=false + inbox folder,
 * excluding sent/spam/trash). Initialised at Platform boot (even when the Inbox page is closed)
 * and refreshed on the existing `email.updated` SSE domain event plus local read/move/delete
 * actions. No new realtime engine — it reuses the DomainEventBus.
 */
import { defineStore } from 'pinia'
import { $platformApi } from '@/utils/platformApi'
import { domainEventBus } from '@/core/realtime/DomainEventBus'

export const useEmailUnreadStore = defineStore('emailUnread', {
  state: () => ({
    _count: 0,
    _loaded: false,
    _inFlight: false,   // single-flight guard — never two concurrent fetches (no stale overwrite)
    _pending: false,    // a refresh was requested while a fetch was running → coalesce into one
    _subscribed: false,
  }),

  getters: {
    unreadCount: state => state._count,
    loaded: state => state._loaded,

    /** 0 → undefined (no badge), 1..99 → "N", 100+ → "99+". */
    badgeContent: state => {
      if (state._count <= 0) return undefined
      if (state._count > 99) return '99+'

      return String(state._count)
    },
  },

  actions: {
    /**
     * Refresh the counter. Serialised: a request fired while one is in flight sets `_pending`
     * so exactly one follow-up fetch runs afterwards — this prevents simultaneous refetches and,
     * because only one request is ever in flight, stale/out-of-order responses can't overwrite a
     * newer value.
     */
    async fetch() {
      if (this._inFlight) {
        this._pending = true

        return
      }
      this._inFlight = true
      try {
        do {
          this._pending = false
          const data = await $platformApi('/email/inbox/unread-count')
          this._count = Number(data?.count ?? 0)
          this._loaded = true
        } while (this._pending)
      } catch {
        // Network/permission error → keep the previous value, retry on the next event.
      } finally {
        this._inFlight = false
      }
    },

    /** Call once at Platform boot (only for users who can access messaging). Idempotent. */
    init() {
      if (this._subscribed) return
      this._subscribed = true
      this.fetch()
      // Reuse the existing realtime channel: any inbox change (new IMAP message, read, move,
      // spam, delete, reply, compose) publishes `email.updated` → recount.
      domainEventBus.on('email.updated', () => this.fetch())
    },

    /** Reset on logout / scope teardown. */
    reset() {
      this._count = 0
      this._loaded = false
      this._pending = false
    },
  },
})
