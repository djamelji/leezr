/**
 * Runtime Stress Harness — DEV-only deterministic reproduction.
 *
 * Tests runtime convergence under adversarial conditions:
 *   S1: 50 rapid boot/teardown cycles
 *   S2: concurrent switchCompany calls
 *   S3: offline simulation → error phase
 *   S4: retryFailed → convergence to ready
 *   S5: teardown during boot (mid-phase)
 *
 * Usage:
 *   window.__runtimeStress()       — run all scenarios
 *   window.__runtimeFault('offline') — set fault mode manually
 *   window.__runtimeFaultClear()   — clear fault mode
 *
 * Output: JSON report { ts, scenarios[], snapshot, journal[], summary }
 */

import { useRuntimeStore } from '@/core/runtime/runtime'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useJobdomainStore } from '@/modules/company/jobdomain/jobdomain.store'
import { useModuleStore } from '@/core/stores/module'
import { buildSnapshot } from '@/core/runtime/invariants'

// ─── Fault injection ────────────────────────────────────

/** @type {null|'offline'|'flaky'} */
let _faultMode = null

/** @type {Map<string, { store: Object, actionName: string, original: Function }>} */
const _originals = new Map()

/**
 * Set fault mode (usable from console).
 * @param {'offline'|'flaky'|null} mode
 */
export function debugSetFaultMode(mode) {
  _faultMode = mode
}

/**
 * Clear fault mode.
 */
export function debugClearFaultMode() {
  _faultMode = null
}

// ─── Internal helpers ───────────────────────────────────

function _delay(ms, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(new DOMException('Aborted', 'AbortError'))

      return
    }

    const timer = setTimeout(resolve, ms)

    if (signal) {
      signal.addEventListener('abort', () => {
        clearTimeout(timer)
        reject(new DOMException('Aborted', 'AbortError'))
      }, { once: true })
    }
  })
}

/**
 * Replace a store action with a mock that respects signal + fault mode.
 * @param {Object} store - Pinia store instance
 * @param {string} actionName
 * @param {Function} mockFn - (options) => result (sets store state)
 */
function _patchAction(store, actionName, mockFn) {
  const key = `${store.$id}:${actionName}`
  if (_originals.has(key)) return

  const original = store[actionName]

  _originals.set(key, { store, actionName, original })

  store[actionName] = async (options = {}) => {
    if (options.signal?.aborted) {
      throw new DOMException('Aborted', 'AbortError')
    }

    // Simulate network latency (5-20ms)
    await _delay(5 + Math.random() * 15, options.signal)

    if (_faultMode === 'offline') {
      throw new Error('Network error (simulated)')
    }

    if (_faultMode === 'flaky' && Math.random() < 0.5) {
      throw new Error('Flaky error (simulated)')
    }

    return mockFn(options)
  }
}

/** Patch all runtime store actions with deterministic mocks. */
function _patchAll() {
  const auth = useAuthStore()
  const platformAuth = usePlatformAuthStore()
  const jobdomain = useJobdomainStore()
  const module = useModuleStore()

  _patchAction(auth, 'fetchMe', () => {
    auth._user = { id: 1, first_name: 'Stress', last_name: 'Test', email: 'stress@test.dev' }
    auth._hydrated = true

    return auth._user
  })

  _patchAction(auth, 'fetchMyCompanies', () => {
    auth._companies = [{ id: 1, name: 'StressCo' }]
    if (!auth._currentCompanyId) auth._currentCompanyId = 1

    return auth._companies
  })

  _patchAction(platformAuth, 'fetchMe', () => {
    platformAuth._user = { id: 1, first_name: 'Platform', last_name: 'Stress', email: 'platform@test.dev' }
    platformAuth._hydrated = true

    return platformAuth._user
  })

  _patchAction(jobdomain, 'fetchJobdomain', () => {
    jobdomain._assigned = true
    jobdomain._jobdomain = { id: 1, name: 'default' }
    jobdomain._profile = { nav_profile: 'default' }

    return { assigned: true, jobdomain: { id: 1 }, profile: { nav_profile: 'default' } }
  })

  _patchAction(module, 'fetchModules', () => {
    module._modules = [{ key: 'recruitment', is_active: true }]
    module._loaded = true

    return module._modules
  })
}

