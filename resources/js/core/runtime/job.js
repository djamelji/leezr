/**
 * Job System — per-resource execution with individual AbortController.
 *
 * Each resource becomes a Job with its own abort signal.
 * JobRunner orchestrates dependency-ordered parallel execution.
 */

import { cacheGet, cacheSet } from './cache'

// ─── Job ─────────────────────────────────────────────

/**
 * @typedef {Object} JobDeps
 * @property {Function} resolveStore - (storeId) => store instance
 * @property {Function} journal      - { log(type, data) }
 */

export class Job {
  /**
   * @param {import('./resources').ResourceDef} resource
   * @param {number} runId
   */
  constructor(resource, runId) {
    this.key = resource.key
    this.runId = runId
    this.resource = resource
    /** @type {'pending'|'running'|'done'|'error'|'cancelled'} */
    this.status = 'pending'
    this.controller = new AbortController()
    this.error = null
    this.duration = 0
  }

  get signal() {
    return this.controller.signal
  }

  /**
   * Execute this job: check cache, call store action, cache result.
   * @param {JobDeps} deps
   * @returns {Promise<void>}
   */
  async run(deps) {
    if (this.status === 'cancelled') return

    this.status = 'running'
    this.error = null
    const start = Date.now()

    deps.journal.log('job:start', { key: this.key, runId: this.runId })

    // Check cache (SWR)
    if (this.resource.cacheable !== false && this.resource.ttl > 0) {
      const cached = cacheGet(this.key, this.resource.ttl)

      if (cached && !cached.stale) {
        try {
          const store = deps.resolveStore(this.resource.store)
          await store[this.resource.action]({ cached: cached.data, signal: this.signal })
          this.status = 'done'
          this.duration = Date.now() - start
          deps.journal.log('job:cache-hit', { key: this.key, runId: this.runId, stale: false })

          return
        }
        catch {
          // Cache hydration failed, fall through
        }
      }

      if (cached && cached.stale) {
        try {
          const store = deps.resolveStore(this.resource.store)
          await store[this.resource.action]({ cached: cached.data, signal: this.signal })
          this.status = 'done'
          this.duration = Date.now() - start
          deps.journal.log('job:cache-hit', { key: this.key, runId: this.runId, stale: true })

          // Background refresh (fire-and-forget)
          this._backgroundRefresh(deps)

          return
        }
        catch {
          // Fall through to API call
        }
      }
    }

    // API call
    try {
      const store = deps.resolveStore(this.resource.store)
      const result = await store[this.resource.action]({ signal: this.signal })

      if (this.resource.cacheable !== false && this.resource.ttl > 0 && result !== undefined) {
        cacheSet(this.key, result)
      }

      this.status = 'done'
      this.duration = Date.now() - start
      deps.journal.log('job:done', { key: this.key, runId: this.runId, duration: this.duration })
    }
    catch (err) {
      if (err?.name === 'AbortError' || this.status === 'cancelled') {
        this.status = 'cancelled'
        deps.journal.log('job:cancel', { key: this.key, runId: this.runId })

        return
      }

      this.status = 'error'
      this.error = err
      this.duration = Date.now() - start
      deps.journal.log('job:error', { key: this.key, runId: this.runId, message: err?.message })
    }
  }

  cancel() {
    if (this.status === 'running' || this.status === 'pending') {
      this.controller.abort()
      this.status = 'cancelled'
    }
  }

  /** @private */
  async _backgroundRefresh(deps) {
    try {
      const store = deps.resolveStore(this.resource.store)
      const result = await store[this.resource.action]({})

      if (result !== undefined) {
        cacheSet(this.key, result)
      }
    }
    catch {
      // Background refresh failure is silent
    }
  }
}

// ─── JobRunner ───────────────────────────────────────

export class JobRunner {
  /**
   * @param {import('./resources').ResourceDef[]} resources
   * @param {number} runId
   * @param {JobDeps} deps
   */
  constructor(resources, runId, deps) {
    this.runId = runId
    this.deps = deps
    this.jobs = resources.map(r => new Job(r, runId))
    this._jobMap = new Map(this.jobs.map(j => [j.key, j]))
  }

  /**
   * Progress summary.
   * @returns {{ total: number, done: number, loading: number, error: number, pending: number, cancelled: number }}
   */
  get progress() {
    const counts = { total: this.jobs.length, done: 0, loading: 0, error: 0, pending: 0, cancelled: 0 }
    for (const j of this.jobs) {
      if (j.status === 'done') counts.done++
      else if (j.status === 'running') counts.loading++
      else if (j.status === 'error') counts.error++
      else if (j.status === 'cancelled') counts.cancelled++
      else counts.pending++
    }

    return counts
  }

  /**
   * Resource status map (compatible with runtime._resources).
   * @returns {Object<string, string>}
   */
  get resourceStatus() {
    const status = {}
    for (const j of this.jobs) {
      status[j.key] = j.status === 'running' ? 'loading' : j.status
    }

    return status
  }

  /**
   * Execute all jobs respecting dependency order.
   * Returns when all jobs settle. Does NOT throw.
   *
   * @returns {Promise<{ critical: boolean, errorKey: string|null }>}
   */
  async execute() {
    const pending = new Set(this.jobs.map(j => j.key))

    while (pending.size > 0) {
      const runnable = this.jobs.filter(j =>
        pending.has(j.key)
        && j.resource.dependsOn.every(dep => {
          const depJob = this._jobMap.get(dep)

          return depJob ? depJob.status === 'done' : true
        }),
      )

      if (runnable.length === 0) {
        // Deadlock — mark remaining non-critical as done, abort on critical
        for (const key of pending) {
          const j = this._jobMap.get(key)
          if (j?.resource.critical) {
            return { critical: true, errorKey: j.key }
          }
          j.status = 'done' // Skip non-critical
        }
        break
      }

      await Promise.allSettled(runnable.map(j => j.run(this.deps)))

      for (const j of runnable) {
        pending.delete(j.key)
      }

      // If a critical job failed, stop immediately
      const failedCritical = runnable.find(j => j.status === 'error' && j.resource.critical)
      if (failedCritical) {
        return { critical: true, errorKey: failedCritical.key }
      }
    }

    return { critical: false, errorKey: null }
  }

  /** Cancel all running/pending jobs. */
  cancelAll() {
    for (const j of this.jobs) {
      j.cancel()
    }
    this.deps.journal.log('run:cancel', { runId: this.runId })
  }

  /**
   * Retry only the jobs that are in error state.
   * Resets their controller and re-runs them.
   * @returns {Promise<{ critical: boolean, errorKey: string|null }>}
   */
  async retryFailed() {
    const failed = this.jobs.filter(j => j.status === 'error')
    if (failed.length === 0) return { critical: false, errorKey: null }

    // Reset each failed job with a fresh controller
    for (const j of failed) {
      j.status = 'pending'
      j.error = null
      j.controller = new AbortController()
    }

    // Re-run (they should have no unsatisfied deps since they ran before)
    await Promise.allSettled(failed.map(j => j.run(this.deps)))

    const failedCritical = failed.find(j => j.status === 'error' && j.resource.critical)
    if (failedCritical) {
      return { critical: true, errorKey: failedCritical.key }
    }

    return { critical: false, errorKey: null }
  }
}
