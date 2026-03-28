/**
 * useRealtimeSubscription — composable for auto-managed SSE subscriptions.
 *
 * ADR-427: Subscribes to domain event topics and auto-cleans up on unmount.
 *
 * Usage:
 *   useRealtimeSubscription('document.updated', (envelope) => {
 *     const { payload } = envelope
 *     store.handleRealtimeUpdate(payload)
 *   })
 *
 *   // Multiple topics:
 *   useRealtimeSubscription(['document.updated', 'billing.updated'], handler)
 */

import { onUnmounted } from 'vue'
import { domainEventBus } from './DomainEventBus'

/**
 * @param {string|string[]} topics - Topic(s) to subscribe to
 * @param {(envelope: Object) => void} callback - Handler called with envelope { topic, payload, ... }
 */
export function useRealtimeSubscription(topics, callback) {
  const topicList = Array.isArray(topics) ? topics : [topics]

  topicList.forEach(topic => domainEventBus.on(topic, callback))

  onUnmounted(() => {
    topicList.forEach(topic => domainEventBus.off(topic, callback))
  })
}