/** Restore all original store actions. */
function _unpatchAll() {
  for (const [, { store, actionName, original }] of _originals) {
    store[actionName] = original
  }
  _originals.clear()
  _faultMode = null
}

// ─── Scenarios ──────────────────────────────────────────

/**
 * S1: 50 rapid boot/teardown — fire-and-forget.
 * Only the last boot should survive and converge to ready.
 */
async function s1_rapidNavigations(runtime) {
  const errors = []

  for (let i = 0; i < 50; i++) {
    runtime.teardown()
    runtime.boot('company') // fire, don't await
  }

  // Wait for the last boot to reach ready
  try {
    await runtime.whenReady(10000)
  }
  catch {
    errors.push('Timeout waiting for ready after 50 rapid navigations')
  }

  if (runtime.phase !== 'ready') {
    errors.push(`Expected phase=ready, got ${runtime.phase}`)
  }

  return {
    name: 'S1: 50 rapid navigations',
    pass: errors.length === 0,
    errors,
    finalPhase: runtime.phase,
    finalScope: runtime.scope,
  }
}

/**
 * S2: Concurrent switchCompany calls from ready state.
 * Fire 3 switches without awaiting — only the last should win.
 */
async function s2_concurrentSwitch(runtime) {
  const errors = []

  // First, boot to ready
  runtime.teardown()
  runtime.boot('company')

  try {
    await runtime.whenReady(10000)
  }
  catch {
    errors.push('Timeout during initial boot')

    return { name: 'S2: concurrent switchCompany', pass: false, errors, finalPhase: runtime.phase }
  }

  // Fire 3 rapid switches — catch stale rejections
  runtime.switchCompany(100).catch(() => {}) // fire
  runtime.switchCompany(200).catch(() => {}) // fire — cancels 100
  await runtime.switchCompany(300) // await last one

  try {
    await runtime.whenReady(10000)
  }
  catch {
    errors.push('Timeout waiting for ready after concurrent switches')
  }

  if (runtime.phase !== 'ready') {
    errors.push(`Expected phase=ready, got ${runtime.phase}`)
  }

  return {
    name: 'S2: concurrent switchCompany',
    pass: errors.length === 0,
    errors,
    finalPhase: runtime.phase,
  }
}

/**
 * S3: Offline simulation → error phase.
 * All API calls fail — auth:me is critical → error.
 */
async function s3_offline(runtime) {
  const errors = []

  runtime.teardown()
  _faultMode = 'offline'

  runtime.boot('company')

  // Wait for boot to settle in error (auth:me will fail)
  await _delay(500)

  if (runtime.phase !== 'error') {
    errors.push(`Expected phase=error, got ${runtime.phase}`)
  }

  if (!runtime.error) {
    errors.push('Expected error message to be set')
  }

  return {
    name: 'S3: offline → error',
    pass: errors.length === 0,
    errors,
    finalPhase: runtime.phase,
    errorMessage: runtime.error,
  }
}

/**
 * S4: retryFailed → convergence to ready.
 * Continues from S3's error state — clears fault mode and retries.
 */
async function s4_retryConvergence(runtime) {
  const errors = []

  // Clear fault — retries should succeed
  _faultMode = null

  const result = await runtime.retryFailed()

  // Give _continueFromPhase time to complete remaining phases
  await _delay(500)

  if (runtime.phase !== 'ready') {
    errors.push(`Expected phase=ready after retry, got ${runtime.phase}`)
  }

  if (result.critical) {
    errors.push('retryFailed returned critical=true despite clearing fault mode')
  }

  return {
    name: 'S4: retryFailed convergence',
    pass: errors.length === 0,
    errors,
    finalPhase: runtime.phase,
    retryResult: result,
  }
}

/**
 * S5: Teardown during boot (mid-phase).
 * Boot is interrupted — must reach clean cold state.
 */
