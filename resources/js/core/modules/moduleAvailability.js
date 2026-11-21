// ADR-839 (Leezr 4.2 M3) — which explanation a module address gets when the company cannot use it.
// Pure (no store, no i18n) : reads the module catalogue entry already served by GET /modules (ADR-163 display_state).
//   inactive    — B : available / included but not activated
//   plan        — C : requires another plan (locked_plan)
//   option      — C : paid option or on request (locked_addon / contact_sales)
//   active      — the module is in fact active (stale link)
//   unavailable — unknown for this company (hidden, incompatible activity)
export function moduleAvailabilityState(mod) {
  if (!mod) return 'unavailable'
  if (mod.is_active) return 'active'
  if (mod.display_state === 'locked_plan') return 'plan'
  if (['locked_addon', 'contact_sales'].includes(mod.display_state)) return 'option'
  if (['available', 'included'].includes(mod.display_state)) return 'inactive'

  return 'unavailable'
}

// The only action each state may offer, and the right it needs (the server checks it again).
export const MODULE_STATE_ACTION = {
  inactive: 'modules.manage',
  plan: 'billing.manage',
  option: 'modules.manage',
}

/** Dependencies of the module with their activation state (for the « it relies on » list). */
export function moduleDependencies(mod, isActive) {
  return (mod?.requires || []).map(key => ({ key, ok: Boolean(isActive(key)) }))
}
