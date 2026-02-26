/**
 * RealtimeClient — SSE-based realtime event engine.
 *
 * ADR-125: Connects to /api/realtime/stream via EventSource.
 * ADR-126: Multi-category support — routes events via onEvent callback.
 *
 * Fallback: if SSE fails 3 times consecutively, falls back to polling.
 *
 * Usage:
 *   const client = createRealtimeClient({
 *     companyId,
 *     onEvent,        // (sseEventType, data) => void
 *     onInvalidate,   // backward compat alias for invalidation events
 *     onFallback,
 *     onConnected,
 *   })
 *   client.connect()
 *   client.disconnect()
 */

const MAX_RECONNECT_ATTEMPTS = 3
const RECONNECT_BACKOFF_BASE = 2000 // 2s, 4s, 8s
const DEBOUNCE_MS = 2000 // Debounce invalidation refetches

/**
 * ADR-126: All SSE event types we listen for.
 * 'invalidate' is the backward-compat name for invalidation category.
 */
const SSE_EVENT_TYPES = ['invalidate', 'domain', 'notification', 'audit', 'security']

/**
 * @typedef {Object} RealtimeClientOptions
 * @property {number|string} companyId
 * @property {(sseEventType: string, data: Object) => void} [onEvent] - ADR-126: unified event callback
 * @property {(keys: string[], topic: string) => void} [onInvalidate] - Backward compat: called for invalidation events
 * @property {() => void} onFallback - Called when SSE fails and polling should be activated
 * @property {() => void} [onConnected] - Called when SSE stream is connected
 */

/**
 * @param {RealtimeClientOptions} options
 */
export function createRealtimeClient(options) {
  let _eventSource = null
  let _reconnectAttempts = 0
  let _reconnectTimer = null
  let _connected = false
  let _destroyed = false

  // Debounce: accumulate invalidation keys, flush once
  let _pendingKeys = new Set()
  let _debounceTimer = null

  function _flushInvalidations() {
    if (_pendingKeys.size === 0) return

    const keys = [..._pendingKeys]
    _pendingKeys = new Set()
    _debounceTimer = null

    options.onInvalidate?.(keys, 'debounced')
  }

  function _scheduleInvalidation(keys) {
    keys.forEach(k => _pendingKeys.add(k))

    if (_debounceTimer) clearTimeout(_debounceTimer)
    _debounceTimer = setTimeout(_flushInvalidations, DEBOUNCE_MS)
  }

  function _handleEvent(sseEventType, data) {
    // ADR-126: Unified event callback
    if (typeof options.onEvent === 'function') {
      options.onEvent(sseEventType, data)
    }

    // Backward compat: invalidation events also debounce + call onInvalidate
    if (sseEventType === 'invalidate') {
      const keys = data.invalidates || []
      if (keys.length > 0) {
        _scheduleInvalidation(keys)
      }
    }
  }

  function connect() {
    if (_destroyed || _eventSource) return

    const baseUrl = import.meta.env.VITE_API_BASE_URL || '/api'
    const url = `${baseUrl}/realtime/stream?company_id=${options.companyId}`

    try {
      _eventSource = new EventSource(url, { withCredentials: true })
    }
    catch {
      // EventSource not supported or URL invalid
      options.onFallback()

      return
    }

    _eventSource.addEventListener('connected', () => {
      _reconnectAttempts = 0
      _connected = true
      if (import.meta.env.DEV) {
        console.log('[realtime] SSE connected', { companyId: options.companyId })
      }
      options.onConnected?.()
    })

    // ADR-126: Listen for all category event types
    for (const eventType of SSE_EVENT_TYPES) {
      _eventSource.addEventListener(eventType, event => {
        try {
          const data = JSON.parse(event.data)

          if (import.meta.env.DEV) {
            console.log(`[realtime] ${eventType}`, data.topic, data)
          }

          _handleEvent(eventType, data)
        }
        catch {
          // Malformed event — skip
        }
      })
    }

    _eventSource.addEventListener('error', event => {
      try {
        const data = JSON.parse(event.data)
        if (import.meta.env.DEV) {
          console.warn('[realtime] stream error', data)
        }
      }
      catch {
        // Standard SSE error (connection lost)
      }
    })

    _eventSource.onerror = () => {
      _connected = false
      _cleanup()

      if (_destroyed) return

      _reconnectAttempts++

      if (_reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
        if (import.meta.env.DEV) {
          console.warn(`[realtime] SSE failed ${MAX_RECONNECT_ATTEMPTS} times — falling back to polling`)
        }
        options.onFallback()

        return
      }

      // Exponential backoff reconnect
      const delay = RECONNECT_BACKOFF_BASE * Math.pow(2, _reconnectAttempts - 1)
      if (import.meta.env.DEV) {
        console.log(`[realtime] reconnecting in ${delay}ms (attempt ${_reconnectAttempts})`)
      }
      _reconnectTimer = setTimeout(connect, delay)
    }
  }

  function disconnect() {
    _destroyed = true
    _cleanup()

    if (_debounceTimer) {
      clearTimeout(_debounceTimer)
      _flushInvalidations() // Flush any pending invalidations
    }
  }

  function _cleanup() {
    if (_reconnectTimer) {
      clearTimeout(_reconnectTimer)
      _reconnectTimer = null
    }
    if (_eventSource) {
      _eventSource.close()
      _eventSource = null
    }
  }

  return {
    connect,
    disconnect,
    get connected() { return _connected },
    get destroyed() { return _destroyed },
  }
}