async function s5_teardownDuringBoot(runtime) {
  const errors = []

  runtime.teardown()
  runtime.boot('company') // fire

  // Teardown after a small delay (boot is in auth or tenant)
  await _delay(8)
  runtime.teardown()

  if (runtime.phase !== 'cold') {
    errors.push(`Expected phase=cold after teardown, got ${runtime.phase}`)
  }

  if (runtime.scope !== null) {
    errors.push(`Expected scope=null after teardown, got ${runtime.scope}`)
  }

  if (runtime.error) {
    errors.push(`Expected no error after teardown, got: ${runtime.error}`)
  }

  return {
    name: 'S5: teardown during boot',
    pass: errors.length === 0,
    errors,
    finalPhase: runtime.phase,
  }
}

// ─── Runner ─────────────────────────────────────────────

/**
 * Run all stress test scenarios.
 * @returns {Promise<Object>} JSON report
 */
export async function runStressTests() {
  if (!import.meta.env.DEV) {
    console.warn('[stress] Stress tests are DEV-only')

    return { error: 'DEV-only' }
  }

  const runtime = useRuntimeStore()

  console.group('[stress] Runtime Stress Harness')
  console.log('Patching store actions with mocks...')

  // Save current scope BEFORE teardown (teardown resets it to null)
  const originalScope = runtime.scope
    || (window.location.pathname.startsWith('/platform') ? 'platform' : 'company')

  // Clean slate
  runtime.teardown()
  runtime.clearJournal()

  // Patch all stores
  _patchAll()

  const report = {
    ts: new Date().toISOString(),
    scenarios: [],
    snapshot: null,
    journal: [],
    summary: null,
  }

  // Run each scenario in isolation — invariant throws don't skip remaining tests
  async function _run(label, fn) {
    console.log(`${label}...`)
    try {
      const result = await fn()
      report.scenarios.push(result)
      console.log(result.pass ? `%c PASS %c ${label}` : `%c FAIL %c ${label}`,
        result.pass ? 'background:#4caf50;color:white;padding:1px 4px' : 'background:#f44336;color:white;padding:1px 4px',
        '', ...(!result.pass ? [result.errors.join(', ')] : []))
    }
    catch (err) {
      const result = { name: label, pass: false, errors: [err.message], finalPhase: runtime.phase }
      report.scenarios.push(result)
      console.log(`%c FAIL %c ${label}`, 'background:#f44336;color:white;padding:1px 4px', '', err.message)
    }
  }

  try {
    await _run('S1: 50 rapid navigations', () => s1_rapidNavigations(runtime))

    runtime.teardown()
    await _delay(10)
    await _run('S2: concurrent switchCompany', () => s2_concurrentSwitch(runtime))

    await _run('S3: offline → error', () => s3_offline(runtime))

    // S4 continues from S3's error state
    await _run('S4: retryFailed convergence', () => s4_retryConvergence(runtime))

    await _run('S5: teardown during boot', () => s5_teardownDuringBoot(runtime))
  }
  finally {
    // Capture final snapshot and journal
    report.snapshot = buildSnapshot(runtime, {})
    report.journal = runtime.journalEntries.slice(-50)

    // Restore everything
    _unpatchAll()
    runtime.teardown()
    runtime.clearJournal()

    // Re-boot with original scope so the page recovers
    runtime.boot(originalScope)

    const passed = report.scenarios.filter(s => s.pass).length
    const total = report.scenarios.length

    report.summary = { passed, total, allPass: passed === total }

    console.log(
      report.summary.allPass
        ? `%c ${passed}/${total} ALL PASS `
        : `%c ${passed}/${total} SOME FAILED `,
      report.summary.allPass
        ? 'background:#4caf50;color:white;padding:2px 8px;font-weight:bold'
        : 'background:#f44336;color:white;padding:2px 8px;font-weight:bold',
    )
    console.groupEnd()
  }

  return report
}

// ─── Window bindings (DEV-only) ─────────────────────────

if (import.meta.env.DEV && typeof window !== 'undefined') {
  window.__runtimeStress = runStressTests
  window.__runtimeFault = debugSetFaultMode
  window.__runtimeFaultClear = debugClearFaultMode
}
