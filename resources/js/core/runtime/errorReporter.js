/**
 * Global error reporter (ADR-046 F2).
 * Captures unhandled JS errors and promise rejections,
 * sends them to /api/runtime-error via sendBeacon.
 */

const ENDPOINT = '/api/runtime-error'

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
