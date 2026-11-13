/**
 * Global error reporter (ADR-046 F2).
 * Captures unhandled JS errors and promise rejections,
 * sends them to /api/runtime-error via sendBeacon.
 */

const ENDPOINT = '/api/runtime-error'

const BENIGN_MESSAGES = [
  'ResizeObserver loop completed with undelivered notifications',
  'ResizeObserver loop limit exceeded',
]

function buildPayload(type, message, stack) {
  return JSON.stringify({
    type,
    message: String(message).slice(0, 2000),
    stack: stack ? String(stack).slice(0, 5000) : null,
    url: window.location.href,
    user_agent: navigator.userAgent,
    timestamp: new Date().toISOString(),
    build_version: window.__APP_VERSION__ || null,
  })
}

function send(payload) {
  if (navigator.sendBeacon) {
    navigator.sendBeacon(ENDPOINT, new Blob([payload], { type: 'application/json' }))
  }
}

export function initErrorReporter() {
  window.addEventListener('error', event => {
    // Skip chunk errors — handled separately by F3
    const msg = event.message || ''
    if (msg.includes('ChunkLoadError') || msg.includes('Loading chunk'))
      return

    // ADR-770 : avis BÉNIN du navigateur (une observation de redimensionnement reportée à l'image
    // suivante — ouverture d'un tiroir, d'un dialogue). Ce n'est pas une erreur applicative ; le
    // remonter noierait la télémétrie maintenant qu'elle fonctionne. Seul ce message exact est écarté.
    if (BENIGN_MESSAGES.some(m => msg.startsWith(m)))
      return

    send(buildPayload('js_error', msg, event.error?.stack))
  })

  window.addEventListener('unhandledrejection', event => {
    const reason = event.reason
    const msg = String(reason?.message || reason || 'Unknown rejection')

    // Skip chunk errors — handled separately by F3
    if (msg.includes('Failed to fetch dynamically imported module') || msg.includes('ChunkLoadError'))
      return

    send(buildPayload('unhandled_rejection', msg, reason?.stack))
  })
}
