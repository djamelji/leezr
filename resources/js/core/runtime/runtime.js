/**
 * SPA Runtime — centralized phase machine for store hydration.
 *
 * Phases: cold → auth → tenant → features → ready | error
 * Scopes: 'company', 'platform', 'public'
 *
 * G1: Validated transitions via stateMachine + event journal
 * G2: Per-job AbortController via JobRunner
 * G3: Single-writer scheduler (replaces _bootId with RunManager)
 */

import { defineStore } from 'pinia'
import { cacheRemove } from './cache'
import { initBroadcast } from './broadcast'
import { transition } from './stateMachine'
import { createJournal } from './journal'
import { createScheduler } from './scheduler'
import { buildSnapshot } from './invariants'
import { bootMachine } from './bootMachine'
import { createRealtimeClient } from '@/core/realtime/RealtimeClient'
import { createChannelRouter } from '@/core/realtime/ChannelRouter'
import { domainEventBus } from '@/core/realtime/DomainEventBus'
import { createNotificationHandler } from '@/core/realtime/handlers/NotificationHandler'
import { createAuditHandler } from '@/core/realtime/handlers/AuditHandler'
import { createSecurityHandler } from '@/core/realtime/handlers/SecurityHandler'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useJobdomainStore } from '@/modules/company/jobdomain/jobdomain.store'
import { useModuleStore } from '@/core/stores/module'
import { useNavStore } from '@/core/stores/nav'
import { useNotificationStore } from '@/core/stores/notification'

// Module-level journal (non-reactive — accessed via getter + version counter)
const _journal = createJournal(200)

// Scheduler — initialized lazily after store is created
let _scheduler = null

// Visibility + polling refresh (fallback when SSE unavailable)
let _visibilityInitialized = false
let _lastHiddenAt = 0
let _pollTimer = null
let _platformNotifPollTimer = null
const NAV_POLL_MS = 30_000 // 30 seconds

// ADR-125: SSE realtime client
let _realtimeClient = null
let _realtimeActive = false

// ADR-126: Channel router (domain events route through DomainEventBus singleton)
let _channelRouter = null

// Store factory map — keyed by store id from resource declarations
const storeFactories = {
  auth: useAuthStore,
  platformAuth: usePlatformAuthStore,
  jobdomain: useJobdomainStore,
  module: useModuleStore,
  nav: useNavStore,
  notification: useNotificationStore,
  // auditLive and securityAlert stores will be registered in Phase 3 and 4
}

function resolveStore(storeId) {
  const factory = storeFactories[storeId]
  if (!factory) throw new Error(`[runtime] Unknown store: ${storeId}`)

  return factory()
}

// Re-export for external consumers (guards, layouts, AppShellGate)
export { bootMachine }

