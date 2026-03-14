/**
 * ADR-330 / ADR-341: Version check — detects new production deploys.
 *
 * Architecture:
 *   - Single source of truth: sessionStorage 'lzr:update-shown' (survives reloads)
 *   - Cooldown after reload: 30s grace period to let new assets load
 *   - Deduplication: overlay shown at most ONCE until user explicitly refreshes
 *   - visibilitychange: debounced, respects cooldown
 *
 * Two entry points:
 *   1. checkVersionOnMount() — pre-flight for login pages (ADR-260)
 *   2. startVersionPolling() — polls /api/public/version every 60s
 */

let pollingTimer = null
let visibilityListenerAdded = false

// Cooldown: don't check version within 30s of page load.
// This prevents the reload loop: deploy → overlay → reload → overlay → reload...
const BOOT_TIME = Date.now()
const COOLDOWN_MS = 30_000

function isInCooldown() {
  return Date.now() - BOOT_TIME < COOLDOWN_MS
}

function wasAlreadyShown() {
  return sessionStorage.getItem('lzr:update-shown') === 'true'
}

function showOverlay() {
  // Already shown this session (survives reloads) — stop everything
  if (wasAlreadyShown()) return

  if (pollingTimer) { clearInterval(pollingTimer); pollingTimer = null }

  // Mark as shown BEFORE displaying — prevents any race
  sessionStorage.setItem('lzr:update-shown', 'true')

  // Delegate to Blade's centralized overlay
  if (typeof window.__lzrShowVersionOverlay === 'function') {
    window.__lzrShowVersionOverlay()
  }
}

async function checkVersion() {
  // Don't check during cooldown (just after a reload)
  if (isInCooldown()) return

  // Already shown — no point checking
  if (wasAlreadyShown()) return

  // Overlay already visible
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
    if (isInCooldown() || wasAlreadyShown()) return

    try {
      const res = await fetch('/health', { method: 'HEAD', cache: 'no-store' })
      const serverVersion = res.headers.get('x-build-version')

      if (serverVersion && serverVersion !== 'dev' && serverVersion !== clientVersion) {
        showOverlay()
      }
    }
    catch {}
  })
}

/**
 * Start version polling (production only — dev versions are always 'dev').
 * Polls every 60s + on visibilitychange (debounced).
 */
export function startVersionPolling() {
  if (pollingTimer) return

  pollingTimer = setInterval(checkVersion, 60_000)

  // visibilitychange: only add once (survives HMR)
  if (!visibilityListenerAdded) {
    visibilityListenerAdded = true

    let lastVisibilityCheck = 0

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState !== 'visible') return

      // Debounce: at most one check per 10s from visibility changes
      const now = Date.now()
      if (now - lastVisibilityCheck < 10_000) return
      lastVisibilityCheck = now

      checkVersion()
    })
  }
}

/**
 * Called after successful smart refresh to clear the session flag.
 * The next page load starts fresh and can detect future deploys.
 */
export function clearVersionState() {
  sessionStorage.removeItem('lzr:update-shown')
  sessionStorage.removeItem('lzr:stale')
  sessionStorage.removeItem('lzr:version-mismatch')
}
