// ADR-839 (Leezr 4.2 M3) — explanation of an unavailable module (permission vs activation vs plan never confused).
// Run : node --test tests/js/
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { MODULE_STATE_ACTION, moduleAvailabilityState, moduleDependencies } from '../../resources/js/core/modules/moduleAvailability.js'

test('compatible but not activated → inactive (B), whatever the entitlement source', () => {
  assert.equal(moduleAvailabilityState({ key: 'workforce_planning', is_active: false, display_state: 'available' }), 'inactive')
  assert.equal(moduleAvailabilityState({ key: 'logistics_shipments', is_active: false, display_state: 'included' }), 'inactive')
})

test('not in the plan → plan (C) ; paid option / on request → option (C)', () => {
  assert.equal(moduleAvailabilityState({ is_active: false, display_state: 'locked_plan' }), 'plan')
  assert.equal(moduleAvailabilityState({ is_active: false, display_state: 'locked_addon' }), 'option')
  assert.equal(moduleAvailabilityState({ is_active: false, display_state: 'contact_sales' }), 'option')
})

test('unknown module (hidden, incompatible activity) → unavailable, no action', () => {
  assert.equal(moduleAvailabilityState(null), 'unavailable')
  assert.equal(moduleAvailabilityState({ is_active: false, display_state: 'system' }), 'unavailable')
  assert.equal(MODULE_STATE_ACTION.unavailable, undefined)
})

test('an active module is never reported as missing', () => {
  assert.equal(moduleAvailabilityState({ is_active: true, display_state: 'locked_plan' }), 'active')
})

test('each action needs its own right : activation = modules.manage, plans = billing.manage', () => {
  assert.equal(MODULE_STATE_ACTION.inactive, 'modules.manage')
  assert.equal(MODULE_STATE_ACTION.plan, 'billing.manage')
  assert.equal(MODULE_STATE_ACTION.option, 'modules.manage')
})

test('dependencies are listed with their activation state', () => {
  const active = new Set(['workforce'])

  assert.deepEqual(moduleDependencies({ requires: ['workforce', 'workforce_documents'] }, k => active.has(k)),
    [{ key: 'workforce', ok: true }, { key: 'workforce_documents', ok: false }])
  assert.deepEqual(moduleDependencies({}, () => true), [])
})