export const useRuntimeStore = defineStore('runtime', {
  state: () => ({
    /** @type {'cold'|'auth'|'tenant'|'features'|'ready'|'error'} */
    _phase: 'cold',

    /** @type {'company'|'platform'|'public'|null} */
    _scope: null,

    /** @type {Object<string, 'pending'|'loading'|'done'|'error'>} */
    _resources: {},

    /** @type {string|null} */
    _error: null,

    /** @type {number} */
    _bootedAt: 0,

    /** @type {boolean} */
    _broadcastInitialized: false,

    /** @type {number} Bumped on each journal.log() to trigger reactive reads */
    _journalVersion: 0,

    /** @type {number|null} Last known session TTL (seconds) from cross-tab sync */
    _sessionTTL: null,

    /** @type {number} Timestamp of last TTL sync (for cross-tab composable resync) */
    _sessionTTLSyncedAt: 0,
  }),

  getters: {
    phase: state => state._phase,
    scope: state => state._scope,
    error: state => state._error,

    /** Boot machine state — SINGLE source of truth for ready/booting/failed. */
    bootState: () => bootMachine.state.value,
    isReady: () => bootMachine.isReady.value,
    isBooting: () => bootMachine.isBooting.value,
    isFailed: () => bootMachine.isFailed.value,
    resourceStatus: state => state._resources,

    /** Read journal entries (touch _journalVersion for reactivity). */
    journalEntries() {
      // eslint-disable-next-line no-unused-expressions
      this._journalVersion // reactive dependency
      return _journal.entries()
    },

    /** Progress from the active JobRunner. */
    progress() {
      // eslint-disable-next-line no-unused-expressions
      this._journalVersion // reactive dependency
      const runner = _scheduler?.activeRunner
      if (!runner) return { total: 0, done: 0, loading: 0, error: 0, pending: 0, cancelled: 0 }

      return runner.progress
    },

    /** Current run ID (for debug). */
    currentRunId() {
      return _scheduler?.currentRunId ?? null
    },

    phaseMessage: state => {
      const messages = {
        cold: 'Initializing...',
        auth: 'Verifying your session...',
        tenant: 'Loading your workspace...',
        features: 'Preparing your tools...',
        ready: '',
        error: state._error || 'Something went wrong.',
      }

      return messages[state._phase] || ''
    },
  },

  actions: {
    // ─── Public API (delegates to scheduler) ────────────────

    /**
     * Boot the runtime for a given scope.
     * Called by the router guard on first navigation.
     *
     * Idempotent via bootMachine:
     * - Already READY for same scope → returns immediately (no-op)
     * - Already BOOTING for same scope → returns existing promise (dedup)
     * - Otherwise → starts new boot and returns promise
     *
     * @param {'company'|'platform'|'public'} scope
     * @returns {Promise<void>}
     */
    async boot(scope) {
      const promise = bootMachine.prepareBoot(scope)

      // Already ready for this scope — no-op
      if (promise === null) return

      this._ensureScheduler()
      this._initBroadcast()
      this._initVisibilityRefresh()

      // Fire scheduler (drives internal phases + calls onCommit/onFail)
      _scheduler.requestBoot(scope)

      // Await the bootMachine promise — resolves on commit() or fail()
      await promise

      // Post-boot: non-critical hydration
      if (bootMachine.isReady.value && scope === 'company') {
        // ADR-125: Start SSE for realtime events
        this._initRealtime()

        // ADR-347: Fetch notification unread count for navbar badge (non-blocking)
        const notifStore = resolveStore('notification')

        notifStore.setPlatformMode(false)
        notifStore.fetchUnreadCount().catch(() => {})
      }

      if (bootMachine.isReady.value && scope === 'platform') {
        const notifStore = resolveStore('notification')

        notifStore.setPlatformMode(true)
        notifStore.fetchUnreadCount().catch(() => {})

        // Platform has no SSE stream — poll every 15s for notifications
        this._startPlatformNotifPoll()
      }
    },

    /**
     * Full teardown: abort all, clear cache, reset to cold.
     * Called on logout or before scope switch.
     */
    teardown() {
      this._disconnectRealtime()
      this._stopNavPoll()
      this._stopPlatformNotifPoll()
      this._ensureScheduler()
      _scheduler.requestTeardown()
      bootMachine.teardown()
    },

    /**
     * Re-hydrate tenant + features after a company switch.
     * @param {number|string} companyId
     */
    async switchCompany(companyId) {
      // ADR-125: Disconnect SSE before switch (company channel changes)
      this._disconnectRealtime()

      // Force reboot via bootMachine (same scope, force=true)
      const promise = bootMachine.prepareBoot('company', true)

      this._ensureScheduler()
      _scheduler.requestSwitch(companyId)

      // Await bootMachine promise
      if (promise) await promise

      // Reset notification state for the new company + reconnect SSE
      if (bootMachine.isReady.value) {
        const notifStore = resolveStore('notification')

        notifStore.$reset()
        this._initRealtime()
        notifStore.fetchUnreadCount().catch(() => {})
      }
    },

    /**
     * Promise that resolves when the auth phase completes.
     * @returns {Promise<void>}
     */
    whenAuthResolved() {
      this._ensureScheduler()

      return _scheduler.whenAuthResolved()
    },

    /**
     * Promise that resolves when phase reaches 'ready'.
     * @param {number} [timeout] - Optional timeout in ms
     * @returns {Promise<void>}
     */
    whenReady(timeout) {
      this._ensureScheduler()

      return _scheduler.whenReady(timeout)
    },

    /**
     * Retry failed jobs in the active runner.
     * @returns {Promise<{ critical: boolean, errorKey: string|null }>}
     */
    async retryFailed() {
      // Prepare bootMachine for retry (force reboot from FAILED state)
      const scope = bootMachine.scope.value || this._scope || 'company'
      const promise = bootMachine.prepareBoot(scope, true)

      this._ensureScheduler()
      const result = await _scheduler.retryFailed()

      // If the scheduler's retry didn't resolve the bootMachine (e.g., still failing),
      // the _failBoot() or _commitReady() in scheduler handles it.
      // But if retryFailed completed with pending promise, await it.
      if (promise) await promise

      return result
    },

    // ─── Broadcast handling ────────────────────────────────

    /**
     * Handle broadcast events from other tabs.
     * @param {string} event
     * @param {Object} payload
     */
    handleBroadcast(event, payload) {
      _journal.log('broadcast:in', { event, payload })
      this._journalVersion++

      switch (event) {
      case 'logout':
        this.teardown()

        // Reset auth stores
        try {
          const auth = resolveStore('auth')
          auth._persistUser(null)
          auth._companies = []
          auth._persistCompanyId(null)
          auth._hydrated = false
        }
        catch { /* store may not be initialized */ }

        try {
          const platformAuth = resolveStore('platformAuth')
          platformAuth._persistUser(null)
          platformAuth._persistRoles([])
          platformAuth._persistPermissions([])
          platformAuth._hydrated = false
        }
        catch { /* store may not be initialized */ }

        // Redirect to login
        if (typeof window !== 'undefined') {
          const isPlat = window.location.pathname.startsWith('/platform')
          window.location.href = isPlat ? '/platform/login' : '/login'
        }
        break

      case 'company-switch':
        if (payload.companyId && this._scope === 'company' && this._phase === 'ready') {
          const auth = resolveStore('auth')
          auth._persistCompanyId(payload.companyId)
          this.switchCompany(payload.companyId)
        }
        break

      case 'cache-invalidate':
        if (Array.isArray(payload.keys)) {
          payload.keys.forEach(k => cacheRemove(k))
        }
        break

      case 'session-extended':
        this._sessionTTL = payload.ttl ?? null
        this._sessionTTLSyncedAt = Date.now()
        break

      case 'session-expired':
        this.teardown()
        try { resolveStore('auth')._persistUser(null) } catch {}
        try { resolveStore('platformAuth')._persistUser(null) } catch {}
        if (typeof window !== 'undefined') {
          const isPlat = window.location.pathname.startsWith('/platform')
          window.location.href = isPlat ? '/platform/login' : '/login'
        }
        break
      }
    },

    // ─── Internal ──────────────────────────────────────────

    /**
     * Validated phase transition with journal logging.
     * @param {string} target - Target phase
     * @returns {string} The resulting phase
     */
    _transition(target) {
      const from = this._phase
      const result = transition(from, target)

      if (result !== from) {
        this._phase = result
        _journal.log('phase:transition', { from, to: result })
        this._journalVersion++
      }

      return result
    },

    /** Clear journal entries. */
    clearJournal() {
      _journal.clear()
      this._journalVersion++
    },

    /**
     * Get a JSON-serializable snapshot of the full runtime state.
     * Includes phase, scope, resources, progress, journal, and run metadata.
     * @returns {Object}
     */
    getSnapshot() {
      return {
        ...buildSnapshot(this, {
          runId: _scheduler?.currentRunId ?? null,
          runMeta: _scheduler?.runMeta ?? null,
        }),
        bootedAt: this._bootedAt,
        journal: _journal.toJSON(),
      }
    },

    /** Lazily create the scheduler (needs `this` reference). */
    _ensureScheduler() {
      if (_scheduler) return

      const self = this

      _scheduler = createScheduler({
        resolveStore,
        journal: _journal,
        transition: target => self._transition(target),
        getPhase: () => self._phase,
        getStore: () => self,
        setScope: scope => { self._scope = scope },
        setError: msg => { self._error = msg },
        setBootedAt: ts => { self._bootedAt = ts },
        resetState: () => {
          self._scope = null
          self._resources = {}
          self._error = null
          self._bootedAt = 0
        },
        syncResources: runner => {
          const status = runner.resourceStatus
          for (const [key, value] of Object.entries(status)) {
            self._resources[key] = value
          }
          self._journalVersion++ // trigger progress reactivity
        },
        onCommit: () => bootMachine.commit(),
        onFail: msg => bootMachine.fail(msg),
      })
    },

    _initBroadcast() {
      if (this._broadcastInitialized) return
      this._broadcastInitialized = true

      initBroadcast({
        onLogout: () => this.handleBroadcast('logout', {}),
        onCompanySwitch: payload => this.handleBroadcast('company-switch', payload),
        onCacheInvalidate: payload => this.handleBroadcast('cache-invalidate', payload),
        onSessionExtended: payload => this.handleBroadcast('session-extended', payload),
        onSessionExpired: payload => this.handleBroadcast('session-expired', payload),
      })
    },

    /**
     * Realtime RBAC refresh (sans websockets).
     *
     * Two mechanisms ensure Alice sees permission changes without logout:
     *
     * 1) VISIBILITY — when tab becomes visible after >10s hidden,
     *    invalidate cache and refetch immediately.
     *
     * 2) POLLING — every 30s while tab is visible + company scope + ready,
     *    background-refetch nav + companies. Covers split-screen / same-tab
     *    scenarios where visibilitychange never fires.
     *
     * Polling is paused when tab is hidden (saves network).
     */
    _initVisibilityRefresh() {
      if (_visibilityInitialized || typeof document === 'undefined') return
      _visibilityInitialized = true

      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
          _lastHiddenAt = Date.now()
          this._stopNavPoll()

          return
        }

        // Tab became visible — immediate refresh if hidden long enough
        const hiddenMs = _lastHiddenAt ? Date.now() - _lastHiddenAt : 0

        if (hiddenMs > 10_000 && this._scope === 'company' && this._phase === 'ready') {
          this._refreshNavAndAuth()
        }

        // Restart polling
        this._startNavPoll()
      })

      // Start polling on init (tab is visible at boot time)
      this._startNavPoll()
    },

    /** Invalidate cache + background-refetch nav and auth companies. */
    _refreshNavAndAuth() {
      cacheRemove('features:nav')
      cacheRemove('auth:companies')

      const navStore = resolveStore('nav')
      const authStore = resolveStore('auth')

      Promise.all([
        navStore.fetchCompanyNav(),
        authStore.fetchMyCompanies(),
      ]).catch(() => {})
    },

    _startNavPoll() {
      // Skip polling when SSE is active (ADR-125)
      if (_realtimeActive) return

      this._stopNavPoll()
      _pollTimer = setInterval(() => {
        if (this._scope === 'company' && this._phase === 'ready') {
          this._refreshNavAndAuth()
        }
      }, NAV_POLL_MS)
    },

    _stopNavPoll() {
      if (_pollTimer) {
        clearInterval(_pollTimer)
        _pollTimer = null
      }
    },

    // Platform notification polling (no SSE available for platform scope)
    // Uses recursive setTimeout to prevent request accumulation.
    _startPlatformNotifPoll() {
      this._stopPlatformNotifPoll()

      const poll = async () => {
        // Stop if scope changed or runtime no longer ready
        if (bootMachine.scope.value !== 'platform' || !bootMachine.isReady.value) {
          _platformNotifPollTimer = null

          return
        }

        try {
          await resolveStore('notification').fetchUnreadCount({ _authCheck: true })
        }
        catch { /* logged inside store */ }

        // Schedule next tick only after completion (no accumulation)
        _platformNotifPollTimer = setTimeout(poll, 30_000)
      }

      // First tick in 30s (boot already called fetchUnreadCount)
      _platformNotifPollTimer = setTimeout(poll, 30_000)
    },

    _stopPlatformNotifPoll() {
      if (_platformNotifPollTimer) {
        clearTimeout(_platformNotifPollTimer)
        _platformNotifPollTimer = null
      }
    },

    // ─── ADR-125: SSE Realtime ────────────────────────────

    /**
     * Connect the SSE realtime client for the current company.
     * ADR-126: Creates ChannelRouter to dispatch events by category.
     * Stops polling when SSE is connected; falls back to polling on failure.
     */
    _initRealtime() {
      if (_realtimeClient || typeof EventSource === 'undefined') return

      const authStore = resolveStore('auth')
      const companyId = authStore.currentCompanyId

      if (!companyId) return

      // ADR-126 + ADR-427: Channel router — domain events go through global DomainEventBus
      const notificationHandler = createNotificationHandler(() => {
        try { return storeFactories.notification?.() ?? null } catch { return null }
      })

      const auditHandler = createAuditHandler(() => {
        try { return storeFactories.auditLive?.() ?? null } catch { return null }
      })

      const securityHandler = createSecurityHandler(() => null) // Placeholder — store created in Phase 4

      _channelRouter = createChannelRouter({
        invalidation: data => this._handleRealtimeInvalidation(data.invalidates || []),
        domain: data => domainEventBus.dispatch(data),
        notification: data => notificationHandler.dispatch(data),
        audit: data => auditHandler.dispatch(data),
        security: data => securityHandler.dispatch(data),
      })

      _realtimeClient = createRealtimeClient({
        companyId,
        onEvent: (sseEventType, data) => _channelRouter.dispatch(sseEventType, data),
        onInvalidate: keys => this._handleRealtimeInvalidation(keys),
        onConnected: () => {
          _realtimeActive = true
          this._stopNavPoll() // SSE replaces polling
          _journal.log('realtime:connected', { companyId })
          this._journalVersion++
        },
        onFallback: () => {
          _realtimeActive = false
          _journal.log('realtime:fallback', { reason: 'SSE failed — activating polling' })
          this._journalVersion++
          this._startNavPoll()
        },
      })

      _realtimeClient.connect()
    },

    /**
     * Disconnect and cleanup the SSE client.
     */
    _disconnectRealtime() {
      if (_realtimeClient) {
        _realtimeClient.disconnect()
        _realtimeClient = null
      }
      _realtimeActive = false
      _channelRouter = null
      domainEventBus.clear()
    },

    /**
     * Handle invalidation events from SSE.
     * Clears specified cache keys and refetches affected stores.
     * @param {string[]} keys - Cache keys to invalidate
     */
    _handleRealtimeInvalidation(keys) {
      if (this._scope !== 'company' || this._phase !== 'ready') return

      _journal.log('realtime:invalidate', { keys })
      this._journalVersion++

      // Invalidate cache keys
      keys.forEach(k => cacheRemove(k))

      // Refetch affected stores
      const promises = []

      if (keys.includes('features:nav')) {
        promises.push(resolveStore('nav').fetchCompanyNav())
      }
      if (keys.includes('auth:companies')) {
        promises.push(resolveStore('auth').fetchMyCompanies())
      }
      if (keys.includes('features:modules')) {
        promises.push(resolveStore('module').fetchModules())
      }
      if (keys.includes('tenant:jobdomain')) {
        promises.push(resolveStore('jobdomain').fetchJobdomain())
      }

      if (promises.length) {
        Promise.all(promises).catch(() => {})
      }
    },
  },
})
