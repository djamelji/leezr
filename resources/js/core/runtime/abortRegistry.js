/**
 * AbortController registry — legacy safety net.
 *
 * With the Job System (G2), each job has its own AbortController.
 * These functions remain as a safety net for teardown and group abort.
 * The global signal approach (setActiveGroup/getActiveSignal) is removed.
 */

/** @type {Map<string, AbortController>} */
const controllers = new Map()

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
 * Abort ALL groups (used on logout/teardown as safety net).
 */
export function abortAll() {
  for (const [, controller] of controllers) {
    controller.abort()
  }
  controllers.clear()
}

/**
 * @deprecated No-op — kept for backward compatibility during G2→G3 transition.
 */
export function setActiveGroup() {}
