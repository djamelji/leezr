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
import { useJobdomainStore } from '@/core/stores/jobdomain'
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

  // Fire 3 rapid switches
  runtime.switchCompany(100) // fire
  runtime.switchCompany(200) // fire — cancels 100
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

  try {
    // S1: 50 rapid navigations
    console.log('S1: 50 rapid navigations...')
    const s1 = await s1_rapidNavigations(runtime)
    report.scenarios.push(s1)
    console.log(s1.pass ? '%c PASS %c S1' : '%c FAIL %c S1',
      s1.pass ? 'background:#4caf50;color:white;padding:1px 4px' : 'background:#f44336;color:white;padding:1px 4px',
      '', ...(!s1.pass ? [s1.errors.join(', ')] : []))

    // S2: concurrent switchCompany
    console.log('S2: concurrent switchCompany...')
    runtime.teardown()
    await _delay(10)
    const s2 = await s2_concurrentSwitch(runtime)
    report.scenarios.push(s2)
    console.log(s2.pass ? '%c PASS %c S2' : '%c FAIL %c S2',
      s2.pass ? 'background:#4caf50;color:white;padding:1px 4px' : 'background:#f44336;color:white;padding:1px 4px',
      '', ...(!s2.pass ? [s2.errors.join(', ')] : []))

    // S3: offline → error
    console.log('S3: offline → error...')
    const s3 = await s3_offline(runtime)
    report.scenarios.push(s3)
    console.log(s3.pass ? '%c PASS %c S3' : '%c FAIL %c S3',
      s3.pass ? 'background:#4caf50;color:white;padding:1px 4px' : 'background:#f44336;color:white;padding:1px 4px',
      '', ...(!s3.pass ? [s3.errors.join(', ')] : []))

    // S4: retryFailed convergence (continues from S3)
    console.log('S4: retryFailed convergence...')
    const s4 = await s4_retryConvergence(runtime)
    report.scenarios.push(s4)
    console.log(s4.pass ? '%c PASS %c S4' : '%c FAIL %c S4',
      s4.pass ? 'background:#4caf50;color:white;padding:1px 4px' : 'background:#f44336;color:white;padding:1px 4px',
      '', ...(!s4.pass ? [s4.errors.join(', ')] : []))

    // S5: teardown during boot
    console.log('S5: teardown during boot...')
    const s5 = await s5_teardownDuringBoot(runtime)
    report.scenarios.push(s5)
    console.log(s5.pass ? '%c PASS %c S5' : '%c FAIL %c S5',
      s5.pass ? 'background:#4caf50;color:white;padding:1px 4px' : 'background:#f44336;color:white;padding:1px 4px',
      '', ...(!s5.pass ? [s5.errors.join(', ')] : []))
  }
  finally {
    // Capture final snapshot and journal
    report.snapshot = buildSnapshot(runtime, {})
    report.journal = runtime.journalEntries.slice(-50)

    // Restore everything
    _unpatchAll()
    runtime.teardown()
    runtime.clearJournal()

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
