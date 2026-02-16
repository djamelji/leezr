/**
 * SPA Runtime — centralized phase machine for store hydration.
 *
 * Phases: cold → auth → tenant → features → ready | error
 * Scopes: 'company', 'platform', 'public'
 *
 * G1: Validated transitions via stateMachine + event journal
 * G2: Per-job AbortController via JobRunner (replaces _loadResource/_resolveResources)
 */

import { defineStore } from 'pinia'
import { companyResources, platformResources } from './resources'
import { abortGroup, abortAll, setActiveGroup } from './abortRegistry'
import { cacheRemove, cacheClear } from './cache'
import { initBroadcast } from './broadcast'
import { transition } from './stateMachine'
import { createJournal } from './journal'
import { JobRunner } from './job'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useJobdomainStore } from '@/core/stores/jobdomain'
import { useModuleStore } from '@/core/stores/module'

// Module-level journal (non-reactive — accessed via getter + version counter)
const _journal = createJournal(200)

// Active JobRunner for progress tracking
let _activeRunner = null

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

    /** @type {number} Boot generation — incremented on each boot/teardown to detect stale boots */
    _bootId: 0,

    /** @type {boolean} */
    _broadcastInitialized: false,

    /** @type {number} Bumped on each journal.log() to trigger reactive reads */
    _journalVersion: 0,
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
      this._journalVersion // reactive dependency (bumped after each job completes)
      if (!_activeRunner) return { total: 0, done: 0, loading: 0, error: 0, pending: 0, cancelled: 0 }

      return _activeRunner.progress
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
    // ─── Boot ──────────────────────────────────────────────

    /**
     * Boot the runtime for a given scope.
     * Called by the router guard on first navigation.
     * @param {'company'|'platform'|'public'} scope
     */
    async boot(scope) {
      if (this._phase !== 'cold') return

      const bootId = ++this._bootId

      this._scope = scope
      _journal.log('run:start', { runId: bootId, scope })
      this._journalVersion++

      // Initialize broadcast (once per app lifecycle)
      this._initBroadcast()

      // Public routes need no hydration
      if (scope === 'public') {
        this._transition('ready')

        return
      }

      const resources = scope === 'platform' ? platformResources : companyResources

      // Phase: auth
      this._transition('auth')
      const authResources = resources.filter(r => r.phase === 'auth')
      const authResult = await this._runJobs(authResources, bootId)
      if (this._bootId !== bootId) return
      if (authResult.critical) {
        this._error = `Failed to load ${authResult.errorKey}.`
        this._transition('error')

        return
      }

      // Check auth — if not logged in, stop (guard will redirect)
      if (scope === 'company') {
        const auth = resolveStore('auth')
        if (!auth.isLoggedIn) return
      }
      else if (scope === 'platform') {
        const platformAuth = resolveStore('platformAuth')
        if (!platformAuth.isLoggedIn) {
          this._transition('ready') // Let the guard handle redirect

          return
        }
      }

      // Phase: tenant (company scope only)
      if (scope === 'company') {
        if (this._bootId !== bootId) return
        this._transition('tenant')
        const tenantResources = resources.filter(r => r.phase === 'tenant')
        const tenantResult = await this._runJobs(tenantResources, bootId)
        if (this._bootId !== bootId) return
        if (tenantResult.critical) {
          this._error = `Failed to load ${tenantResult.errorKey}.`
          this._transition('error')

          return
        }
      }

      // Phase: features (company scope only)
      if (scope === 'company') {
        if (this._bootId !== bootId) return
        this._transition('features')
        const featureResources = resources.filter(r => r.phase === 'features')
        const featureResult = await this._runJobs(featureResources, bootId)
        if (this._bootId !== bootId) return
        if (featureResult.critical) {
          this._error = `Failed to load ${featureResult.errorKey}.`
          this._transition('error')

          return
        }
      }

      if (this._bootId !== bootId) return
      this._transition('ready')
      this._bootedAt = Date.now()
      _journal.log('run:complete', { runId: bootId, scope })
      this._journalVersion++
    },

    // ─── Company switch ────────────────────────────────────

    /**
     * Re-hydrate tenant + features after a company switch.
     * Aborts in-flight requests, clears caches, resets stores, re-runs phases.
     * @param {number|string} companyId
     */
    async switchCompany(companyId) {
      // Cancel active runner jobs
      if (_activeRunner) {
        _activeRunner.cancelAll()
      }

      // Legacy abort as safety net
      abortGroup('tenant')
      abortGroup('features')

      // Clear tenant/features cache
      cacheRemove('auth:companies')
      cacheRemove('tenant:jobdomain')
      cacheRemove('features:modules')

      // Reset tenant/features stores
      const jobdomainStore = resolveStore('jobdomain')
      const moduleStore = resolveStore('module')
      jobdomainStore.reset()
      moduleStore.reset()

      const tenantResources = companyResources.filter(r => r.phase === 'tenant')
      const featureResources = companyResources.filter(r => r.phase === 'features')

      const bootId = ++this._bootId

      _journal.log('run:start', { runId: bootId, scope: 'switch', companyId })
      this._journalVersion++

      this._transition('tenant')

      const tenantResult = await this._runJobs(tenantResources, bootId)
      if (this._bootId !== bootId) return
      if (tenantResult.critical) {
        this._error = `Failed to load ${tenantResult.errorKey}.`
        this._transition('error')

        return
      }

      this._transition('features')
      const featureResult = await this._runJobs(featureResources, bootId)
      if (this._bootId !== bootId) return
      if (featureResult.critical) {
        this._error = `Failed to load ${featureResult.errorKey}.`
        this._transition('error')

        return
      }

      this._transition('ready')
      _journal.log('run:complete', { runId: bootId, scope: 'switch' })
      this._journalVersion++
    },

    // ─── Teardown ──────────────────────────────────────────

    /**
     * Full teardown: abort all, clear cache, reset to cold.
     * Called on logout or before scope switch.
     */
    teardown() {
      const prevScope = this._scope

      // Cancel active runner
      if (_activeRunner) {
        _activeRunner.cancelAll()
        _activeRunner = null
      }

      abortAll()
      cacheClear()
      setActiveGroup(null)

      this._bootId++ // Invalidate any in-progress boot
      if (this._phase !== 'cold') {
        this._transition('cold')
      }
      this._scope = null
      this._resources = {}
      this._error = null
      this._bootedAt = 0
      _journal.log('run:teardown', { scope: prevScope })
      this._journalVersion++
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
        if (payload.companyId && this._scope === 'company') {
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
     * Run a set of resources via JobRunner and sync status back.
     * @param {import('./resources').ResourceDef[]} resources
     * @param {number} bootId - Current boot generation
     * @returns {Promise<{ critical: boolean, errorKey: string|null }>}
     */
    async _runJobs(resources, bootId) {
      const runner = new JobRunner(resources, bootId, {
        resolveStore,
        journal: _journal,
      })

      _activeRunner = runner

      // Sync initial statuses
      this._syncResources(runner)

      const result = await runner.execute()

      // Sync final statuses
      this._syncResources(runner)
      this._journalVersion++ // trigger progress reactivity

      return result
    },

    /**
     * Sync resource statuses from JobRunner into reactive state.
     * @param {JobRunner} runner
     */
    _syncResources(runner) {
      const status = runner.resourceStatus
      for (const [key, value] of Object.entries(status)) {
        this._resources[key] = value
      }
    },

    _initBroadcast() {
      if (this._broadcastInitialized) return
      this._broadcastInitialized = true

      initBroadcast({
        onLogout: () => this.handleBroadcast('logout', {}),
        onCompanySwitch: payload => this.handleBroadcast('company-switch', payload),
        onCacheInvalidate: payload => this.handleBroadcast('cache-invalidate', payload),
      })
    },
  },
})
