// ADR-856 (Leezr 5.0 L4) — x-axis labels thinned to the width : no overlapping dates.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { thinCategories } from '../../resources/js/core/surface-engine/axisLabels.js'

const days = Array.from({ length: 30 }, (_, i) => `${i + 1} nov.`)

test('short series are untouched', () => {
  assert.deepEqual(thinCategories(['L', 'M', 'M', 'J', 'V', 'S', 'D'], 300), ['L', 'M', 'M', 'J', 'V', 'S', 'D'])
})

test('30 daily labels on a 340 px chart keep at most ~5 visible, including first and last', () => {
  const out = thinCategories(days, 340)
  const visible = out.filter(Boolean)
  assert.ok(visible.length <= 6, `visible ${visible.length}`)
  assert.equal(out[0], '1 nov.')
  assert.equal(out.at(-1), '30 nov.')
  assert.equal(out.length, 30, 'one slot per point (tooltips keep the real label)')
})

test('wider chart shows more labels, never fewer than 3', () => {
  assert.ok(thinCategories(days, 1100).filter(Boolean).length > thinCategories(days, 340).filter(Boolean).length)
  assert.ok(thinCategories(days, 50).filter(Boolean).length >= 3)
})
