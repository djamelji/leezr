/**
 * BroadcastChannel multi-tab sync.
 *
 * Channel name: 'leezr-runtime'
 * Events: 'logout', 'company-switch', 'cache-invalidate'
 *
 * Graceful fallback: if BroadcastChannel is not supported, all functions no-op.
 */

const CHANNEL_NAME = 'leezr-runtime'

/** @type {BroadcastChannel|null} */
let channel = null

/**
 * Initialize the broadcast channel. Call once during runtime boot.
 * @param {Object} handlers
 * @param {Function} handlers.onLogout
 * @param {Function} handlers.onCompanySwitch - receives { companyId }
 * @param {Function} handlers.onCacheInvalidate - receives { keys }
 */
export function initBroadcast(handlers) {
  if (typeof BroadcastChannel === 'undefined') return
  if (channel) return // Already initialized

  channel = new BroadcastChannel(CHANNEL_NAME)

  channel.onmessage = event => {
    const { event: eventName, payload } = event.data || {}

    switch (eventName) {
    case 'logout':
      handlers.onLogout?.()
      break
    case 'company-switch':
      handlers.onCompanySwitch?.(payload)
      break
    case 'cache-invalidate':
      handlers.onCacheInvalidate?.(payload)
      break
    }
  }
}

/**
 * Post an event to all other tabs.
 * @param {string} event - 'logout' | 'company-switch' | 'cache-invalidate'
 * @param {Object} [payload={}]
 */
export function postBroadcast(event, payload = {}) {
  if (!channel) return

  channel.postMessage({
    event,
    payload,
    ts: Date.now(),
  })
}

/**
 * Close the channel (cleanup).
 */
export function closeBroadcast() {
  if (channel) {
    channel.close()
    channel = null
  }
}
