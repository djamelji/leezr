import { createApp } from 'vue'
import App from '@/App.vue'
import { registerPlugins } from '@core/utils/plugins'
import { initErrorReporter } from '@/core/runtime/errorReporter'
import { startVersionPolling } from '@/utils/versionCheck'
import { router } from '@/plugins/1.router'

// Styles
import '@core-scss/template/index.scss'
import '@styles/styles.scss'

// ─── Global error monitoring (ADR-046 F2) ────────────
initErrorReporter()

// ─── bfcache guard (ADR-075) ─────────────────────────────
// Chrome's back-forward cache preserves entire JS state (including stuck
// overlays, stale timers, zombie event listeners). When the page is
// restored from bfcache, force a full reload to guarantee clean state.
// This is the ROOT CAUSE of the "overlay persists after refresh but new
// tab works" bug — bfcache restoration ≠ real page reload.
window.addEventListener('pageshow', event => {
  if (event.persisted) {
    window.location.reload()
  }
})

// ─── Chunk Resilience (ADR-045d + ADR-075 + ADR-046 F3 + ADR-330) ──
// CRITICAL: Module-level flag — ensures handleChunkError fires ONCE per page load.
// Multiple async components can fail simultaneously (e.g. Vuetify virtual modules after
// Vite restart), each triggering a separate unhandledrejection. Without this flag,
// each call would create its own retry timer → cascading reloads → multiple popups.
let _chunkErrorHandled = false

function reportChunkFailure(message) {
  try {
    const payload = JSON.stringify({
      type: 'chunk_load_failure',
      message: String(message).slice(0, 2000),
      url: window.location.href,
      user_agent: navigator.userAgent,
      timestamp: new Date().toISOString(),
      build_version: window.__APP_VERSION__ || null,
    })

    if (navigator.sendBeacon) {
      navigator.sendBeacon('/api/runtime-error', new Blob([payload], { type: 'application/json' }))
    }
  }
  catch {
    // Fire-and-forget — never block the reload
  }
}

function handleChunkError(errorMessage) {
  // ADR-330c: Single-fire — only the FIRST chunk error per page load takes action
  if (_chunkErrorHandled) return
  _chunkErrorHandled = true

  reportChunkFailure(errorMessage || 'Unknown chunk error')

  // Delegate to Blade's centralized overlay (single-fire guard + lzr:stale persistence).
  if (typeof window.__lzrShowVersionOverlay === 'function') {
    window.__lzrShowVersionOverlay()
  }
}

window.addEventListener('vite:preloadError', event => {
  event.preventDefault()
  handleChunkError(event.payload?.message || 'vite:preloadError')
})

window.addEventListener('unhandledrejection', event => {
  const msg = String(event.reason?.message || event.reason || '')
  if (msg.includes('Failed to fetch dynamically imported module') || msg.includes('ChunkLoadError')) {
    event.preventDefault()
    handleChunkError(msg)
  }
})

// Create vue app
const app = createApp(App)

// Register plugins
registerPlugins(app)

// Mount vue app
app.mount('#app')

// ─── Post-mount cleanup ──────────────────────────────────
// Cancel the Blade failsafe timer — Vue mounted successfully.
if (window.__LZR_BOOT_TIMER__) clearTimeout(window.__LZR_BOOT_TIMER__)

// Layout overlays (nav drawer) — always clean up, unrelated to chunk errors.
document.querySelectorAll('.layout-overlay.visible').forEach(el => {
  el.click()
})

// ADR-330c: Health check in 2 phases (replaces the unreliable immediate cleanup).
// Phase 1 — router.isReady(): waits for initial route resolution. Route-level chunk
//   errors have already fired _chunkErrorHandled / __lzrOverlayFired by this point.
// Phase 2 — requestIdleCallback: waits for browser idle (no pending tasks).
//   Covers sub-component lazy imports that fail after mount. 5s timeout as safety net.
// Why not a fixed setTimeout? app.mount() is synchronous — it completes BEFORE async
// fetch 404s (e.g., Vuetify virtual modules). A fixed timer risks running too early.
router.isReady().then(() => {
  const cleanup = () => {
    if (_chunkErrorHandled || window.__lzrOverlayFired) return
    document.getElementById('lzr-chunk-error')?.remove()
    sessionStorage.removeItem('lzr:stale')
  }

  if (typeof requestIdleCallback === 'function') {
    requestIdleCallback(cleanup, { timeout: 5000 })
  }
  else {
    setTimeout(cleanup, 3000)
  }
})

// ADR-330: Start live version polling — detects server restarts and new deploys.
startVersionPolling()

// ─── Dev server restart detection (ADR-330) ────────────────
// In dev mode, Vite HMR WebSocket disconnect = server restart.
// Delayed activation (15s) — avoids false positives during initial HMR connection.
// In production, import.meta.hot is undefined — this block is tree-shaken.
if (import.meta.hot) {
  setTimeout(() => {
    let wsDisconnectTimer = null

    import.meta.hot.on('vite:ws:disconnect', () => {
      if (wsDisconnectTimer) return
      wsDisconnectTimer = setTimeout(() => {
        if (typeof window.__lzrShowVersionOverlay === 'function') {
          window.__lzrShowVersionOverlay()
        }
      }, 5000)
    })

    import.meta.hot.on('vite:ws:connect', () => {
      if (wsDisconnectTimer) {
        clearTimeout(wsDisconnectTimer)
        wsDisconnectTimer = null
      }
    })
  }, 15000)
}
