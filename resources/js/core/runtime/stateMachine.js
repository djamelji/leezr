/**
 * Runtime State Machine — validated phase transitions.
 *
 * Every phase change must go through `transition()`.
 * Invalid transitions throw in DEV, return current phase in PROD.
 */

const TRANSITIONS = new Set([
  'cold→auth',
  'cold→ready',
  'auth→tenant',
  'auth→ready',
  'auth→error',
  'auth→cold',
  'tenant→features',
  'tenant→error',
  'tenant→cold',
  'features→ready',
  'features→error',
  'features→cold',
  'ready→cold',
  'ready→tenant',
  'error→cold',
  'error→ready',
])

/**
 * Attempt a phase transition. Returns the target phase if valid.
 * @param {string} current - Current phase
 * @param {string} target  - Desired phase
 * @returns {string} The target phase
 * @throws {Error} In DEV mode if the transition is invalid
 */
export function transition(current, target) {
  const key = `${current}→${target}`

  if (TRANSITIONS.has(key)) {
    return target
  }

  const msg = `[runtime] Invalid transition: ${current} → ${target}`

  if (import.meta.env.DEV) {
    throw new Error(msg)
  }

  console.error(msg)

  return current
}

/**
 * Check if a transition is allowed without executing it.
 * @param {string} current
 * @param {string} target
 * @returns {boolean}
 */
export function canTransition(current, target) {
  return TRANSITIONS.has(`${current}→${target}`)
}
