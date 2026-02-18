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
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useJobdomainStore } from '@/core/stores/jobdomain'
import { useModuleStore } from '@/core/stores/module'

// Module-level journal (non-reactive — accessed via getter + version counter)
const _journal = createJournal(200)

// Scheduler — initialized lazily after store is created
let _scheduler = null

// Store factory map — keyed by store id from resource declarations
const storeFactories = {
  auth: useAuthStore,
  platformAuth: usePlatformAuthStore,
  jobdomain: useJobdomainStore,
  module: useModuleStore,
}

function resolveStore(storeId) {
  const factory = storeFactories[storeId]
  if (!factory) throw new Error(`[runtime] Unknown store: ${storeId}`)

  return factory()
}

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
    isReady: state => state._phase === 'ready',
    isBooting: state => ['cold', 'auth', 'tenant', 'features'].includes(state._phase),
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
     * @param {'company'|'platform'|'public'} scope
     */
    async boot(scope) {
      if (this._phase !== 'cold') return

      this._ensureScheduler()
      this._initBroadcast()
      await _scheduler.requestBoot(scope)
    },

    /**
     * Full teardown: abort all, clear cache, reset to cold.
     * Called on logout or before scope switch.
     */
    teardown() {
      this._ensureScheduler()
      _scheduler.requestTeardown()
    },

    /**
     * Re-hydrate tenant + features after a company switch.
     * @param {number|string} companyId
     */
    async switchCompany(companyId) {
      this._ensureScheduler()
      await _scheduler.requestSwitch(companyId)
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
      this._ensureScheduler()

      return _scheduler.retryFailed()
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
  },
})
