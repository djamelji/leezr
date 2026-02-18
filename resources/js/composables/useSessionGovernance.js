import { ref, watch } from 'vue'
import { postBroadcast } from '@/core/runtime/broadcast'
import { useRuntimeStore } from '@/core/runtime/runtime'

const DOM_EVENTS = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart']
const THROTTLE_MS = 1000

function getXsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/)

  return match ? decodeURIComponent(match[1]) : ''
}

export function useSessionGovernance() {
  const isWarningVisible = ref(false)
  const remainingSeconds = ref(0)

  // Internal state
  let serverTTL = 0
  let lastSyncTime = 0
  let isActive = false
  let warningThresholdSec = 0

  // Timer references (for strict cleanup)
  let _tickId = null
  let _heartbeatId = null
  let _started = false

  // Stored references for cleanup
  let _scope = null
  let _config = null
  let _onLogout = null
  let _domHandler = null
  let _ttlEventHandler = null
  let _unwatchRuntime = null
  let _lastThrottleTime = 0

  // ── syncFromHeader — single resync entry point ──
  function syncFromHeader(ttlSeconds) {
    if (!ttlSeconds || isNaN(ttlSeconds)) return
    serverTTL = ttlSeconds
    lastSyncTime = Date.now()
    if (serverTTL > warningThresholdSec) {
      isWarningVisible.value = false
    }
  }

  // ── Tick — countdown interpolation (1s interval) ──
  function tick() {
    const elapsed = (Date.now() - lastSyncTime) / 1000
    const remaining = Math.max(0, serverTTL - elapsed)

    remainingSeconds.value = Math.ceil(remaining)

    if (remaining <= warningThresholdSec && remaining > 0) {
      isWarningVisible.value = true
    }

    if (remaining <= 0) {
      triggerLogout()
    }
  }

  // ── Heartbeat — keepalive if user active ──
  async function heartbeat() {
    if (!isActive) return // User idle → skip → TTL decays

    isActive = false // Reset for next period

    const base = _scope === 'platform' ? '/api/platform' : '/api'

    try {
      const res = await fetch(base + '/heartbeat', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Accept': 'application/json',
          'X-XSRF-TOKEN': getXsrfToken(),
        },
      })

      if (res.status === 401) {
        triggerLogout()

        return
      }

      const ttl = parseInt(res.headers.get('x-session-ttl'), 10)

      syncFromHeader(ttl)
      postBroadcast('session-extended', { ttl: serverTTL })
    }
    catch {
      // Network error — let countdown continue, next heartbeat will retry
    }
  }

  // ── triggerLogout — cleanup BEFORE logout ──
  async function triggerLogout() {
    stop() // Cleanup all timers first
    postBroadcast('session-expired')
    if (_onLogout) await _onLogout()
  }

  // ── start — with double-mount protection ──
  function start({ scope, config, onLogout }) {
    if (_started) stop() // Protection: hot reload / layout remount

    _started = true
    _scope = scope
    _config = config
    _onLogout = onLogout

    serverTTL = config.idle_timeout * 60
    lastSyncTime = Date.now()
    warningThresholdSec = config.warning_threshold * 60
    isActive = false

    // DOM activity tracking (passive, throttled)
    _lastThrottleTime = 0
    _domHandler = () => {
      const now = Date.now()
      if (now - _lastThrottleTime < THROTTLE_MS) return
      _lastThrottleTime = now
      isActive = true
    }

    DOM_EVENTS.forEach(event => {
      document.addEventListener(event, _domHandler, { passive: true })
    })

    // CustomEvent listener for TTL resync from API interceptors
    _ttlEventHandler = e => {
      if (e.detail?.ttl) syncFromHeader(e.detail.ttl)
    }
    window.addEventListener('lzr:session-ttl', _ttlEventHandler)

    // Cross-tab sync via runtime store
    const runtime = useRuntimeStore()

    _unwatchRuntime = watch(
      () => runtime._sessionTTLSyncedAt,
      () => {
        if (runtime._sessionTTL) {
          syncFromHeader(runtime._sessionTTL)
        }
      },
    )

    // Start intervals
    _tickId = setInterval(tick, 1000)
    _heartbeatId = setInterval(heartbeat, config.heartbeat_interval * 60 * 1000)
  }

  // ── stop — strict cleanup of ALL resources ──
  function stop() {
    if (!_started) return

    _started = false

    // Clear intervals
    if (_tickId !== null) {
      clearInterval(_tickId)
      _tickId = null
    }
    if (_heartbeatId !== null) {
      clearInterval(_heartbeatId)
      _heartbeatId = null
    }

    // Remove DOM listeners
    if (_domHandler) {
      DOM_EVENTS.forEach(event => {
        document.removeEventListener(event, _domHandler)
      })
      _domHandler = null
    }

    // Remove CustomEvent listener
    if (_ttlEventHandler) {
      window.removeEventListener('lzr:session-ttl', _ttlEventHandler)
      _ttlEventHandler = null
    }

    // Remove runtime watcher
    if (_unwatchRuntime) {
      _unwatchRuntime()
      _unwatchRuntime = null
    }

    // Reset refs
    isWarningVisible.value = false
    remainingSeconds.value = 0
  }

  // ── extendSession — user clicked "Stay Connected" ──
  function extendSession() {
    isActive = true
    isWarningVisible.value = false
    heartbeat() // Force immediate heartbeat
  }

  return {
    isWarningVisible,
    remainingSeconds,
    start,
    stop,
    extendSession,
  }
}
