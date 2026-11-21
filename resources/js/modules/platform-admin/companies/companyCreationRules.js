// ADR-834 — pure rules of the Platform « New company » wizard (no Vue, no store) : module selection with
// dependencies, resulting subscription status. The server re-validates everything ; these rules only make the
// screen say the same thing as the server. Tested by tests/js/company-creation-rules.test.mjs.

export const SELECTABLE = ['included', 'option', 'with_dependency']

const index = modules => Object.fromEntries(modules.map(m => [m.key, m]))

/** Selectable dependencies of a module (base modules are already there, blocked ones are refused). */
export function selectableRequires(module, modules) {
  const byKey = index(modules)

  return (module?.requires || []).map(r => r.key).filter(k => SELECTABLE.includes(byKey[k]?.class))
}

/** Selecting a module selects its dependencies ; removing one removes every selected module that needs it. */
export function toggleModule(selected, key, on, modules) {
  const byKey = index(modules)
  const set = new Set(selected)
  if (on) {
    set.add(key)
    selectableRequires(byKey[key], modules).forEach(k => set.add(k))

    return [...set]
  }
  const drop = new Set([key])
  let grew = true
  while (grew) {
    grew = false
    for (const k of set) {
      if (!drop.has(k) && (byKey[k]?.requires || []).some(r => drop.has(r.key))) {
        drop.add(k)
        grew = true
      }
    }
  }
  drop.forEach(k => set.delete(k))

  return [...set]
}

/** For each selected dependency : the names of the selected modules that need it (the « added because » text). */
export function neededBy(selected, modules) {
  const byKey = index(modules)
  const map = {}
  for (const key of selected) {
    for (const dep of selectableRequires(byKey[key], modules))
      (map[dep] ||= []).push(byKey[key]?.name || key)
  }

  return map
}

/** Keys still selectable after the catalogue changed (plan change) and the names of the ones dropped. */
export function keepSelectable(selected, modules) {
  const byKey = index(modules)
  const kept = selected.filter(k => SELECTABLE.includes(byKey[k]?.class))
  const dropped = selected.filter(k => !kept.includes(k)).map(k => byKey[k]?.name || k)

  return { kept, dropped }
}

/**
 * Subscription status the server will create — never a payment. Same order as CreatePlatformCompanyUseCase :
 * free → active ; override (only with the permission) → trial or free period ; plan trial when the administrator
 * never had one → trialing ; otherwise awaiting payment.
 */
export function subscriptionOutcome({ plan, canOverride = false, overrideEnabled = false, override = {}, trialUsed = false }) {
  if (!plan)
    return null
  if (plan.is_free)
    return { status: 'active', kind: 'free' }
  if (overrideEnabled && canOverride) {
    return override.type === 'trial'
      ? { status: 'trialing', kind: 'override_trial', days: Number(override.trial_days) }
      : { status: 'active', kind: 'override_complimentary', months: Number(override.complimentary_months) }
  }
  if (plan.trial_days > 0 && !trialUsed)
    return { status: 'trialing', kind: 'trial', days: plan.trial_days }

  return { status: 'pending_payment', kind: trialUsed ? 'trial_used' : 'no_trial' }
}

/** Paid options at creation are accepted only during a trial (never invoiced at creation). */
export function paidOptionsBlocked(selected, modules, outcome) {
  const byKey = index(modules)

  return selected.some(k => byKey[k]?.class === 'option') && outcome?.status !== 'trialing'
}

/**
 * Start-of-service stages (server : CompanyOnboardingChecklist::stage) in their order, with the colour each one
 * takes everywhere (list, record). « Created » is never shown as « operational ».
 */
export const STAGES = ['created', 'administrator_invited', 'awaiting_payment', 'operational']
export const STAGE_COLORS = { created: 'secondary', administrator_invited: 'info', awaiting_payment: 'warning', operational: 'success' }
export const STAGE_ICONS = { created: 'tabler-building', administrator_invited: 'tabler-mail-forward', awaiting_payment: 'tabler-credit-card', operational: 'tabler-circle-check' }
