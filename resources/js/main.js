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

// ─── ADR-342: Dev mode — clear stale session flags ─────────────────
// Prevents state stuck from previous overlay/retry iterations.
// In dev, the overlay system is disabled (Vite HMR handles reconnection),
// so these keys should never exist. Clear them defensively.
if (!import.meta.env.PROD) {
  sessionStorage.removeItem('lzr:update-shown')
  sessionStorage.removeItem('lzr:stale')
  sessionStorage.removeItem('lzr:version-mismatch')
  sessionStorage.removeItem('lzr:retry-count')
  // lzr:dev-retry is kept during boot (cleared in post-mount cleanup after successful boot)
  console.log('[lzr:version] Dev mode — cleared stale session flags, overlay system disabled')
}

// ─── Chunk Resilience (ADR-045d + ADR-075 + ADR-046 F3 + ADR-342) ──
// CRITICAL: Module-level flags for boot state management.
let _chunkErrorHandled = false
let _bootComplete = false

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
  // Single-fire — only the FIRST chunk error per page load takes action
  if (_chunkErrorHandled) return
  _chunkErrorHandled = true

  reportChunkFailure(errorMessage || 'Unknown chunk error')

  // ADR-342: Dev mode — boot vs post-boot handling
  if (!import.meta.env.PROD) {
    // Post-boot: router.onError handles SPA navigation retry (no page reload)
    if (_bootComplete) {
      _chunkErrorHandled = false // Allow future nav errors to be caught
      console.warn('[lzr:chunk] Post-boot chunk error — router will retry navigation')
      return
    }

    // Boot phase: auto-reload behind boot screen (invisible to user).
    // Boot screen is outside #app → persists through reload → no blinking.
    const devRetries = parseInt(sessionStorage.getItem('lzr:dev-retry') || '0', 10)
    if (devRetries < 3) {
      sessionStorage.setItem('lzr:dev-retry', String(devRetries + 1))
      const status = document.getElementById('lzr-boot-status')
      if (status) status.textContent = `Initialisation\u2026 (${devRetries + 1}/3)`
      console.warn(`[lzr:chunk] Chunk error in dev — auto-reload attempt ${devRetries + 1}/3:`, errorMessage)
      setTimeout(() => location.reload(), 2000)
    }
    else {
      sessionStorage.removeItem('lzr:dev-retry')
      const status = document.getElementById('lzr-boot-status')
      if (status) status.textContent = '\u00c9chec apr\u00e8s 3 tentatives. Ctrl+Shift+R pour forcer.'
      console.error('[lzr:chunk] 3 reload attempts failed. Try manual hard-refresh (Ctrl+Shift+R).')
    }

    return
  }

  // Production: Post-refresh → silent retry instead of showing another popup.
  if (sessionStorage.getItem('lzr:update-shown') === 'true') {
    const retryCount = parseInt(sessionStorage.getItem('lzr:retry-count') || '0', 10)
    if (retryCount < 5) {
      sessionStorage.setItem('lzr:retry-count', String(retryCount + 1))
      setTimeout(() => location.replace(location.pathname), 3000)
    }
    else {
      sessionStorage.removeItem('lzr:update-shown')
      sessionStorage.removeItem('lzr:retry-count')
      sessionStorage.removeItem('lzr:stale')
    }

    return
  }

  // Production normal: show overlay
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

// ADR-342: Signal to Blade scripts that Vue mounted successfully.
// Boot screen (outside #app) stays visible until full readiness confirmed.
window.__lzrVueMounted = true

// ─── Post-mount cleanup ──────────────────────────────────
// Cancel the Blade failsafe timers — Vue mounted successfully.
if (window.__LZR_BOOT_TIMER__) clearTimeout(window.__LZR_BOOT_TIMER__)
if (window.__LZR_AUTO_RETRY__) clearTimeout(window.__LZR_AUTO_RETRY__)

// Layout overlays (nav drawer) — always clean up, unrelated to chunk errors.
document.querySelectorAll('.layout-overlay.visible').forEach(el => {
  el.click()
})

// ADR-342: Boot readiness check — 2-phase health verification.
// Phase 1 — router.isReady(): initial route resolution complete.
// Phase 2 — requestIdleCallback: browser idle, all async modules settled.
// Only THEN remove boot screen → app visible. No blinking, no partial render.
router.isReady().then(() => {
  const finalizeBoot = () => {
    // Chunk error during THIS page load → boot screen stays, auto-reload handles it
    if (_chunkErrorHandled) return

    // ── Boot succeeded ──
    _bootComplete = true

    // Fade out and remove boot screen (outside #app, persistent overlay)
    const bootScreen = document.getElementById('lzr-boot-screen')
    if (bootScreen) {
      bootScreen.style.opacity = '0'
      setTimeout(() => bootScreen.remove(), 300)
    }

    // Remove any leftover version overlay
    document.getElementById('lzr-chunk-error')?.remove()

    // Clear ALL version/retry state
    sessionStorage.removeItem('lzr:stale')
    sessionStorage.removeItem('lzr:update-shown')
    sessionStorage.removeItem('lzr:version-mismatch')
    sessionStorage.removeItem('lzr:retry-count')
    sessionStorage.removeItem('lzr:dev-retry')
  }

  if (import.meta.env.DEV) {
    // Dev: Fixed 2s delay — requestIdleCallback fires before Vite virtual module
    // 404s arrive (network latency). 2s is enough for SASS imports to settle.
    setTimeout(finalizeBoot, 2000)
  }
  else if (typeof requestIdleCallback === 'function') {
    // Prod: Use idle callback (no virtual module issues with pre-built assets)
    requestIdleCallback(finalizeBoot, { timeout: 5000 })
  }
  else {
    setTimeout(finalizeBoot, 3000)
  }
})

// ADR-330: Start live version polling — detects server restarts and new deploys.
startVersionPolling()

// ─── Dev server restart detection (ADR-342) ────────────────
// In dev mode, Vite HMR WebSocket disconnect = server restart.
// ADR-342: No overlay in dev — Vite HMR auto-reconnects and triggers full-reload.
// Just log a warning after 30s if still disconnected.
// In production, import.meta.hot is undefined — this block is tree-shaken.
if (import.meta.hot) {
  setTimeout(() => {
    let wsDisconnectTimer = null

    import.meta.hot.on('vite:ws:disconnect', () => {
      if (wsDisconnectTimer) return
      console.warn('[lzr:hmr] Vite WS disconnected — waiting for reconnection...')
      wsDisconnectTimer = setTimeout(() => {
        console.warn('[lzr:hmr] Vite still disconnected after 30s. Restart pnpm dev:all if needed.')
      }, 30000)
    })

    import.meta.hot.on('vite:ws:connect', () => {
      if (wsDisconnectTimer) {
        clearTimeout(wsDisconnectTimer)
        wsDisconnectTimer = null
        console.log('[lzr:hmr] Vite WS reconnected')
      }
    })
  }, 15000)
}
