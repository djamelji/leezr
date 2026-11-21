// ADR-844 (Leezr 4.2 M7) — time export period presets and warnings. Run : node --test tests/js/
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { exportPeriod, exportWarnings } from '../../resources/js/modules/company/workforce/timeExport.js'

const wed = new Date(2026, 2, 11) // Wednesday 11 March 2026

test('weeks start on Monday', () => {
  assert.deepEqual(exportPeriod('this_week', wed), { from: '2026-03-09', to: '2026-03-15' })
  assert.deepEqual(exportPeriod('last_week', wed), { from: '2026-03-02', to: '2026-03-08' })
  assert.deepEqual(exportPeriod('this_week', new Date(2026, 2, 15)), { from: '2026-03-09', to: '2026-03-15' }, 'Sunday belongs to the same week')
})

test('months, including year change and February', () => {
  assert.deepEqual(exportPeriod('this_month', wed), { from: '2026-03-01', to: '2026-03-31' })
  assert.deepEqual(exportPeriod('last_month', wed), { from: '2026-02-01', to: '2026-02-28' })
  assert.deepEqual(exportPeriod('last_month', new Date(2026, 0, 5)), { from: '2025-12-01', to: '2025-12-31' })
})

test('custom keeps the typed dates', () => {
  assert.deepEqual(exportPeriod('custom', wed, { from: '2026-01-10', to: '2026-01-20' }), { from: '2026-01-10', to: '2026-01-20' })
  assert.deepEqual(exportPeriod('custom', wed), { from: '', to: '' })
})

test('server warnings become typed messages', () => {
  assert.deepEqual(exportWarnings(['unvalidated:3', 'too_large', 'empty']), [
    { key: 'unvalidated', n: 3, type: 'warning' }, { key: 'too_large', n: null, type: 'error' }, { key: 'empty', n: null, type: 'info' },
  ])
})
