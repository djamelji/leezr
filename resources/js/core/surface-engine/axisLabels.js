/**
 * ADR-856 (Leezr 5.0 L4) — category axis labels thinned to the measured width (room per label derived from the label length,
 * always the first and the last) : no overlapping dates on narrow or long series. Tooltips keep the full labels.
 */
export function thinCategories(categories, width, perLabel = null) {
  const c = categories || []

  // room per label from the real label length (≈ 7 px a character + 20 px gap), at least 36 px
  const avgLen = c.length ? c.reduce((n, v) => n + String(v ?? '').length, 0) / c.length : 1
  const room = perLabel ?? Math.max(36, Math.round(avgLen * 7 + 20))
  const max = Math.max(3, Math.floor((Number(width) || 600) / room))
  if (c.length <= max) return c
  const step = Math.ceil(c.length / max)

  return c.map((v, i) => (i % step === 0 || i === c.length - 1 ? v : ''))
}
