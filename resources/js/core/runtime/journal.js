/**
 * Runtime Event Journal — ring buffer for debug observability.
 *
 * Records phase transitions, job lifecycle, broadcast events.
 * Available via RuntimeDebugPanel (Ctrl+Shift+D).
 */

/**
 * @typedef {Object} JournalEntry
 * @property {string}  type   - Event type (e.g. 'phase:transition', 'job:start')
 * @property {Object}  data   - Event-specific payload
 * @property {number}  ts     - Timestamp (Date.now())
 */

/**
 * Create a journal instance (ring buffer).
 * @param {number} [maxEntries=200]
 * @returns {{ log, entries, clear }}
 */
export function createJournal(maxEntries = 200) {
  /** @type {JournalEntry[]} */
  let buffer = []

  return {
    /**
     * Log an event.
     * @param {string} type
     * @param {Object} [data={}]
     */
    log(type, data = {}) {
      buffer.push({ type, data, ts: Date.now() })
      if (buffer.length > maxEntries) {
        buffer = buffer.slice(-maxEntries)
      }
    },

    /**
     * Get all entries, optionally filtered by type.
     * @param {string} [filterType] - If provided, only return entries of this type
     * @returns {JournalEntry[]}
     */
    entries(filterType) {
      if (filterType) {
        return buffer.filter(e => e.type === filterType)
      }

      return [...buffer]
    },

    /**
     * Clear all entries.
     */
    clear() {
      buffer = []
    },
  }
}
