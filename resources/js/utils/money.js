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

/**
 * W-G1 (ADR-641) — parse a user-typed monetary amount into integer cents.
 *
 * Accepts locale-formatted input ("2 500,00", "2500.5", "2 500", "1 234,56 €").
 * Uses pure integer arithmetic — NEVER multiplies a float by 100 — so no binary
 * rounding drift (e.g. 2500.50 → 250050, not 250049.99…). The last ',' or '.' is
 * treated as the decimal separator; any other separators are grouping noise.
 *
 * @param {string|number|null} input
 * @returns {number|null} integer cents, or null when the input is empty/invalid
 */
export function parseMoneyToCents(input) {
  if (input === null || input === undefined) return null
  if (typeof input === 'number') return Number.isFinite(input) ? Math.round(input * 100) : null

  // Strip spaces (incl. NBSP / narrow NBSP), currency symbols and letters.
  let s = String(input).replace(/[\s  ]/g, '').replace(/[^\d.,-]/g, '')
  if (s === '' || s === '-') return null

  const negative = s.startsWith('-')
  if (negative) s = s.slice(1)

  // Last occurrence of ',' or '.' = decimal separator; everything else = grouping.
  const lastComma = s.lastIndexOf(',')
  const lastDot = s.lastIndexOf('.')
  const decPos = Math.max(lastComma, lastDot)

  let intPart
  let decPart
  if (decPos === -1) {
    intPart = s.replace(/[.,]/g, '')
    decPart = ''
  } else {
    intPart = s.slice(0, decPos).replace(/[.,]/g, '')
    decPart = s.slice(decPos + 1).replace(/[.,]/g, '')
  }

  if (intPart === '' && decPart === '') return null
  if (!/^\d*$/.test(intPart) || !/^\d*$/.test(decPart)) return null

  const cents2 = (decPart + '00').slice(0, 2) // pad/truncate to exactly 2 digits
  const cents = (parseInt(intPart || '0', 10) * 100) + parseInt(cents2 || '0', 10)

  return negative ? -cents : cents
}

/**
 * W-G1 (ADR-641) — render integer cents as a plain editable decimal string
 * (locale decimal separator, NO grouping/symbol) for use inside an input field.
 *
 * @param {number|null} cents
 * @param {object} [options]
 * @param {string} [options.locale]
 * @returns {string}
 */
export function centsToInput(cents, { locale } = {}) {
  if (typeof cents !== 'number' || !Number.isFinite(cents)) return ''

  const world = useWorldStore()
  const sep = (1.1).toLocaleString(locale || world.locale).includes(',') ? ',' : '.'
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)

  return `${sign}${Math.floor(abs / 100)}${sep}${String(abs % 100).padStart(2, '0')}`
}

/**
 * Symbol for an ISO currency in the active locale (e.g. "€", "MAD", "$").
 *
 * @param {object} [options]
 * @returns {string}
 */
export function currencySymbol({ currency, locale } = {}) {
  const world = useWorldStore()
  try {
    const parts = new Intl.NumberFormat(locale || world.locale, {
      style: 'currency',
      currency: currency || world.currency,
    }).formatToParts(0)

    return parts.find(p => p.type === 'currency')?.value || (currency || world.currency)
  } catch {
    return currency || world.currency
  }
}
