// @ts-check
import { expect, test } from '@playwright/test'
import { loginPlatform, apiRequest } from './helpers'

// Platform admin real, persistent, reversible actions (admin@leezr.com).
//   1) Settings — change the app brand name, verify persistence, restore.
//   2) Plans — change a plan's display description (prices preserved), verify, restore.
//   3) Automations — read status + toggle a NON-sensitive task's enabled flag off→on (no run).
// No sensitive/financial automation is ever executed; every mutation is restored.
// Platform permission enforcement (a no-permission platform user → 403 on gated endpoints) is
// proven separately in platform-rbac-workflow.spec.js; the gating middleware is identical here.

const stamp = String(Date.now()).slice(-6)

test.beforeEach(() => test.setTimeout(180000))

// All three admin actions share ONE admin login (keeps platform login-throttle headroom).
test('admin actions: settings + plans + automations (real, persisted, restored)', async ({ page }) => {
  await loginPlatform(page, 'admin@leezr.com')

  // ── 1) Settings: change app name, persist, restore ──────────────────────────
  await page.goto('/platform/settings/general', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)
  const read = await apiRequest(page, { method: 'GET', path: '/api/platform/general-settings' })
  const original = read.json?.general?.app_name ?? 'Leezr'
  const changed = `Leezr E2E ${stamp}`
  const put = await apiRequest(page, { method: 'PUT', path: '/api/platform/general-settings', body: { app_name: changed } })
  expect(put.status, 'app_name saved').toBeLessThan(400)
  const reread = await apiRequest(page, { method: 'GET', path: '/api/platform/general-settings' })
  expect(reread.json?.general?.app_name, 'app_name persisted').toBe(changed)
  const restore = await apiRequest(page, { method: 'PUT', path: '/api/platform/general-settings', body: { app_name: original } })
  expect(restore.status, 'app_name restored').toBeLessThan(400)

  // ── 2) Plans: change a plan description (prices preserved), persist, restore ─
  await page.goto('/platform/catalog/plans', { waitUntil: 'networkidle' })
  await page.waitForTimeout(800)

  const list = await apiRequest(page, { method: 'GET', path: '/api/platform/plans' })
  const plans = list.json?.plans ?? list.json?.data ?? list.json ?? []
  const plan = (Array.isArray(plans) ? plans : [])[0]
  expect(plan?.id, 'a plan exists').toBeTruthy()

  // Full payload preserving every required field (prices!) — change only the description.
  const originalDesc = plan.description ?? ''
  const base = {
    key: plan.key, name: plan.name, level: plan.level,
    price_monthly: plan.price_monthly, price_yearly: plan.price_yearly,
    is_popular: plan.is_popular ?? false, trial_days: plan.trial_days ?? 0,
    feature_labels: plan.feature_labels ?? [], limits: plan.limits ?? [],
  }
  const changedDesc = `E2E description ${stamp}`
  const planPut = await apiRequest(page, { method: 'PUT', path: `/api/platform/plans/${plan.id}`, body: { ...base, description: changedDesc } })
  expect(planPut.status, 'plan updated').toBeLessThan(400)

  const planRead = await apiRequest(page, { method: 'GET', path: `/api/platform/plans/${plan.key}` })
  const updated = planRead.json?.plan ?? planRead.json
  expect(updated?.description, 'description persisted').toBe(changedDesc)
  expect(Number(updated?.price_monthly), 'monthly price preserved').toBe(Number(plan.price_monthly))
  expect(Number(updated?.price_yearly), 'yearly price preserved').toBe(Number(plan.price_yearly))

  // Restore.
  await apiRequest(page, { method: 'PUT', path: `/api/platform/plans/${plan.id}`, body: { ...base, description: originalDesc } })

  // ── 3) Automations: read status + toggle a non-sensitive task (no run), restore ─
  await page.goto('/platform/operations/automations', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)

  const overview = await apiRequest(page, { method: 'GET', path: '/api/platform/automations' })
  expect(overview.status, 'automations overview readable').toBe(200)
  const tasks = overview.json?.tasks ?? overview.json?.data?.tasks ?? []
  const safe = (Array.isArray(tasks) ? tasks : []).find(t => t.sensitive === false)
  expect(safe?.task ?? safe?.name, 'a non-sensitive task exists').toBeTruthy()
  const taskName = safe.task ?? safe.name
  const wasEnabled = safe.enabled

  // Toggle the enabled flag (config only — this does NOT run the task), then restore.
  const off = await apiRequest(page, { method: 'POST', path: '/api/platform/automations/toggle', body: { task: taskName, enabled: !wasEnabled, reason: 'E2E config toggle' } })
  expect(off.status, 'toggle persisted').toBeLessThan(400)

  const after = await apiRequest(page, { method: 'GET', path: '/api/platform/automations' })
  const t2 = (after.json?.tasks ?? []).find(t => (t.task ?? t.name) === taskName)
  expect(t2?.enabled, 'enabled flag flipped').toBe(!wasEnabled)

  // Restore original enabled state.
  const back = await apiRequest(page, { method: 'POST', path: '/api/platform/automations/toggle', body: { task: taskName, enabled: wasEnabled, reason: 'E2E restore' } })
  expect(back.status, 'toggle restored').toBeLessThan(400)
})
