// ADR-779 (Company Experience 2.0 / UX4) — unités MÉTIER des écrans Planning & temps.
// Les API gardent leurs unités exactes (minutes entières, centièmes de jour entiers) : ces fonctions
// ne font que les PRÉSENTER (et convertir une saisie en jours vers des centièmes, sans perte).
// Durée (temps de travail), quantité de congés (jours) et montant (centimes, voir money.js) ne se
// mélangent jamais.

/** Minutes entières → « 8 h », « 7 h 30 », « 45 min », « -1 h 15 ». */
export function formatMinutes(minutes) {
  if (minutes === null || minutes === undefined || Number.isNaN(Number(minutes))) return '—'
  const m = Math.round(Number(minutes))
  const sign = m < 0 ? '-' : ''
  const abs = Math.abs(m)
  const h = Math.floor(abs / 60)
  const r = abs % 60
  if (h === 0) return `${sign}${r} min`

  return r === 0 ? `${sign}${h} h` : `${sign}${h} h ${String(r).padStart(2, '0')}`
}

/** Centièmes de jour → nombre de jours (1, 0,5, 2,57) au format de la langue. */
export function hundredthsToDays(hundredths) {
  if (hundredths === null || hundredths === undefined || hundredths === '') return null

  return Number(hundredths) / 100
}

/** Jours saisis (« 1,5 », 0.5) → centièmes entiers, arrondis au centième. */
export function daysToHundredths(days) {
  if (days === null || days === undefined || days === '') return null
  const n = typeof days === 'string' ? Number(days.replace(',', '.')) : Number(days)
  if (!Number.isFinite(n)) return null

  return Math.round(n * 100)
}

/** Centièmes de jour → « 1 jour », « 0,5 jour », « 2,5 jours » (t = i18n). */
export function formatDays(hundredths, t, locale = 'fr') {
  const days = hundredthsToDays(hundredths)
  if (days === null || Number.isNaN(days)) return '—'
  const text = new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(days)

  return t('timeSpace.days', { n: text }, Math.abs(days) > 1 ? 2 : 1)
}
