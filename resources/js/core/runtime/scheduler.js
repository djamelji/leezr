/**
 * Run Scheduler — single-writer orchestrator for runtime boots.
 *
 * Guarantees: only one run active at a time. New run cancels previous.
 * Replaces the _bootId generation counter with proper run lifecycle.
 *
 * Provides Promise-based coordination: whenAuthResolved(), whenReady().
 */

import { companyResources, platformResources } from './resources'
import { abortGroup, abortAll } from './abortRegistry'
import { cacheRemove, cacheClear } from './cache'
import { JobRunner } from './job'
import { assertRuntime, buildSnapshot } from './invariants'

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
 * @property {Function} getStore         - () => runtime store (for invariant snapshots, DEV only)
 */

export function createScheduler(deps) {
  let _runId = 0
  let _activeRunner = null
  let _currentRunId = null
  let _currentScope = null

  /** @type {{ runId: number, scope: string, requiredPhases: string[], executedPhases: Set<string> }|null} */
  let _runMeta = null

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

    // Only sync if this run is still active (F4: prevent stale runner flash)
    if (!_isStale(runId)) {
      deps.syncResources(runner)
    }

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

  /** DEV-only: assert all invariants at a checkpoint. */
  function _assert(context) {
    if (!import.meta.env.DEV) return

    assertRuntime(
      buildSnapshot(deps.getStore(), { runId: _currentRunId, runMeta: _runMeta }),
      context,
      deps.journal,
    )
  }

  /** Mark a phase as executed in run metadata. */
  function _markPhaseExecuted(phase) {
    if (_runMeta) {
      _runMeta.executedPhases.add(phase)
    }
  }

  /**
   * After a successful retry, complete any remaining boot phases.
   * Determines what phase the active runner handled and executes
   * subsequent phases (e.g., tenant retry → run features → ready).
   */
  async function _continueFromPhase(runId) {
    if (_isStale(runId)) return

    // Platform / public: only auth phase → go straight to ready
    if (_currentScope !== 'company') {
      deps.transition('ready')
      deps.setBootedAt(Date.now())
      deps.journal.log('run:complete', { runId, scope: _currentScope, recovery: true })
      _resolveReady()

      return
    }

    // Company scope: check login before continuing
    const auth = deps.resolveStore('auth')
    if (!auth.isLoggedIn) {
      deps.transition('ready')
      _resolveReady()

      return
    }

    // Determine completed phase from active runner's resources
    const completedPhase = _activeRunner?.jobs[0]?.resource.phase
    // Mark the retried phase as executed (it wasn't marked during the failed boot)
    if (completedPhase) _markPhaseExecuted(completedPhase)

    const phaseOrder = ['auth', 'tenant', 'features']
    const startIdx = phaseOrder.indexOf(completedPhase)
    const remaining = phaseOrder.slice(startIdx + 1)

    for (const phase of remaining) {
      if (_isStale(runId)) return

      deps.transition(phase)

      const phaseResources = companyResources.filter(r => r.phase === phase)
      const result = await _runJobs(phaseResources, runId)
      if (_isStale(runId)) return

      if (result.critical) {
        deps.setError(`Failed to load ${result.errorKey}.`)
        deps.transition('error')

        return
      }
      _markPhaseExecuted(phase)
    }

    if (_isStale(runId)) return
    deps.transition('ready')
    deps.setBootedAt(Date.now())
    deps.journal.log('run:complete', { runId, scope: _currentScope, recovery: true })
    _resolveReady()
    _assert('continueFromPhase:ready')
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

    /** Run metadata (for invariants + debug). */
    get runMeta() {
      return _runMeta
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
      _currentScope = scope
      _resetPromises()

      // Initialize run metadata
      const requiredPhases = scope === 'company'
        ? ['auth', 'tenant', 'features']
        : scope === 'platform' ? ['auth'] : []

      _runMeta = { runId, scope, requiredPhases, executedPhases: new Set() }

      deps.setScope(scope)
      deps.journal.log('run:start', { runId, scope })

      // Public routes need no hydration
      if (scope === 'public') {
        deps.transition('ready')
        _resolveReady()
        _assert('requestBoot:public-ready')

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
        _assert('requestBoot:auth-error')

        return
      }

      _markPhaseExecuted('auth')

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
          _assert('requestBoot:tenant-error')

          return
        }
        _markPhaseExecuted('tenant')
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
          _assert('requestBoot:features-error')

          return
        }
        _markPhaseExecuted('features')
      }

      if (_isStale(runId)) return
      deps.transition('ready')
      deps.setBootedAt(Date.now())
      deps.journal.log('run:complete', { runId, scope })
      _resolveReady()
      _assert('requestBoot:ready')
    },

    /**
     * Full teardown: cancel run, abort all, clear cache, reset to cold.
     */
    requestTeardown() {
      _cancelCurrentRun()
      _currentRunId = null
      _currentScope = null
      _runMeta = null

      abortAll()
      cacheClear()

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

      _runMeta = { runId, scope: 'company', requiredPhases: ['tenant', 'features'], executedPhases: new Set() }

      deps.journal.log('run:start', { runId, scope: 'switch', companyId })

      const tenantResources = companyResources.filter(r => r.phase === 'tenant')
      const featureResources = companyResources.filter(r => r.phase === 'features')

      // Transition to tenant — skip if already there (concurrent switch)
      if (deps.getPhase() !== 'tenant') {
        deps.transition('tenant')
      }

      const tenantResult = await _runJobs(tenantResources, runId)
      if (_isStale(runId)) return
      if (tenantResult.critical) {
        deps.setError(`Failed to load ${tenantResult.errorKey}.`)
        deps.transition('error')

        return
      }
      _markPhaseExecuted('tenant')

      deps.transition('features')
      const featureResult = await _runJobs(featureResources, runId)
      if (_isStale(runId)) return
      if (featureResult.critical) {
        deps.setError(`Failed to load ${featureResult.errorKey}.`)
        deps.transition('error')

        return
      }
      _markPhaseExecuted('features')

      deps.transition('ready')
      deps.journal.log('run:complete', { runId, scope: 'switch' })
      _resolveReady()
      _assert('requestSwitch:ready')
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
     * Retry failed jobs in the active runner, then complete remaining phases.
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
        _assert('retryFailed:error')

        // Phase is already 'error' — no transition needed
        return result
      }

      // Active runner succeeded — complete remaining phases (F2)
      const progress = _activeRunner.progress
      if (progress.error === 0 && progress.loading === 0 && progress.pending === 0) {
        await _continueFromPhase(runId)
      }

      return result
    },
  }
}
