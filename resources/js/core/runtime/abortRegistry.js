/**
 * AbortController registry — manages abort signals per request group.
 *
 * Groups: 'auth', 'tenant', 'features'
 * The $api onRequest hook reads the active signal via getActiveSignal().
 */

/** @type {Map<string, AbortController>} */
const controllers = new Map()

/** @type {string|null} */
let _activeGroup = null

/**
 * Get (or create) the AbortController for a group.
 * @param {string} group
 * @returns {AbortController}
 */
function ensureController(group) {
  if (!controllers.has(group)) {
    controllers.set(group, new AbortController())
  }

  return controllers.get(group)
}

/**
 * Get the AbortSignal for a specific group.
 * @param {string} group
 * @returns {AbortSignal}
 */
export function getSignal(group) {
  return ensureController(group).signal
}

/**
 * Abort all pending requests in a group, then create a fresh controller.
 * @param {string} group
 */
export function abortGroup(group) {
  const controller = controllers.get(group)
  if (controller) {
    controller.abort()
  }
  controllers.set(group, new AbortController())
}

/**
 * Abort ALL groups (used on logout/teardown).
 */
export function abortAll() {
  for (const [group, controller] of controllers) {
    controller.abort()
    controllers.set(group, new AbortController())
  }
}

/**
 * Set the currently active group. The $api onRequest hook reads this
 * to attach the correct signal to outgoing requests.
 * @param {string|null} group
 */
export function setActiveGroup(group) {
  _activeGroup = group
}

/**
 * Get the active signal (if any). Returns undefined when no group is active,
 * which means $api will not attach any abort signal.
 * @returns {AbortSignal|undefined}
 */
export function getActiveSignal() {
  if (!_activeGroup) return undefined

  return getSignal(_activeGroup)
}
