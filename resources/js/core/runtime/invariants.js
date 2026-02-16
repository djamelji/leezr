/**
 * Runtime Invariants — formal guarantees enforced in DEV.
 *
 * Each invariant is a function that receives a runtime snapshot
 * and throws if violated. Active only in DEV (import.meta.env.DEV).
 *
 * Call assertRuntime(snapshot, context) at critical points:
 * - After phase transitions
 * - After syncResources
 * - After retryFailed
 * - After requestSwitch
 * - After teardown
 */

const VALID_PHASES = new Set(['cold', 'auth', 'tenant', 'features', 'ready', 'error'])
const VALID_SCOPES = new Set(['company', 'platform', 'public', null])

// ─── Invariant definitions ──────────────────────────────

const invariants = [
  // I1: phase must be a known value
  {
    id: 'I1',
    name: 'phase-valid',
    check(s) {
      if (!VALID_PHASES.has(s.phase)) {
        return `phase "${s.phase}" is not a valid phase`
      }
    },
  },

  // I2: scope must be a known value
  {
    id: 'I2',
    name: 'scope-valid',
    check(s) {
      if (!VALID_SCOPES.has(s.scope)) {
        return `scope "${s.scope}" is not a valid scope`
      }
    },
  },

  // I3: ready ⇒ isReady AND no pending/loading jobs
  {
    id: 'I3',
    name: 'ready-consistent',
    check(s) {
      if (s.phase === 'ready') {
        if (!s.isReady) return 'phase is ready but isReady is false'
        if (s.progress.loading > 0) return `phase is ready but ${s.progress.loading} jobs still loading`
        if (s.progress.pending > 0) return `phase is ready but ${s.progress.pending} jobs still pending`
      }
    },
  },

  // I4: company scope + ready ⇒ all required phases executed
  {
    id: 'I4',
    name: 'ready-phases-complete',
    check(s) {
      if (s.phase === 'ready' && s.scope === 'company' && s.runMeta) {
        for (const p of s.runMeta.requiredPhases) {
          if (!s.runMeta.executedPhases.has(p)) {
            return `phase ready but required phase "${p}" was never executed in this run`
          }
        }
      }
    },
  },

  // I5: error ⇒ errorMessage is set
  {
    id: 'I5',
    name: 'error-has-message',
    check(s) {
      if (s.phase === 'error' && !s.error) {
        return 'phase is error but no error message is set'
      }
    },
  },

  // I6: error ⇒ isReady must be false
  {
    id: 'I6',
    name: 'error-not-ready',
    check(s) {
      if (s.phase === 'error' && s.isReady) {
        return 'phase is error but isReady is true'
      }
    },
  },

  // I7: runId must be monotonically increasing (tracked via lastSeenRunId)
  {
    id: 'I7',
    name: 'runid-monotonic',
    _lastSeen: 0,
    check(s) {
      if (s.runId !== null && s.runId > 0) {
        if (s.runId < this._lastSeen) {
          return `runId ${s.runId} is less than previously seen ${this._lastSeen}`
        }
        this._lastSeen = s.runId
      }
    },
  },

  // I8: cold ⇒ no error message lingering
  {
    id: 'I8',
    name: 'cold-clean',
    check(s) {
      if (s.phase === 'cold' && s.error) {
        return 'phase is cold but error message is still set'
      }
    },
  },

  // I9: cold ⇒ resources should be empty
  {
    id: 'I9',
    name: 'cold-no-resources',
    check(s) {
      if (s.phase === 'cold' && Object.keys(s.resources).length > 0) {
        return 'phase is cold but resources map is not empty'
      }
    },
  },

  // I10: ready ⇒ scope must be set
  {
    id: 'I10',
    name: 'ready-has-scope',
    check(s) {
      if (s.phase === 'ready' && !s.scope) {
        return 'phase is ready but scope is null'
      }
    },
  },

  // I11: booting phases ⇒ scope must be set
  {
    id: 'I11',
    name: 'booting-has-scope',
    check(s) {
      if (['auth', 'tenant', 'features'].includes(s.phase) && !s.scope) {
        return `phase is "${s.phase}" (booting) but scope is null`
      }
    },
  },

  // I12: platform scope should never enter tenant/features phase
  {
    id: 'I12',
    name: 'platform-no-tenant',
    check(s) {
      if (s.scope === 'platform' && ['tenant', 'features'].includes(s.phase)) {
        return `platform scope should not be in "${s.phase}" phase`
      }
    },
  },

  // I13: public scope should only be in cold or ready
  {
    id: 'I13',
    name: 'public-phases',
    check(s) {
      if (s.scope === 'public' && !['cold', 'ready'].includes(s.phase)) {
        return `public scope should not be in "${s.phase}" phase`
      }
    },
  },

  // I14: executedPhases should be a subset of requiredPhases
  {
    id: 'I14',
    name: 'executed-subset',
    check(s) {
      if (s.runMeta) {
        for (const p of s.runMeta.executedPhases) {
          if (!s.runMeta.requiredPhases.includes(p)) {
            return `executed phase "${p}" is not in requiredPhases`
          }
        }
      }
    },
  },
]

// ─── Public API ─────────────────────────────────────────

/**
 * Assert all invariants against a runtime snapshot.
 * Only active in DEV. No-op in production.
 *
 * @param {Object} snapshot - Runtime state snapshot
 * @param {string} snapshot.phase
 * @param {string|null} snapshot.scope
 * @param {boolean} snapshot.isReady
 * @param {string|null} snapshot.error
 * @param {Object} snapshot.progress - { total, done, loading, error, pending, cancelled }
 * @param {Object} snapshot.resources - Resource status map
 * @param {number|null} snapshot.runId
 * @param {Object|null} snapshot.runMeta - { runId, scope, requiredPhases, executedPhases: Set }
 * @param {string} context - Where the assertion is called from (for diagnostics)
 * @param {Object} [journal] - Optional journal instance for logging violations
 */
export function assertRuntime(snapshot, context, journal) {
  if (!import.meta.env.DEV) return

  for (const inv of invariants) {
    const violation = inv.check(snapshot)
    if (violation) {
      const msg = `[invariant:${inv.id}] ${inv.name}: ${violation} (at ${context})`

      if (journal) {
        journal.log('invariant:failed', { id: inv.id, name: inv.name, violation, context })
      }

      console.error(msg)
      throw new Error(msg)
    }
  }
}

/**
 * Build a snapshot from a runtime store instance + scheduler metadata.
 * @param {Object} store - Pinia runtime store
 * @param {Object} [meta] - { runId, runMeta }
 * @returns {Object} Snapshot suitable for assertRuntime
 */
export function buildSnapshot(store, meta = {}) {
  return {
    phase: store._phase,
    scope: store._scope,
    isReady: store._phase === 'ready',
    error: store._error,
    progress: typeof store.progress === 'object' ? store.progress : { total: 0, done: 0, loading: 0, error: 0, pending: 0, cancelled: 0 },
    resources: store._resources || {},
    runId: meta.runId ?? null,
    runMeta: meta.runMeta ?? null,
  }
}
