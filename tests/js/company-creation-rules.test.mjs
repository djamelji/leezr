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

// ── ADR-834 fix : step 4 « Subscription » blocked by a hidden, invalid override ───────────────────────────
import { overrideApplies, overrideErrors, overridePayload } from '../../resources/js/modules/platform-admin/companies/companyCreationRules.js'

const pro = { key: 'pro', is_free: false, trial_days: 14 }
const starter = { key: 'starter', is_free: true, trial_days: 0 }
const emptyOverride = { type: 'trial', trial_days: 30, complimentary_months: 3, reason: '' }

test('regression : an override enabled then hidden by a free plan does not apply (never validated, never sent)', () => {
  const state = { plan: starter, canOverride: true, overrideEnabled: true, override: emptyOverride }
  assert.equal(overrideApplies(state), false)
  assert.equal(overridePayload(state), null)
  assert.deepEqual(subscriptionOutcome({ ...state, trialUsed: false }), { status: 'active', kind: 'free' })
})

test('an override without the permission does not apply', () => {
  assert.equal(overrideApplies({ plan: pro, canOverride: false, overrideEnabled: true }), false)
  assert.equal(overridePayload({ plan: pro, canOverride: false, overrideEnabled: true, override: { ...emptyOverride, reason: 'Accord commercial signé' } }), null)
})

test('an applicable override is validated field by field, with the server bounds', () => {
  assert.deepEqual(overrideErrors(emptyOverride), { reason: true })
  assert.deepEqual(overrideErrors({ ...emptyOverride, reason: 'Accord commercial signé', trial_days: 0 }), { duration: { min: 1, max: 90 } })
  assert.deepEqual(overrideErrors({ ...emptyOverride, reason: 'Accord commercial signé', trial_days: '' }), { duration: { min: 1, max: 90 } })
  assert.deepEqual(overrideErrors({ type: 'complimentary', complimentary_months: 13, reason: 'Accord commercial signé' }), { duration: { min: 1, max: 12 } })
  assert.deepEqual(overrideErrors({ ...emptyOverride, reason: 'Accord commercial signé', trial_days: 45 }), {})
})

test('the payload of an applicable override carries only the chosen duration', () => {
  const state = { plan: pro, canOverride: true, overrideEnabled: true, override: { type: 'complimentary', trial_days: 30, complimentary_months: 3, reason: '  Partenaire stratégique  ' } }
  assert.deepEqual(overridePayload(state), { type: 'complimentary', reason: 'Partenaire stratégique', trial_days: null, complimentary_months: 3 })
})

// ── ADR-834 fix : SIRET never asked, then « SIRET missing » at the end ─────────────────────────────────────
import { companyFieldIssues } from '../../resources/js/modules/platform-admin/companies/companyCreationRules.js'

const fields = [
  { code: 'siret', label: 'SIRET', required: true, max: 14, pattern: null },
  { code: 'naf_code', label: 'Code NAF', required: false, max: 6, pattern: '/^\\d{2}\\.?\\d{2}[A-Za-z]$/' },
  { code: 'billing_email', label: 'E-mail de facturation', required: false, max: 254, pattern: 'email' },
]

test('required activity field (SIRET) is reported missing on the Company step', () => {
  assert.deepEqual(companyFieldIssues(fields, {}), { missing: ['SIRET'], tooLong: [], invalid: [] })
})

test('valid values pass : « / » pattern is a regex, « email » is only a rendering hint', () => {
  assert.deepEqual(companyFieldIssues(fields, { siret: '12345678901234', naf_code: '49.41A', billing_email: 'a@b.fr' }), { missing: [], tooLong: [], invalid: [] })
})

test('too long and wrong format are reported, like the server', () => {
  assert.deepEqual(companyFieldIssues(fields, { siret: '123456789012345', naf_code: 'ABC' }), { missing: [], tooLong: ['SIRET'], invalid: ['Code NAF'] })
})

// ── ADR-835 : JobDomain-configurable fields — defaults and select choices, same rule as the server ──────────
import { applyFieldDefaults } from '../../resources/js/modules/platform-admin/companies/companyCreationRules.js'

test('a default value satisfies a required field and is pre-filled without overwriting a typed value', () => {
  const f = [{ code: 'country_code', label: 'Pays', required: true, max: 2, default: 'FR' }, { code: 'city', label: 'Ville', required: false, default: null }]
  assert.deepEqual(companyFieldIssues(f, {}), { missing: [], tooLong: [], invalid: [] })
  assert.deepEqual(applyFieldDefaults(f, { city: 'Lyon' }), { country_code: 'FR', city: 'Lyon' })
  assert.deepEqual(applyFieldDefaults(f, { country_code: 'BE' }), { country_code: 'BE' })
})

test('a select value outside the choices is refused', () => {
  const f = [{ code: 'size', label: 'Taille', type: 'select', options: ['s', 'm'], required: false }]
  assert.deepEqual(companyFieldIssues(f, { size: 'xl' }).invalid, ['Taille'])
  assert.deepEqual(companyFieldIssues(f, { size: 'm' }).invalid, [])
})
