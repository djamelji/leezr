/**
 * DomainHandler — simple event bus for domain events.
 *
 * ADR-126: Components can subscribe to specific domain event types
 * (e.g., 'member.joined', 'module.activated') for local UI updates
 * without touching the cache invalidation pipeline.
 *
 * Usage:
 *   const handler = createDomainHandler()
 *   handler.on('member.joined', envelope => { ... })
 *   handler.dispatch(envelope) // topic = 'member.joined'
 *   handler.off('member.joined', myCallback)
 */

export function createDomainHandler() {
  const _listeners = new Map()

  function on(eventType, callback) {
    if (!_listeners.has(eventType)) {
      _listeners.set(eventType, new Set())
    }
    _listeners.get(eventType).add(callback)
  }

  function off(eventType, callback) {
    const set = _listeners.get(eventType)
    if (set) {
      set.delete(callback)
      if (set.size === 0) _listeners.delete(eventType)
    }
  }

  function dispatch(envelope) {
    const topic = envelope?.topic
    if (!topic) return

    const set = _listeners.get(topic)
    if (set) {
      set.forEach(cb => {
        try { cb(envelope) } catch { /* listener error — skip */ }
      })
    }
  }

  function clear() {
    _listeners.clear()
  }

  return { on, off, dispatch, clear }
}
