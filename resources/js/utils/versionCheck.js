/**
 * ADR-330: Version check — detects new production deploys.
 *
 * Two mechanisms:
 *   1. checkVersionOnMount() — pre-flight for login pages (ADR-260)
 *   2. startVersionPolling() — polls /api/public/version every 60s
 *      Compares build_version — only triggers in production (dev = 'dev' both sides).
 *
 * For dev mode, server restarts are detected by:
 *   - Blade boot timer (10s) if Vue fails to mount
 *   - Script error listener if main.js fails to load
 *   - Chunk error handler if dynamic imports fail during navigation
 *   - Vite HMR WebSocket reconnect (built-in)
 */

let pollingTimer = null

function showOverlay() {
  if (pollingTimer) { clearInterval(pollingTimer); pollingTimer = null }

  // ADR-330c: Delegate to Blade's centralized overlay (single-fire guard + lzr:stale).
  if (typeof window.__lzrShowVersionOverlay === 'function') {
    window.__lzrShowVersionOverlay()
  }
}

async function checkVersion() {
  if (document.getElementById('lzr-chunk-error')) return

  try {
    const res = await fetch('/api/public/version', { cache: 'no-store' })
    const data = await res.json()
    const clientVersion = window.__APP_VERSION__

    // Only compare in production (both sides have real versions, not 'dev')
    if (clientVersion && clientVersion !== 'dev'
      && data.version && data.version !== 'dev'
      && data.version !== clientVersion) {
      showOverlay()
    }
  }
  catch {
    // Server unreachable — transient, don't show overlay
  }
}

/**
 * Pre-flight version check for login pages (ADR-260).
 */
export function checkVersionOnMount() {
  const clientVersion = import.meta.env.VITE_APP_VERSION
  if (!clientVersion || clientVersion === '__dev__') return

  onMounted(async () => {
    try {
      const res = await fetch('/health', { method: 'HEAD', cache: 'no-store' })
      const serverVersion = res.headers.get('x-build-version')

      if (serverVersion && serverVersion !== 'dev' && serverVersion !== clientVersion) {
        window.location.reload()
      }
    }
    catch {}
  })
}

/**
 * Start version polling (production only — dev versions are always 'dev').
 * Polls every 60s + on visibilitychange.
 */
export function startVersionPolling() {
  if (pollingTimer) return

  pollingTimer = setInterval(checkVersion, 60_000)

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      checkVersion()
    }
  })
}
