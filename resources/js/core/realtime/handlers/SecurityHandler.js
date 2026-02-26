/**
 * SecurityHandler — pushes security alert events to the store.
 *
 * ADR-126: Security events from SSE are forwarded to the security
 * alert store for real-time display on the platform security dashboard.
 * Only active when platform scope is detected.
 */

export function createSecurityHandler(getSecurityAlertStore) {
  function dispatch(envelope) {
    const store = getSecurityAlertStore()
    if (store) {
      store._push(envelope)
    }
  }

  return { dispatch }
}
