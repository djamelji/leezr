/**
 * Analytics composable — funnel instrumentation.
 *
 * Supports:
 * - Google Analytics 4 via window.dataLayer
 * - Console debug in development
 * - Extensible via adapter pattern
 */

const isDev = import.meta.env.DEV

function pushToDataLayer(event, properties) {
  if (typeof window !== 'undefined' && window.dataLayer) {
    window.dataLayer.push({ event, ...properties })
  }
}

function debugLog(type, event, properties) {
  if (isDev) {
    console.debug(`[analytics:${type}]`, event, properties)
  }
}

export function useAnalytics() {
  /**
   * Track a custom event.
   * @param {string} event - Event name (e.g. 'registration_started')
   * @param {Record<string, any>} properties - Event properties
   */
  function track(event, properties = {}) {
    const payload = { ...properties, timestamp: Date.now() }

    debugLog('track', event, payload)
    pushToDataLayer(event, payload)
  }

  /**
   * Identify a user.
   * @param {string|number} userId
   * @param {Record<string, any>} traits - User traits (plan, company, etc.)
   */
  function identify(userId, traits = {}) {
    const payload = { user_id: userId, ...traits }

    debugLog('identify', 'user', payload)
    pushToDataLayer('identify', payload)
  }

  /**
   * Track a page view.
   * @param {string} name - Page name
   * @param {Record<string, any>} properties
   */
  function page(name, properties = {}) {
    const payload = { page_name: name, ...properties }

    debugLog('page', name, payload)
    pushToDataLayer('page_view', payload)
  }

  return { track, identify, page }
}
