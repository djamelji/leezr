// ADR-834 — rules of the Platform « New company » wizard (module dependencies, subscription outcome).
// Run : node --test tests/js/
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { toggleModule, neededBy, keepSelectable, subscriptionOutcome, paidOptionsBlocked } from '../../resources/js/modules/platform-admin/companies/companyCreationRules.js'

const r = (key, name) => ({ key, name })
const modules = [
  { key: 'logistics_shipments', name: 'Expéditions', class: 'base', requires: [] },
  { key: 'workforce', name: 'RH', class: 'option', requires: [] },
  { key: 'workforce_leave', name: 'Congés', class: 'option', requires: [r('workforce', 'RH')] },
  { key: 'workforce_payroll', name: 'Paie', class: 'option', requires: [r('workforce', 'RH'), r('workforce_leave', 'Congés')] },
  { key: 'logistics_tracking', name: 'Suivi', class: 'with_dependency', requires: [r('logistics_shipments', 'Expéditions')] },
  { key: 'logistics_analytics', name: 'Analyses', class: 'plan_required', requires: [] },
]

test('selecting a module adds its selectable dependencies, never a base module', () => {
  assert.deepEqual(toggleModule([], 'workforce_payroll', true, modules).sort(), ['workforce', 'workforce_leave', 'workforce_payroll'])
  assert.deepEqual(toggleModule([], 'logistics_tracking', true, modules), ['logistics_tracking'])
})

test('removing a dependency removes every selected module that needs it, transitively', () => {
  const selected = ['workforce', 'workforce_leave', 'workforce_payroll', 'logistics_tracking']
  assert.deepEqual(toggleModule(selected, 'workforce', false, modules), ['logistics_tracking'])
  assert.deepEqual(toggleModule(selected, 'workforce_payroll', false, modules).sort(), ['logistics_tracking', 'workforce', 'workforce_leave'])
})

test('the « added because » explanation names the modules that need the dependency', () => {
  assert.deepEqual(neededBy(['workforce_leave', 'workforce_payroll', 'workforce'], modules).workforce, ['Congés', 'Paie'])
  assert.equal(neededBy(['workforce'], modules).workforce, undefined)
})

test('a plan change drops the modules no longer selectable and names them', () => {
  const after = modules.map(m => m.key === 'workforce_leave' ? { ...m, class: 'plan_required' } : m)
  assert.deepEqual(keepSelectable(['workforce', 'workforce_leave'], after), { kept: ['workforce'], dropped: ['Congés'] })
})

test('subscription outcome : never a payment, same order as the server', () => {
  const pro = { key: 'pro', is_free: false, trial_days: 14 }
  assert.deepEqual(subscriptionOutcome({ plan: { is_free: true } }), { status: 'active', kind: 'free' })
  assert.deepEqual(subscriptionOutcome({ plan: pro }), { status: 'trialing', kind: 'trial', days: 14 })
  assert.deepEqual(subscriptionOutcome({ plan: pro, trialUsed: true }), { status: 'pending_payment', kind: 'trial_used' })
  assert.deepEqual(subscriptionOutcome({ plan: { is_free: false, trial_days: 0 } }), { status: 'pending_payment', kind: 'no_trial' })
  // override ignored without the permission
  assert.equal(subscriptionOutcome({ plan: pro, trialUsed: true, overrideEnabled: true, override: { type: 'trial', trial_days: 30 } }).status, 'pending_payment')
  assert.deepEqual(subscriptionOutcome({ plan: pro, canOverride: true, overrideEnabled: true, override: { type: 'complimentary', complimentary_months: 3 } }), { status: 'active', kind: 'override_complimentary', months: 3 })
})

test('paid options are blocked outside a trial', () => {
  assert.equal(paidOptionsBlocked(['workforce'], modules, { status: 'trialing' }), false)
  assert.equal(paidOptionsBlocked(['workforce'], modules, { status: 'pending_payment' }), true)
  assert.equal(paidOptionsBlocked(['logistics_tracking'], modules, { status: 'active' }), false)
})
