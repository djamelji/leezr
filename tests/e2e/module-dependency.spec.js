// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, apiRequest } from './helpers'

// Module DEPENDENCY behaviour against the live company (owner session):
//   logistics_tracking REQUIRES logistics_shipments (DependencyGraph).
//   - Activating the child keeps/pulls the parent (entitlement + reason graph).
//   - The child becomes navigable/entitled; the parent is unaffected.
//   - Deactivating the child restores state and leaves the parent active.
// The parent→children cascade on PARENT disable is covered deterministically by PHPUnit
// (ModuleDependencyTest: orphan cleanup) — shipments is plan-included and not disablable here.
// This test restores all state it changes.

const CHILD = 'logistics_tracking'
const PARENT = 'logistics_shipments'

async function modules(page) {
  const r = await apiRequest(page, { method: 'GET', path: '/api/modules' })
  expect(r.status, 'modules readable').toBe(200)
  const map = {}
  for (const m of (r.json?.modules ?? [])) map[m.key] = { state: m.display_state, active: m.is_active }

  return map
}

test('activating a child keeps its required parent; deactivating restores (dependency enforced)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/dashboard', { waitUntil: 'networkidle' })
  await page.waitForTimeout(600)

  // Precondition: child available (inactive), parent active.
  const before = await modules(page)
  expect(before[CHILD]?.active, `${CHILD} starts inactive`).toBe(false)
  expect(before[PARENT]?.active, `${PARENT} is active (plan-included)`).toBe(true)

  try {
    // Activate the child → its required parent must be/stay active.
    const enable = await apiRequest(page, { method: 'PUT', path: `/api/modules/${CHILD}/enable` })
    expect(enable.status, 'child enabled').toBe(200)

    const after = await modules(page)
    expect(after[CHILD]?.active, `${CHILD} is now active`).toBe(true)
    expect(after[PARENT]?.active, 'required parent remains active (dependency satisfied)').toBe(true)

    // Navigation/entitlement: the child now appears as a navigable active module.
    const nav = await apiRequest(page, { method: 'GET', path: '/api/modules' })
    const child = (nav.json?.modules ?? []).find(m => m.key === CHILD)
    expect(child?.is_active, 'child is entitled/active for nav').toBe(true)
  } finally {
    // Restore: deactivate the child; the parent must stay active (no reverse cascade).
    const disable = await apiRequest(page, { method: 'PUT', path: `/api/modules/${CHILD}/disable` })
    expect(disable.status, 'child disabled (restore)').toBe(200)
    const restored = await modules(page)
    expect(restored[CHILD]?.active, `${CHILD} restored to inactive`).toBe(false)
    expect(restored[PARENT]?.active, 'parent unaffected by child deactivation').toBe(true)
  }
})
