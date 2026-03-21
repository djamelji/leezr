/**
 * NotificationStore — realtime-first notification system.
 *
 * ADR-347: SSE for instant delivery + API for persistence.
 * Receives live events via NotificationHandler._push()
 * and fetches persisted data from the relevant API scope.
 *
 * Supports both company (default) and platform contexts.
 * Call setPlatformMode(true) to switch to platform admin notifications.
 */
import { defineStore } from 'pinia'
import { $api } from '@/utils/api'
import { $platformApi } from '@/utils/platformApi'

const MAX_LOCAL = 100

export const useNotificationStore = defineStore('notification', {
  state: () => ({
    _notifications: [],
    _unreadCount: 0,
    _loading: false,
    _pagination: null,
    _preferences: [],
    _preferencesLoaded: false,
    _availableCategories: [],
    _loaded: false,
    _uuids: new Set(), // dedup SSE by event_uuid
    _platformMode: false,
    _toastQueue: [],
    _toastIdCounter: 0,
  }),

  getters: {
    notifications: state => state._notifications,
    unreadCount: state => state._unreadCount,
    // Badge count excludes toasts still on screen (dot appears only after fly-to-bell)
    badgeUnreadCount: state => {
      const toastCount = state._toastQueue.reduce((sum, t) => sum + (t._count || 1), 0)

      return Math.max(0, state._unreadCount - toastCount)
    },
    loading: state => state._loading,
    recentNotifications: state => state._notifications.slice(0, 10),
    preferences: state => state._preferences,
    preferencesLoaded: state => state._preferencesLoaded,
    availableCategories: state => state._availableCategories,
    pagination: state => state._pagination,
  },

  actions: {
    // Context switching
    setPlatformMode(isPlatform) {
      if (this._platformMode !== isPlatform) {
        this.$reset()
        this._platformMode = isPlatform
      }
    },

    // Internal: resolve API client and path prefix
    _call(path, opts = {}) {
      if (this._platformMode) {
        return $platformApi(`/me/notifications${path}`, opts)
      }

      return $api(`/notifications${path}`, opts)
    },

    // Boot: lightweight count only. Accepts opts to forward _authCheck to API client.
    async fetchUnreadCount(opts = {}) {
      try {
        const data = await this._call('/unread-count', opts)
        const prevCount = this._unreadCount
        const newCount = data.unread_count ?? 0
        const isInitialBoot = !this._loaded

        this._unreadCount = newCount
        this._loaded = true

        // Auto-fetch recent if we have unread but no items loaded yet
        // (covers platform polling where no SSE pushes items)
        if (newCount > 0 && this._notifications.length === 0 && !this._loading) {
          await this.fetchRecent()

          // Only toast on polling-detected increases, NOT on initial boot.
          // Initial boot just loads silently — toasts are for live events only.
          if (!isInitialBoot && newCount > prevCount) {
            const newItems = this._notifications
              .filter(n => !n.read_at)
              .slice(0, newCount - prevCount)

            newItems.forEach(n => this._queueToast(n))
          }
        }
        else if (newCount > prevCount && prevCount > 0) {
          // Count increased — fetch recent to get the new items, then toast them
          const prevIds = new Set(this._notifications.map(n => n.id))

          await this.fetchRecent()

          const newItems = this._notifications.filter(n => !prevIds.has(n.id) && !n.read_at)

          newItems.forEach(n => this._queueToast(n))
        }
      }
      catch (e) {
        console.warn('[notifications] fetchUnreadCount failed', e)

        // Unblock _loaded even on failure so NavBarNotifications watchOnce fires
        if (!this._loaded) this._loaded = true
      }
    },

    // Lazy: fetch recent when navbar dropdown opens
    async fetchRecent() {
      if (this._loading) return
      this._loading = true
      try {
        const data = await this._call('', { params: { per_page: 10 } })
        const items = data.data ?? data ?? []

        // Merge without duplicating SSE-pushed items
        for (const item of items) {
          if (item.event_uuid && this._uuids.has(item.event_uuid)) continue
          if (!this._notifications.find(n => n.id === item.id)) {
            this._notifications.push(item)
          }
          if (item.event_uuid) this._uuids.add(item.event_uuid)
        }

        // Sort by created_at DESC
        this._notifications.sort((a, b) => new Date(b.created_at) - new Date(a.created_at))

        // Trim
        if (this._notifications.length > MAX_LOCAL) {
          this._notifications.splice(MAX_LOCAL)
        }
      }
      catch (e) {
        console.warn('[notifications] fetchRecent failed', e)
      }
      finally {
        this._loading = false
      }
    },

    // Full page with pagination
    async fetchPage(page = 1, filters = {}) {
      this._loading = true
      try {
        const params = { page, per_page: 20, ...filters }
        const data = await this._call('', { params })

        this._notifications = data.data ?? []
        this._pagination = {
          currentPage: data.current_page,
          lastPage: data.last_page,
          total: data.total,
          perPage: data.per_page,
        }

        // Rebuild uuid set
        this._uuids = new Set(this._notifications.filter(n => n.event_uuid).map(n => n.event_uuid))

        // Capture permission-filtered categories — ADR-382
        if (data.available_categories) {
          this._availableCategories = data.available_categories
        }
      }
      catch (e) {
        console.warn('[notifications] fetchPage failed', e)
      }
      finally {
        this._loading = false
      }
    },

    async markRead(id) {
      const n = this._notifications.find(n => n.id === id)
      if (n && !n.read_at) {
        n.read_at = new Date().toISOString()
        this._unreadCount = Math.max(0, this._unreadCount - 1)
      }
      try {
        await this._call(`/${id}/read`, { method: 'POST' })
      }
      catch (e) {
        console.warn('[notifications] markRead failed', e)
      }
    },

    async markAllRead() {
      this._notifications.forEach(n => { n.read_at = n.read_at || new Date().toISOString() })
      this._unreadCount = 0
      try {
        await this._call('/read-all', { method: 'POST' })
      }
      catch (e) {
        console.warn('[notifications] markAllRead failed', e)
      }
    },

    async dismiss(id) {
      const idx = this._notifications.findIndex(n => n.id === id)
      if (idx !== -1) {
        const n = this._notifications[idx]
        if (!n.read_at) this._unreadCount = Math.max(0, this._unreadCount - 1)
        this._notifications.splice(idx, 1)
      }
      try {
        await this._call(`/${id}`, { method: 'DELETE' })
      }
      catch (e) {
        console.warn('[notifications] dismiss failed', e)
      }
    },

    async fetchPreferences() {
      try {
        const data = await this._call('/preferences')

        this._preferences = data.bundles ?? []
        this._availableCategories = data.available_categories ?? []
        this._preferencesLoaded = true
      }
      catch (e) {
        console.warn('[notifications] fetchPreferences failed', e)
      }
    },

    async updatePreferences(bundles) {
      try {
        await this._call('/preferences', { method: 'PUT', body: { bundles } })

        // Update local state
        for (const b of bundles) {
          const existing = this._preferences.find(p => p.category === b.category)
          if (existing) {
            existing.in_app = b.in_app
            existing.email = b.email
          }
        }
      }
      catch (e) {
        console.warn('[notifications] updatePreferences failed', e)
        throw e
      }
    },

    // Toast queue for live notifications — one toast at a time, collapse if multiple
    _queueToast(notification) {
      if (this._toastQueue.length > 0) {
        this._toastQueue[0]._count = (this._toastQueue[0]._count || 1) + 1
      }
      else {
        this._toastQueue.push({
          ...notification,
          _toastId: ++this._toastIdCounter,
          _count: 1,
        })
      }
    },

    _dismissToast(toastId) {
      const idx = this._toastQueue.findIndex(t => t._toastId === toastId)
      if (idx !== -1) this._toastQueue.splice(idx, 1)
    },

    // SSE handler — called by NotificationHandler.dispatch(envelope)
    _push(envelope) {
      const payload = envelope.payload ?? envelope
      const uuid = payload.event_uuid

      // Dedup by event_uuid
      if (uuid && this._uuids.has(uuid)) return
      if (uuid) this._uuids.add(uuid)

      const notification = {
        id: payload.event_id,
        event_uuid: uuid,
        topic_key: payload.topic_key,
        title: payload.title,
        body: payload.body,
        icon: payload.icon,
        severity: payload.severity,
        link: payload.link,
        created_at: payload.created_at || new Date().toISOString(),
        read_at: null,
      }

      // Prepend
      this._notifications.unshift(notification)
      this._unreadCount++

      // Show toast
      this._queueToast(notification)

      // Trim
      if (this._notifications.length > MAX_LOCAL) {
        this._notifications.pop()
      }
    },

    // Reset (on company switch or context change)
    $reset() {
      this._notifications = []
      this._unreadCount = 0
      this._loading = false
      this._pagination = null
      this._preferences = []
      this._preferencesLoaded = false
      this._availableCategories = []
      this._loaded = false
      this._uuids = new Set()
      this._toastQueue = []
      this._toastIdCounter = 0
      // _platformMode is intentionally NOT reset here
    },
  },
})
