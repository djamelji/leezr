/**
 * ChannelRouter — dispatches SSE events by category.
 *
 * ADR-126: Routes incoming realtime events to dedicated handlers
 * based on their SSE event type (category). Unknown categories
 * are silently ignored (forward-compatible).
 *
 * Usage:
 *   const router = createChannelRouter({
 *     invalidation: data => handleInvalidation(data),
 *     domain: data => handleDomain(data),
 *     notification: data => handleNotification(data),
 *     audit: data => handleAudit(data),
 *     security: data => handleSecurity(data),
 *   })
 *   router.dispatch('invalidate', data)  // → handlers.invalidation(data)
 */

/**
 * SSE event type → handler key mapping.
 * 'invalidate' is the backward-compat name for invalidation category.
 */
const EVENT_TYPE_MAP = {
  invalidate: 'invalidation',
  domain: 'domain',
  notification: 'notification',
  audit: 'audit',
  security: 'security',
}

/**
 * @typedef {Object} ChannelHandlers
 * @property {(data: Object) => void} [invalidation]
 * @property {(data: Object) => void} [domain]
 * @property {(data: Object) => void} [notification]
 * @property {(data: Object) => void} [audit]
 * @property {(data: Object) => void} [security]
 */

/**
 * @param {ChannelHandlers} handlers
 */
export function createChannelRouter(handlers) {
  function dispatch(sseEventType, data) {
    const handlerKey = EVENT_TYPE_MAP[sseEventType]

    if (!handlerKey) return // Unknown category — forward-compatible ignore

    const handler = handlers[handlerKey]

    if (typeof handler === 'function') {
      handler(data)
    }
  }

  return { dispatch }
}
