/**
 * First-fit placement algorithm for dashboard widgets.
 *
 * Scans a 12-col grid top-to-bottom, left-to-right to find the first
 * position where a widget of size (w × h) fits without overlapping
 * existing tiles. Falls back to appending below all existing tiles.
 *
 * Shared by both platform and company dashboard stores (ADR-197).
 *
 * @param {import('./surfaceEngine.types').DashboardTile[]} layout
 * @param {number} w - Widget width in grid columns
 * @param {number} h - Widget height in grid rows
 * @param {number} [cols=12] - Grid column count
 * @returns {{ x: number, y: number }}
 */
export function firstFitPosition(layout, w, h, cols = 12) {
  const maxY = layout.length
    ? layout.reduce((max, t) => Math.max(max, t.y + t.h), 0)
    : 0

  for (let row = 0; row <= maxY; row++) {
    for (let col = 0; col <= cols - w; col++) {
      const hasOverlap = layout.some(t =>
        col < t.x + t.w && col + w > t.x
        && row < t.y + t.h && row + h > t.y,
      )

      if (!hasOverlap) return { x: col, y: row }
    }
  }

  return { x: 0, y: maxY }
}
