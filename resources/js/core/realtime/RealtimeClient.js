/**
 * RealtimeClient — SSE-based realtime event engine.
 *
 * ADR-125: Connects to /api/realtime/stream via EventSource.
 * ADR-126: Multi-category support — routes events via onEvent callback.
 *
 * Fallback: if SSE fails 3 times consecutively, falls back to polling.
 * ADR-829: then retries SSE alone (bounded backoff + jitter, visible tab only) and leaves polling once connected.
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

// ADR-829 (D-20) — after the fallback to polling, ONE recovery attempt at a time, bounded exponential
// backoff with jitter : 30 s, 60 s, 120 s, 240 s, then every 300 s. Never while the tab is hidden.
export const RECOVERY_BASE_MS = 30_000
export const RECOVERY_MAX_MS = 300_000
const RECOVERY_JITTER = 0.2

export function recoveryDelay(attempt, random = Math.random) {
  const raw = Math.min(RECOVERY_BASE_MS * 2 ** Math.max(0, attempt), RECOVERY_MAX_MS)

  return Math.round(raw * (1 - RECOVERY_JITTER + 2 * RECOVERY_JITTER * random()))
}

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
 * @property {() => void} [onConnected] - Called when SSE stream is connected (first time AND after a recovery)
 * @property {() => void} [onDisconnected] - ADR-829: an open stream was lost (polling must cover the gap)
 * @property {() => boolean} [canRecover] - ADR-829: false = skip this recovery attempt (session gone, scope changed)
 * @property {Function} [eventSourceFactory] - tests only
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

  // ADR-829: recovery state (after fallback)
  let _fallback = false
  let _recoveryAttempts = 0
  let _recoveryTimer = null
  let _waitingVisible = false

  const _doc = typeof document !== 'undefined' ? document : null
  const _makeSource = options.eventSourceFactory || ((url, init) => new EventSource(url, init))

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
    // ADR-431: Platform scope uses /platform prefix, company scope uses company_id param
    const url = options.companyId
      ? `${baseUrl}/realtime/stream?company_id=${options.companyId}`
      : `${baseUrl}/platform/realtime/stream`

    try {
      _eventSource = _makeSource(url, { withCredentials: true })
    }
    catch {
      // EventSource not supported or URL invalid — no recovery possible
      options.onFallback()

      return
    }

    _eventSource.addEventListener('connected', () => {
      _reconnectAttempts = 0
      _connected = true
      if (_fallback) {
        // ADR-829: capacity came back — leave polling without a reload
        _fallback = false
        _recoveryAttempts = 0
        if (import.meta.env.DEV) console.debug('[realtime] SSE recovered after fallback')
      }
      if (import.meta.env.DEV) {
        console.debug('[realtime] SSE connected', { companyId: options.companyId })
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
      const wasConnected = _connected

      _connected = false
      _closeSource()

      if (_destroyed) return

      if (wasConnected) options.onDisconnected?.()

      // ADR-829: a failed recovery attempt (429 capacity, 401/403, network) — back off, never burst
      if (_fallback) {
        _recoveryAttempts++
        _scheduleRecovery()

        return
      }

      _reconnectAttempts++

      if (_reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
        if (import.meta.env.DEV) {
          console.warn(`[realtime] SSE failed ${MAX_RECONNECT_ATTEMPTS} times — falling back to polling`)
        }
        _fallback = true
        _recoveryAttempts = 0
        options.onFallback()
        _scheduleRecovery()

        return
      }

      // Exponential backoff reconnect
      const delay = RECONNECT_BACKOFF_BASE * Math.pow(2, _reconnectAttempts - 1)
      if (import.meta.env.DEV) {
        console.log(`[realtime] reconnecting in ${delay}ms (attempt ${_reconnectAttempts})`)
      }
      _reconnectTimer = setTimeout(() => {
        _reconnectTimer = null
        connect()
      }, delay)
    }
  }

  // ─── ADR-829: recovery after fallback ─────────────────────
  function _scheduleRecovery() {
    if (_destroyed || _recoveryTimer) return
    const delay = recoveryDelay(_recoveryAttempts)
    if (import.meta.env.DEV) console.log(`[realtime] SSE recovery attempt in ${delay}ms`)
    _recoveryTimer = setTimeout(_attemptRecovery, delay)
  }

  function _attemptRecovery() {
    _recoveryTimer = null
    if (_destroyed || !_fallback || _eventSource) return

    // Hidden tab : do not hold a server worker for nobody — retry once it is visible again
    if (_doc && _doc.visibilityState === 'hidden') {
      if (!_waitingVisible) {
        _waitingVisible = true
        _doc.addEventListener('visibilitychange', _onVisible)
      }

      return
    }
    if (typeof options.canRecover === 'function' && !options.canRecover()) {
      _recoveryAttempts++
      _scheduleRecovery()

      return
    }
    connect()
  }

  function _onVisible() {
    if (!_doc || _doc.visibilityState === 'hidden') return
    _stopWaitingVisible()
    _attemptRecovery()
  }

  function _stopWaitingVisible() {
    if (_waitingVisible && _doc) _doc.removeEventListener('visibilitychange', _onVisible)
    _waitingVisible = false
  }

  function disconnect() {
    _destroyed = true
    _cleanup()

    if (_debounceTimer) {
      clearTimeout(_debounceTimer)
      _flushInvalidations() // Flush any pending invalidations
    }
  }

  function _closeSource() {
    if (_eventSource) {
      _eventSource.close()
      _eventSource = null
    }
  }

  function _cleanup() {
    if (_reconnectTimer) {
      clearTimeout(_reconnectTimer)
      _reconnectTimer = null
    }
    if (_recoveryTimer) {
      clearTimeout(_recoveryTimer)
      _recoveryTimer = null
    }
    _stopWaitingVisible()
    _closeSource()
  }

  return {
    connect,
    disconnect,
    get connected() { return _connected },
    get destroyed() { return _destroyed },
    get fallback() { return _fallback },
    get pendingTimers() { return Number(!!_reconnectTimer) + Number(!!_recoveryTimer) + Number(_waitingVisible) },
  }
}
