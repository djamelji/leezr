import { useWorldStore } from '@/core/stores/world'
import { getI18n } from '@/plugins/i18n'

/**
 * ADR-823 — locale used to format dates : the market locale (fr-FR, en-GB…) gives the regional conventions,
 * but the LANGUAGE follows the interface. A user who chose English in a French company reads « Nov 18, 2026 »,
 * not « 18 nov. 2026 ». Same i18n source as the rest of the interface (no third localisation logic).
 */
export function displayLocale() {
  const world = useWorldStore()
  const market = world.locale || 'en-US'
  let ui = null
  try { ui = String(getI18n().global.locale.value || '').slice(0, 2) }
  catch { ui = null }
  if (!ui || market.toLowerCase().startsWith(ui))
    return market
  const region = market.split('-')[1]

  return region ? `${ui}-${region}` : ui
}

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

  return new Intl.DateTimeFormat(locale || displayLocale(), {
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

  return new Intl.DateTimeFormat(locale || displayLocale(), {
    timeZone: timeZone || world.timezone,
    dateStyle: 'medium',
  }).format(new Date(isoString))
}
