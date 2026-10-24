// ADR-435: Market-aware formatting composable
// ADR-616 (LOT 4): money formatting converges on the single `formatMoney` implementation to avoid
// two divergent formatters. `formatAmount` is kept as the market-aware alias (same signature).
import { useWorldStore } from '@/core/stores/world'
import { formatMoney } from '@/utils/money'

/**
 * Composable for market-aware formatting (currency, dates).
 *
 * Uses the worldStore which is synced with the current company's market
 * via auth._syncMarket() on boot and company switch.
 *
 * @example
 * const { formatAmount, formatDate, formatDateTime, currency, locale } = useMarketFormatting()
 * formatAmount(2900)           // "29,00 €" (FR) or "£29.00" (GB)
 * formatAmount(2900, 'GBP')    // "£29.00" (explicit override)
 * formatDate('2026-04-11')     // "11/04/2026" (FR) or "11/04/2026" (GB)
 */
export function useMarketFormatting() {
  const world = useWorldStore()

  const currency = computed(() => world.currency)
  const locale = computed(() => world.locale)
  const timezone = computed(() => world.timezone)

  /**
   * Format cents to a currency string.
   * @param {number} cents - Amount in smallest currency unit
   * @param {string} [currencyOverride] - Explicit currency code (overrides market)
   * @returns {string}
   */
  function formatAmount(cents, currencyOverride) {
    return formatMoney(cents, { currency: currencyOverride || world.currency, locale: world.locale })
  }

  /**
   * Format an ISO date string to a locale-aware date.
   * @param {string} isoString
   * @param {object} [options]
   * @param {string} [options.timeZone] - Override timezone
   * @returns {string}
   */
  function formatDate(isoString, options = {}) {
    if (!isoString) return ''

    return new Intl.DateTimeFormat(world.locale, {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      timeZone: options.timeZone || world.timezone,
    }).format(new Date(isoString))
  }

  /**
   * Format an ISO date string to a locale-aware datetime.
   * @param {string} isoString
   * @param {object} [options]
   * @param {string} [options.timeZone] - Override timezone
   * @returns {string}
   */
  function formatDateTime(isoString, options = {}) {
    if (!isoString) return ''

    return new Intl.DateTimeFormat(world.locale, {
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: options.timeZone || world.timezone,
    }).format(new Date(isoString))
  }

  return { formatAmount, formatDate, formatDateTime, currency, locale, timezone }
}
