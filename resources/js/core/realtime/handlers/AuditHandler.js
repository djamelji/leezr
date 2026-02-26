/**
 * AuditHandler — pushes live audit events to the audit store.
 *
 * ADR-126: Audit events from SSE are forwarded to the audit live
 * store for real-time display on the platform audit dashboard.
 * Only active when platform scope is detected.
 */

export function createAuditHandler(getAuditLiveStore) {
  function dispatch(envelope) {
    const store = getAuditLiveStore()
    if (store) {
      store._push(envelope)
    }
  }

  return { dispatch }
}
