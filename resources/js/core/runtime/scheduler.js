/**
 * Run Scheduler — single-writer orchestrator for runtime boots.
 *
 * Guarantees: only one run active at a time. New run cancels previous.
 * Replaces the _bootId generation counter with proper run lifecycle.
 *
 * Provides Promise-based coordination: whenAuthResolved(), whenReady().
 */

import { companyResources, platformResources } from './resources'
import { abortGroup, abortAll, setActiveGroup } from './abortRegistry'
import { cacheRemove, cacheClear } from './cache'
import { JobRunner } from './job'

/**
 * @typedef {Object} SchedulerDeps
 * @property {Function} resolveStore     - (storeId) => store instance
 * @property {Object}   journal          - { log(type, data) }
 * @property {Function} transition       - runtime._transition(target)
 * @property {Function} getPhase         - () => current phase
 * @property {Function} syncResources    - (runner) => sync to reactive state
 * @property {Function} setError         - (msg) => set runtime._error
 * @property {Function} setScope         - (scope) => set runtime._scope
 * @property {Function} setBootedAt      - (ts) => set runtime._bootedAt
 * @property {Function} resetState       - () => clear _resources, _error, etc.
 */

export function createScheduler(deps) {
  let _runId = 0
  let _activeRunner = null
  let _currentRunId = null

  // Promise coordination
  let _authResolve = null
  let _authPromise = null
  let _readyResolve = null
  let _readyPromise = null

  function _resetPromises() {
    _authPromise = new Promise(r => { _authResolve = r })
    _readyPromise = new Promise(r => { _readyResolve = r })
  }

  function _resolveAuth() {
    if (_authResolve) {
      _authResolve()
      _authResolve = null
    }
  }

  function _resolveReady() {
    _resolveAuth() // auth is always resolved when ready is
    if (_readyResolve) {
      _readyResolve()
      _readyResolve = null
    }
  }

  function _cancelCurrentRun() {
    if (_activeRunner) {
      _activeRunner.cancelAll()
      _activeRunner = null
    }
  }

  async function _runJobs(resources, runId) {
    const runner = new JobRunner(resources, runId, {
      resolveStore: deps.resolveStore,
      journal: deps.journal,
    })

    _activeRunner = runner
    deps.syncResources(runner)

    const result = await runner.execute()

    deps.syncResources(runner)

    return result
  }

  /**
   * Check if the current run is still the active one.
   * @param {number} runId
   * @returns {boolean}
   */
  function _isStale(runId) {
    return runId !== _currentRunId
  }

  return {
    /** Current run ID (for debug). */
    get currentRunId() {
      return _currentRunId
    },

    /** Active JobRunner (for progress). */
    get activeRunner() {
      return _activeRunner
    },

    /**
     * Boot the runtime for a given scope.
     * Cancels any active run before starting.
     * @param {'company'|'platform'|'public'} scope
     */
    async requestBoot(scope) {
      // Cancel any in-flight run
      _cancelCurrentRun()

      const runId = ++_runId

      _currentRunId = runId
      _resetPromises()

      deps.setScope(scope)
      deps.journal.log('run:start', { runId, scope })

      // Public routes need no hydration
      if (scope === 'public') {
        deps.transition('ready')
        _resolveReady()

        return
      }

      const resources = scope === 'platform' ? platformResources : companyResources

      // Phase: auth
      deps.transition('auth')
      const authResources = resources.filter(r => r.phase === 'auth')
      const authResult = await _runJobs(authResources, runId)
      if (_isStale(runId)) return
      if (authResult.critical) {
        deps.setError(`Failed to load ${authResult.errorKey}.`)
        deps.transition('error')
        _resolveAuth() // unblock waiters even on error

        return
      }

      // Signal: auth phase resolved
      _resolveAuth()

      // Check auth — if not logged in, stop (guard will redirect)
      if (scope === 'company') {
        const auth = deps.resolveStore('auth')
        if (!auth.isLoggedIn) {
          _resolveReady()

          return
        }
      }
      else if (scope === 'platform') {
        const platformAuth = deps.resolveStore('platformAuth')
        if (!platformAuth.isLoggedIn) {
          deps.transition('ready')
          _resolveReady()

          return
        }
      }

      // Phase: tenant (company scope only)
      if (scope === 'company') {
        if (_isStale(runId)) return
        deps.transition('tenant')
        const tenantResources = resources.filter(r => r.phase === 'tenant')
        const tenantResult = await _runJobs(tenantResources, runId)
        if (_isStale(runId)) return
        if (tenantResult.critical) {
          deps.setError(`Failed to load ${tenantResult.errorKey}.`)
          deps.transition('error')

          return
        }
      }

      // Phase: features (company scope only)
      if (scope === 'company') {
        if (_isStale(runId)) return
        deps.transition('features')
        const featureResources = resources.filter(r => r.phase === 'features')
        const featureResult = await _runJobs(featureResources, runId)
        if (_isStale(runId)) return
        if (featureResult.critical) {
          deps.setError(`Failed to load ${featureResult.errorKey}.`)
          deps.transition('error')

          return
        }
      }

      if (_isStale(runId)) return
      deps.transition('ready')
      deps.setBootedAt(Date.now())
      deps.journal.log('run:complete', { runId, scope })
      _resolveReady()
    },

    /**
     * Full teardown: cancel run, abort all, clear cache, reset to cold.
     */
    requestTeardown() {
      const prevScope = deps.getPhase() !== 'cold' ? undefined : undefined // unused

      _cancelCurrentRun()
      _currentRunId = null

      abortAll()
      cacheClear()
      setActiveGroup(null)

      if (deps.getPhase() !== 'cold') {
        deps.transition('cold')
      }

      deps.resetState()
      deps.journal.log('run:teardown', {})

      // Resolve any pending waiters (they'll see phase=cold)
      _resolveAuth()
      _resolveReady()
    },

    /**
     * Re-hydrate tenant + features after a company switch.
     * @param {number|string} companyId
     */
    async requestSwitch(companyId) {
      _cancelCurrentRun()

      // Legacy abort as safety net
      abortGroup('tenant')
      abortGroup('features')

      // Clear tenant/features cache
      cacheRemove('auth:companies')
      cacheRemove('tenant:jobdomain')
      cacheRemove('features:modules')

      // Reset tenant/features stores
      const jobdomainStore = deps.resolveStore('jobdomain')
      const moduleStore = deps.resolveStore('module')
      jobdomainStore.reset()
      moduleStore.reset()

      const runId = ++_runId

      _currentRunId = runId
      _resetPromises()
      _resolveAuth() // auth is already resolved during switch

      deps.journal.log('run:start', { runId, scope: 'switch', companyId })

      const tenantResources = companyResources.filter(r => r.phase === 'tenant')
      const featureResources = companyResources.filter(r => r.phase === 'features')

      deps.transition('tenant')

      const tenantResult = await _runJobs(tenantResources, runId)
      if (_isStale(runId)) return
      if (tenantResult.critical) {
        deps.setError(`Failed to load ${tenantResult.errorKey}.`)
        deps.transition('error')

        return
      }

      deps.transition('features')
      const featureResult = await _runJobs(featureResources, runId)
      if (_isStale(runId)) return
      if (featureResult.critical) {
        deps.setError(`Failed to load ${featureResult.errorKey}.`)
        deps.transition('error')

        return
      }

      deps.transition('ready')
      deps.journal.log('run:complete', { runId, scope: 'switch' })
      _resolveReady()
    },

    /**
     * Promise that resolves when the auth phase completes.
     * Safe to call multiple times — returns same promise.
     * @returns {Promise<void>}
     */
    whenAuthResolved() {
      if (!_authPromise) {
        // No boot in progress — resolve immediately
        return Promise.resolve()
      }

      return _authPromise
    },

    /**
     * Promise that resolves when phase reaches 'ready'.
     * @param {number} [timeout] - Optional timeout in ms
     * @returns {Promise<void>}
     */
    whenReady(timeout) {
      if (!_readyPromise) {
        return Promise.resolve()
      }

      if (!timeout) return _readyPromise

      return Promise.race([
        _readyPromise,
        new Promise((_, reject) =>
          setTimeout(() => reject(new Error('Boot timeout')), timeout),
        ),
      ]).catch(() => {
        // Timeout — resolve silently (guard will handle)
      })
    },

    /**
     * Retry failed jobs in the active runner.
     * Stays in error phase during retry — never transits through cold.
     * @returns {Promise<{ critical: boolean, errorKey: string|null }>}
     */
    async retryFailed() {
      if (!_activeRunner) return { critical: false, errorKey: null }

      const runId = _currentRunId

      deps.setError(null)

      // Stay in error phase — do NOT transition to cold
      const result = await _activeRunner.retryFailed()

      if (_isStale(runId)) return { critical: false, errorKey: null }

      deps.syncResources(_activeRunner)

      if (result.critical) {
        deps.setError(`Failed to load ${result.errorKey}.`)

        // Phase is already 'error' — no transition needed
        return result
      }

      // All jobs in active runner succeeded
      const progress = _activeRunner.progress
      if (progress.error === 0 && progress.loading === 0 && progress.pending === 0) {
        deps.transition('ready')
        deps.setBootedAt(Date.now())
        deps.journal.log('run:complete', { runId, recovery: true })
        _resolveReady()
      }

      return result
    },
  }
}
