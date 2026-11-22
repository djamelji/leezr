// ADR-855 (Leezr 5.0 L1) — presentation modes per widget FAMILY (same measurement as the Presentation Engine).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { listRows, resolveFamilyMode, resolveKpiMode, resolveListMode } from '../../resources/js/core/surface-engine/familyModes.js'
import { resolvePresentationMode } from '../../resources/js/core/surface-engine/presentationMode.js'

test('a KPI tile of 3 × 2 grid cells is readable (not micro), while the chart profile would make it micro', () => {
  // 3 columns of a 1130 px grid ≈ 270 px wide, 2 rows of 80 px + gap ≈ 176 px high
  assert.equal(resolvePresentationMode(270, 176), 'micro')
  assert.equal(resolveKpiMode(270, 176), 'standard')
})

test('KPI profile: micro only when really tiny, compact when narrow, wide/expanded when large', () => {
  assert.equal(resolveKpiMode(160, 176), 'micro')
  assert.equal(resolveKpiMode(300, 80), 'micro')
  assert.equal(resolveKpiMode(200, 176), 'compact')
  assert.equal(resolveKpiMode(560, 176), 'wide')
  assert.equal(resolveKpiMode(560, 260), 'expanded')
  assert.equal(resolveKpiMode(0, 0), 'standard', 'before the first measurement')
})

test('list profile and visible rows follow the real height', () => {
  assert.equal(resolveListMode(300, 120), 'micro')
  assert.equal(resolveListMode(360, 336), 'compact')
  assert.equal(resolveListMode(500, 336), 'standard')
  assert.equal(resolveListMode(800, 336), 'wide')
  assert.equal(listRows(336, 52, 76), 5)
  assert.equal(listRows(60), 1, 'never zero rows')
})

test('chart family delegates to the existing engine (no second engine)', () => {
  for (const [w, h] of [[300, 170], [420, 300], [620, 320], [940, 270], [1100, 420]])
    assert.equal(resolveFamilyMode('chart', w, h), resolvePresentationMode(w, h))
  assert.equal(resolveFamilyMode('unknown', 620, 320), resolvePresentationMode(620, 320))
})
