// ADR-857 (Leezr 5.0 L3) — the workspace hero says only what the data proves.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { buildSituation, isQuiet, topPriorities, workspaceFocus } from '../../resources/js/core/surface-engine/workspaceSituation.js'

const keys = r => r.parts.map(p => p.key)

test('focus follows the active modules, never a JobDomain key', () => {
  assert.equal(workspaceFocus(['shipments.overview', 'workforce.headcount']), 'logistics')
  assert.equal(workspaceFocus(['workforce.headcount', 'compliance.rate']), 'workforce')
  assert.equal(workspaceFocus(['compliance.rate', 'billing.plan_badge']), 'general')
})

test('logistics day : deliveries progress, late and unassigned, presence', () => {
  const r = buildSituation({
    data: {
      'logistics.fleet': { scheduled_today: 14, completed_today: 9 },
      'shipments.overview': { today: 14, late: 2, unassigned: 1, total: 40 },
      'workforce.clock_live': { working: 5, on_break: 1 },
    },
    prioritiesState: 'ready',
    priorities: [{ key: 'x', count: 3 }],
  })

  assert.deepEqual(keys(r), ['l3.sit.deliveries', 'l3.sit.late', 'l3.sit.unassigned', 'l3.sit.working'])
  assert.deepEqual(r.progress, { value: 64, key: 'l3.progress.deliveries' })
  assert.equal(r.live, 6)
})

test('« nothing to do » only when the priorities API answered completely', () => {
  assert.ok(keys(buildSituation({ prioritiesState: 'ready', priorities: [] })).includes('l3.sit.nothingTodo'))
  assert.ok(!keys(buildSituation({ prioritiesState: 'ready', priorities: [], prioritiesPartial: true })).includes('l3.sit.nothingTodo'))
  assert.ok(!keys(buildSituation({ prioritiesState: 'error', priorities: [] })).includes('l3.sit.nothingTodo'))
  assert.ok(!keys(buildSituation({ prioritiesState: 'loading' })).includes('l3.sit.nothingTodo'))
})

test('an error payload is never read as zero', () => {
  const r = buildSituation({ data: { 'logistics.fleet': { error: true }, 'workforce.clock_live': { error: true } } })

  assert.deepEqual(keys(r), ['l3.sit.noActivity'])
  assert.equal(r.progress, null)
})

test('setup : onboarding progress drives the ring', () => {
  const r = buildSituation({ setup: { done: 2, total: 6 } })

  assert.equal(r.parts[0].key, 'l3.sit.setup')
  assert.deepEqual(r.progress, { value: 33, key: 'l3.progress.setup' })
})

test('quiet company : every known indicator empty ; unknown is not quiet', () => {
  assert.equal(isQuiet({ 'shipments.overview': { total: 0 }, 'workforce.headcount': { total: 1 }, 'planning.shifts': { total: 0 } }), true)
  assert.equal(isQuiet({ 'shipments.overview': { total: 3 }, 'workforce.headcount': { total: 1 } }), false)
  assert.equal(isQuiet({}), false)
  assert.equal(isQuiet({ 'shipments.overview': { error: true } }), false)
})

test('top priority : most severe, then the largest count, only actionable ones', () => {
  const p = [{ key: 'a', count: 9, severity: 'info', to: '/a' }, { key: 'b', count: 1, severity: 'error', to: '/b' }, { key: 'c', count: 5, severity: 'error' }]

  assert.deepEqual(topPriorities(p, 2).map(x => x.key), ['b', 'a'])
})
