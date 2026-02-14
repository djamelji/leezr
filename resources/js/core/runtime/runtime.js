/**
 * SPA Runtime — centralized phase machine for store hydration.
 *
 * Phases: cold → auth → tenant → features → ready | error
 * Scopes: 'company', 'platform', 'public'
 *
 * Replaces scattered hydration in guards, layouts, and pages
 * with a single orchestrated boot sequence.
 */

import { defineStore } from 'pinia'
import { companyResources, platformResources } from './resources'
import { setActiveGroup, abortGroup, abortAll } from './abortRegistry'
import { cacheGet, cacheSet, cacheRemove, cacheClear } from './cache'
import { initBroadcast } from './broadcast'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useJobdomainStore } from '@/core/stores/jobdomain'
import { useModuleStore } from '@/core/stores/module'

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

    /** @type {Array<{ event: string, payload: object, ts: number }>} */
    _broadcastLog: [],
  }),

  getters: {
    phase: state => state._phase,
    scope: state => state._scope,
    error: state => state._error,
    isReady: state => state._phase === 'ready',
    isBooting: state => ['cold', 'auth', 'tenant', 'features'].includes(state._phase),
    resourceStatus: state => state._resources,

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
      this._scope = scope

      // Initialize broadcast (once per app lifecycle)
      this._initBroadcast()

      // Public routes need no hydration
      if (scope === 'public') {
        this._phase = 'ready'

        return
      }

      const resources = scope === 'platform' ? platformResources : companyResources

      // Phase: auth
      this._phase = 'auth'
      const authResources = resources.filter(r => r.phase === 'auth')
      await this._resolveResources(authResources)
      if (this._phase === 'error') return

      // Check auth — if not logged in, stop (guard will redirect)
      if (scope === 'company') {
        const auth = resolveStore('auth')
        if (!auth.isLoggedIn) return
      }
      else if (scope === 'platform') {
        const platformAuth = resolveStore('platformAuth')
        if (!platformAuth.isLoggedIn) {
          this._phase = 'ready' // Let the guard handle redirect

          return
        }
      }

      // Phase: tenant (company scope only)
      if (scope === 'company') {
        this._phase = 'tenant'
        const tenantResources = resources.filter(r => r.phase === 'tenant')
        await this._resolveResources(tenantResources)
        if (this._phase === 'error') return
      }

      // Phase: features (company scope only)
      if (scope === 'company') {
        this._phase = 'features'
        const featureResources = resources.filter(r => r.phase === 'features')
        await this._resolveResources(featureResources)
        if (this._phase === 'error') return
      }

      this._phase = 'ready'
      this._bootedAt = Date.now()
    },

    // ─── Company switch ────────────────────────────────────

    /**
     * Re-hydrate tenant + features after a company switch.
     * Aborts in-flight requests, clears caches, resets stores, re-runs phases.
     * @param {number|string} companyId
     */
    async switchCompany(companyId) {
      // Abort in-flight tenant/features requests
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

      // Update company ID (store already persisted to cookie by auth.switchCompany)
      // Re-hydrate tenant + features
      const tenantResources = companyResources.filter(r => r.phase === 'tenant')
      const featureResources = companyResources.filter(r => r.phase === 'features')

      this._phase = 'tenant'

      // Reset resource statuses for tenant/features
      for (const r of [...tenantResources, ...featureResources]) {
        this._resources[r.key] = 'pending'
      }

      await this._resolveResources(tenantResources)
      if (this._phase === 'error') return

      this._phase = 'features'
      await this._resolveResources(featureResources)
      if (this._phase === 'error') return

      this._phase = 'ready'
    },

    // ─── Teardown ──────────────────────────────────────────

    /**
     * Full teardown: abort all, clear cache, reset to cold.
     * Called on logout or before scope switch.
     */
    teardown() {
      abortAll()
      cacheClear()
      setActiveGroup(null)

      this._phase = 'cold'
      this._scope = null
      this._resources = {}
      this._error = null
      this._bootedAt = 0
    },

    // ─── Broadcast handling ────────────────────────────────

    /**
     * Handle broadcast events from other tabs.
     * @param {string} event
     * @param {Object} payload
     */
    handleBroadcast(event, payload) {
      // Dev log
      if (import.meta.env.DEV) {
        this._broadcastLog.push({ event, payload, ts: Date.now() })
        if (this._broadcastLog.length > 50) {
          this._broadcastLog = this._broadcastLog.slice(-50)
        }
      }

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

    _initBroadcast() {
      if (this._broadcastInitialized) return
      this._broadcastInitialized = true

      initBroadcast({
        onLogout: () => this.handleBroadcast('logout', {}),
        onCompanySwitch: payload => this.handleBroadcast('company-switch', payload),
        onCacheInvalidate: payload => this.handleBroadcast('cache-invalidate', payload),
      })
    },

    /**
     * Resolve a set of resources respecting their dependency order.
     * Resources with satisfied dependencies run in parallel.
     * @param {import('./resources').ResourceDef[]} resources
     */
    async _resolveResources(resources) {
      // Initialize statuses
      for (const r of resources) {
        if (!this._resources[r.key] || this._resources[r.key] === 'error') {
          this._resources[r.key] = 'pending'
        }
      }

      const pending = new Set(resources.map(r => r.key))

      while (pending.size > 0) {
        // Find resources whose dependencies are all resolved
        const runnable = resources.filter(r =>
          pending.has(r.key)
          && r.dependsOn.every(dep => this._resources[dep] === 'done'),
        )

        if (runnable.length === 0) {
          // Deadlock or all dependencies errored
          // Mark remaining non-critical as done (skip), critical as error
          for (const key of pending) {
            const r = resources.find(res => res.key === key)
            if (r?.critical) {
              this._phase = 'error'
              this._error = `Unable to load required data (${r.key}).`

              return
            }
            this._resources[key] = 'done' // Skip non-critical
          }
          break
        }

        // Set abort group for this batch
        const group = runnable[0].abortGroup
        setActiveGroup(group)

        // Run all runnable resources in parallel
        const results = await Promise.allSettled(
          runnable.map(r => this._loadResource(r)),
        )

        setActiveGroup(null)

        // Process results
        for (let i = 0; i < results.length; i++) {
          pending.delete(runnable[i].key)
        }

        // If a critical resource failed, stop
        if (this._phase === 'error') return
      }
    },

    /**
     * Load a single resource: check cache, call store action, update cache.
     * @param {import('./resources').ResourceDef} resource
     */
    async _loadResource(resource) {
      this._resources[resource.key] = 'loading'

      // Check cache (if cacheable and has TTL)
      if (resource.cacheable !== false && resource.ttl > 0) {
        const cached = cacheGet(resource.key, resource.ttl)

        if (cached && !cached.stale) {
          // Fresh cache hit — hydrate store from cache, skip API call
          try {
            const store = resolveStore(resource.store)
            await store[resource.action]({ cached: cached.data })
            this._resources[resource.key] = 'done'

            return
          }
          catch {
            // Cache hydration failed, fall through to normal fetch
          }
        }

        if (cached && cached.stale) {
          // Stale cache — use immediately, refresh in background
          try {
            const store = resolveStore(resource.store)
            await store[resource.action]({ cached: cached.data })
            this._resources[resource.key] = 'done'

            // Background refresh (fire-and-forget)
            this._backgroundRefresh(resource)

            return
          }
          catch {
            // Fall through to normal fetch
          }
        }
      }

      // Normal fetch via store action
      try {
        const store = resolveStore(resource.store)
        const result = await store[resource.action]()

        // Cache the result
        if (resource.cacheable !== false && resource.ttl > 0 && result !== undefined) {
          cacheSet(resource.key, result)
        }

        this._resources[resource.key] = 'done'
      }
      catch (err) {
        // AbortError — request was cancelled, not a real error
        if (err?.name === 'AbortError') {
          this._resources[resource.key] = 'pending'

          return
        }

        this._resources[resource.key] = 'error'

        if (resource.critical) {
          this._phase = 'error'
          this._error = `Failed to load ${resource.key}.`
        }
      }
    },

    /**
     * Background refresh for stale cache entries.
     * @param {import('./resources').ResourceDef} resource
     */
    async _backgroundRefresh(resource) {
      try {
        const store = resolveStore(resource.store)
        const result = await store[resource.action]()

        if (result !== undefined) {
          cacheSet(resource.key, result)
        }
      }
      catch {
        // Background refresh failure is silent
      }
    },
  },
})
