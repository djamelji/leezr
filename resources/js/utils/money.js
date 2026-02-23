import { useWorldStore } from '@/core/stores/world'

/**
 * Format an integer amount in cents to a human-readable currency string.
 * Defaults are read from the world store (platform-wide settings).
 *
 * @param {number} cents - Amount in smallest currency unit (e.g. cents)
 * @param {object} options
 * @param {string} options.currency - ISO 4217 currency code (falls back to world store)
 * @param {string} options.locale - BCP 47 locale tag (falls back to world store)
 * @returns {string} Formatted currency string (e.g. "$29.00", "29,00 €")
 */
export function formatMoney(cents, { currency, locale } = {}) {
  if (typeof cents !== 'number') return ''

  const world = useWorldStore()

  return new Intl.NumberFormat(locale || world.locale, {
    style: 'currency',
    currency: currency || world.currency,
  }).format(cents / 100)
}
