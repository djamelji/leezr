/**
 * NotificationHandler — pushes realtime notifications to the store.
 *
 * ADR-126: Notification events from SSE are forwarded to the
 * notification Pinia store for display in the UI.
 */

export function createNotificationHandler(getNotificationStore) {
  function dispatch(envelope) {
    const store = getNotificationStore()
    if (store) {
      store._push(envelope)
    }
  }

  return { dispatch }
}
