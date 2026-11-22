// Leezr 5.0 cockpit (ADR-855) — shared formatting for the Platform cockpit widgets (amounts are in cents).
export function money(cents, currency = 'EUR', digits = 0) {
  if (cents === null || cents === undefined || Number.isNaN(Number(cents))) return '—'
  if (!currency || currency === 'MULTI') return (Number(cents) / 100).toFixed(digits)

  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency, maximumFractionDigits: digits, minimumFractionDigits: digits }).format(Number(cents) / 100)
}

export function pctChange(now, prev) {
  if (prev === null || prev === undefined || Number(prev) === 0) return null

  return Math.round(((Number(now) - Number(prev)) / Number(prev)) * 1000) / 10
}

export function relativeTime(iso) {
  if (!iso) return ''
  const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000)
  const rtf = new Intl.RelativeTimeFormat('fr', { numeric: 'auto' })
  if (Math.abs(s) < 60) return rtf.format(-s, 'second')
  if (Math.abs(s) < 3600) return rtf.format(-Math.round(s / 60), 'minute')
  if (Math.abs(s) < 86400) return rtf.format(-Math.round(s / 3600), 'hour')

  return rtf.format(-Math.round(s / 86400), 'day')
}

/** Apex colours from the Vuetify theme only (never hard-coded per widget). */
export function chartTheme(theme) {
  const v = theme.current.value
  const dark = v.dark

  return {
    dark,
    colors: v.colors,
    grid: dark ? 'rgba(255,255,255,.08)' : 'rgba(47,43,61,.08)',
    label: dark ? '#a3a6c4' : '#8c8798',
    strong: dark ? '#e6e8f5' : '#2f2b3d',
    muted: dark ? '#4a4f6e' : '#d6d9e6',
  }
}
