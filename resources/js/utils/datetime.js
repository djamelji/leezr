import { useWorldStore } from '@/core/stores/world'

/**
 * Format an ISO date string to a human-readable date/time string.
 * Defaults are read from the world store (platform-wide settings).
 *
 * @param {string} isoString - ISO 8601 date string
 * @param {object} options
 * @param {string} options.locale - BCP 47 locale tag (falls back to world store)
 * @param {string} options.timeZone - IANA timezone (falls back to world store)
 * @returns {string} Formatted date string (e.g. "Feb 21, 2026, 3:30 PM")
 */
export function formatDateTime(isoString, { locale, timeZone } = {}) {
  if (!isoString) return ''

  const world = useWorldStore()

  return new Intl.DateTimeFormat(locale || world.locale, {
    timeZone: timeZone || world.timezone,
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(isoString))
}

/**
 * Format an ISO date string to a date-only string (no time).
 * Defaults are read from the world store (platform-wide settings).
 *
 * @param {string} isoString - ISO 8601 date string
 * @param {object} options
 * @param {string} options.locale - BCP 47 locale tag (falls back to world store)
 * @param {string} options.timeZone - IANA timezone (falls back to world store)
 * @returns {string} Formatted date string (e.g. "Feb 21, 2026")
 */
export function formatDate(isoString, { locale, timeZone } = {}) {
  if (!isoString) return ''

  const world = useWorldStore()

  return new Intl.DateTimeFormat(locale || world.locale, {
    timeZone: timeZone || world.timezone,
    dateStyle: 'medium',
  }).format(new Date(isoString))
}
