// ADR-844 (Leezr 4.2 M7) — pure helpers of the time export dialog (no store, no i18n).

const pad = n => String(n).padStart(2, '0')
const ymd = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` // local date, never UTC-shifted

/**
 * Period of a preset. Weeks start on Monday (ISO). `custom` returns the user's dates as typed.
 * @returns {{from: string, to: string}}
 */
export function exportPeriod(range, now = new Date(), custom = { from: '', to: '' }) {
  const d = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const monday = new Date(d)

  monday.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  switch (range) {
  case 'this_week': {
    const end = new Date(monday)

    end.setDate(monday.getDate() + 6)

    return { from: ymd(monday), to: ymd(end) }
  }
  case 'last_week': {
    const start = new Date(monday)

    start.setDate(monday.getDate() - 7)

    const end = new Date(monday)

    end.setDate(monday.getDate() - 1)

    return { from: ymd(start), to: ymd(end) }
  }
  case 'this_month':
    return { from: ymd(new Date(d.getFullYear(), d.getMonth(), 1)), to: ymd(new Date(d.getFullYear(), d.getMonth() + 1, 0)) }
  case 'last_month':
    return { from: ymd(new Date(d.getFullYear(), d.getMonth() - 1, 1)), to: ymd(new Date(d.getFullYear(), d.getMonth(), 0)) }
  default:
    return { from: custom?.from || '', to: custom?.to || '' }
  }
}

const LEVEL = { 'empty': 'info', 'large': 'warning', 'too_large': 'error', 'unvalidated': 'warning', 'pending_leaves': 'info', 'open_entries': 'warning' } // server codes

/** Server warning codes (« unvalidated:3 ») → { key, n, type } for the UI. */
export function exportWarnings(codes = []) {
  return codes.map(code => {
    const [key, n] = String(code).split(':')

    return { key, n: n ? Number(n) : null, type: LEVEL[key] || 'info' }
  })
}
