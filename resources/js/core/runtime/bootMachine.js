/**
 * Boot Machine — external reactive state for the SPA boot lifecycle.
 *
 * States: COLD → BOOTING → READY | FAILED
 *
 * This module is the SINGLE source of truth for boot state.
 * Guards and layouts read it; the scheduler drives transitions via
 * commit() / fail(). Internal phase progress (auth → tenant → features)
 * remains in stateMachine.js for dev overlay only.
 *
 * NOT a Pinia store — standalone reactive module (Vue refs).
 * This avoids circular dependency with the runtime store.
 */

import { ref, computed } from 'vue'

export const BootState = Object.freeze({
  COLD: 'COLD',
  BOOTING: 'BOOTING',
  READY: 'READY',
  FAILED: 'FAILED',
})

export function createBootMachine() {
  const _state = ref(BootState.COLD)
  const _scope = ref(null)
  const _error = ref(null)

  // Promise coordination — one inflight boot at a time
  let _resolve = null
  let _promise = null

  function _newPromise() {
    _promise = new Promise(r => { _resolve = r })

    return _promise
  }

  function _resolveIfPending() {
    if (_resolve) {
      _resolve()
      _resolve = null
      _promise = null
    }
  }

  return {
    // ─── Reactive reads (consumed by guards, layouts, AppShellGate) ──

    state: computed(() => _state.value),
    scope: computed(() => _scope.value),
    error: computed(() => _error.value),
    isReady: computed(() => _state.value === BootState.READY),
    isBooting: computed(() => _state.value === BootState.BOOTING),
    isFailed: computed(() => _state.value === BootState.FAILED),
    isCold: computed(() => _state.value === BootState.COLD),

    /**
     * Prepare a boot for the given scope.
     *
     * Returns:
     * - null        → already READY for this scope (no-op)
     * - promise     → boot in progress or just started; await it
     *
     * Handles dedup: if already BOOTING for the same scope, returns the
     * existing promise instead of starting a new boot.
     *
     * @param {'company'|'platform'|'public'} scope
     * @param {boolean} [force=false] - Force reboot (company switch, retry)
     * @returns {Promise<void>|null}
     */
    prepareBoot(scope, force = false) {
      // Already ready for this scope — no-op
      if (!force && _state.value === BootState.READY && _scope.value === scope) {
        return null
      }

      // Already booting for this scope — return existing promise (dedup)
      if (!force && _state.value === BootState.BOOTING && _scope.value === scope && _promise) {
        return _promise
      }

      // New boot needed — resolve any pending from previous boot
      _resolveIfPending()

      _state.value = BootState.BOOTING
      _scope.value = scope
      _error.value = null

      return _newPromise()
    },

    /**
     * SINGLE place where READY is set.
     * Called by the scheduler after all phases complete successfully.
     */
    commit() {
      _state.value = BootState.READY
      _error.value = null
      _resolveIfPending()
    },

    /**
     * SINGLE place where FAILED is set.
     * Called by the scheduler when a critical job fails.
     *
     * @param {string} [message] - Error description
     */
    fail(message) {
      _state.value = BootState.FAILED
      _error.value = message || 'Boot failed'
      _resolveIfPending()
    },

    /**
     * Reset to COLD — called by runtime.teardown() only.
     * Resolves any pending promise so awaiting guards unblock.
     */
    teardown() {
      _resolveIfPending()
      _state.value = BootState.COLD
      _scope.value = null
      _error.value = null
    },

    /**
     * Promise that resolves when the current boot completes.
     * If no boot in progress, resolves immediately.
     * @returns {Promise<void>}
     */
    get promise() {
      return _promise || Promise.resolve()
    },
  }
}

// ─── Singleton instance ──────────────────────────────────────
// Shared across runtime, guards, and layouts.
export const bootMachine = createBootMachine()
