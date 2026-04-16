/**
 * ADR-434: Topic → Handler mapping.
 *
 * Each topic defines:
 * - handler(envelope): function called when a domain event arrives
 * - invalidates: cache keys to clear (triggers store refetch via runtime)
 * - ux: 'silent' | 'toast' | 'badge' — how to notify the user
 *
 * The runtime's domain event handler dispatches through this registry
 * BEFORE passing to DomainEventBus (which stores subscribe to directly).
 *
 * ADR-434b: Consolidated — sub-topics (member.joined, module.activated, etc.)
 * merged into parent topics. Parent topics get 'toast' UX for perceptible feedback.
 */

/**
 * @typedef {Object} TopicHandler
 * @property {string[]} invalidates - Cache keys to clear
 * @property {'silent'|'toast'|'badge'} ux - UX behavior
 * @property {string} [toastKey] - i18n key for toast message
 * @property {string} [description] - Human-readable purpose
 */

/** @type {Record<string, TopicHandler>} */
export const TOPIC_HANDLERS = {
  // ─── Structural invalidation topics ──────────────────
  'rbac.changed': {
    invalidates: ['features:nav', 'auth:companies'],
    ux: 'toast',
    toastKey: 'realtime.rbacChanged',
    description: 'Role permissions changed → refresh nav + companies',
  },
  'modules.changed': {
    invalidates: ['features:nav', 'features:modules'],
    ux: 'toast',
    toastKey: 'realtime.modulesChanged',
    description: 'Module activation changed → refresh nav + modules',
  },
  'plan.changed': {
    invalidates: ['features:nav', 'features:modules', 'auth:companies'],
    ux: 'toast',
    toastKey: 'realtime.planChanged',
    description: 'Plan upgraded/downgraded → refresh all features',
  },
  'jobdomain.changed': {
    invalidates: ['features:nav', 'features:modules', 'tenant:jobdomain'],
    ux: 'toast',
    toastKey: 'realtime.jobdomainChanged',
    description: 'Jobdomain changed → refresh nav + modules + jobdomain',
  },
  'members.changed': {
    invalidates: ['auth:companies'],
    ux: 'toast',
    toastKey: 'realtime.membersChanged',
    description: 'Member added/removed/updated → refresh companies',
  },

  // ─── Domain events (consumed by stores via DomainEventBus) ──
  'document.updated': {
    invalidates: [],
    ux: 'silent',
    description: 'Document status changed — handled by store subscription',
  },
  'billing.updated': {
    invalidates: [],
    ux: 'silent',
    description: 'Billing state changed — handled by store subscription',
  },
  'automation.updated': {
    invalidates: [],
    ux: 'silent',
    description: 'Automation rule changed',
  },
  'automation.run.completed': {
    invalidates: [],
    ux: 'silent',
    description: 'Scheduled task completed (platform scope)',
  },
}

/**
 * Get the handler config for a topic.
 * @param {string} topic
 * @returns {TopicHandler|null}
 */
export function getTopicHandler(topic) {
  return TOPIC_HANDLERS[topic] || null
}

/**
 * Get invalidation keys for a topic.
 * @param {string} topic
 * @returns {string[]}
 */
export function getTopicInvalidationKeys(topic) {
  return TOPIC_HANDLERS[topic]?.invalidates || []
}
