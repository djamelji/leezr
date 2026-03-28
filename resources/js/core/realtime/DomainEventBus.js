/**
 * DomainEventBus — global singleton for domain event subscriptions.
 *
 * ADR-427: Exposes the DomainHandler as a global bus that stores
 * and composables can subscribe to, without coupling to runtime.js internals.
 *
 * The runtime initializes this bus on SSE connect and clears it on disconnect.
 *
 * Usage in stores:
 *   import { domainEventBus } from '@/core/realtime/DomainEventBus'
 *   domainEventBus.on('document.updated', (envelope) => { ... })
 *
 * Usage in composables (auto-cleanup):
 *   import { useRealtimeSubscription } from '@/core/realtime/useRealtimeSubscription'
 *   useRealtimeSubscription('document.updated', (envelope) => { ... })
 */

import { createDomainHandler } from './handlers/DomainHandler'

// Global singleton — survives runtime reconnects
const _bus = createDomainHandler()

export const domainEventBus = {
  /**
   * Subscribe to a domain event topic.
   * @param {string} topic - e.g. 'document.updated', 'billing.updated'
   * @param {(envelope: Object) => void} callback
   */
  on: _bus.on,

  /**
   * Unsubscribe from a domain event topic.
   * @param {string} topic
   * @param {(envelope: Object) => void} callback
   */
  off: _bus.off,

  /**
   * Dispatch an event (called by runtime's ChannelRouter).
   * @param {Object} envelope - { topic, payload, ... }
   */
  dispatch: _bus.dispatch,

  /**
   * Clear all listeners (called on runtime teardown).
   */
  clear: _bus.clear,
}
