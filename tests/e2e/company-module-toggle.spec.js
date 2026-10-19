// @ts-check
import { execFileSync } from 'node:child_process'
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// Start from a deterministic 'available' baseline: clear any add-on subscription / grace-period
// row left by a previous run (end-of-period deactivation keeps the module active until period end).
test.beforeAll(() => {
  execFileSync('php', ['artisan', 'e2e:reset-module-addon', '--company=1', '--module=logistics_tracking'],
    { cwd: process.cwd(), stdio: 'pipe' })
})

// Module activation/deactivation round-trip driven by the owner on the isolated local base.
// Target: logistics_tracking — a paid add-on in the 'available' state (so this also exercises
// the add-on quote flow + entitlement). We activate it then deactivate it, restoring state,
// and never touch modules other specs depend on (workforce*, logistics_shipments).
// Real endpoints: PUT /modules/{key}/enable and /disable, with persistence checked via /api/modules.

const KEY = 'logistics_tracking'

// Read the live module catalog through the authenticated session (what the API actually returns).
async function getModule(page, key) {
  return page.evaluate(async k => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const res = await fetch('/api/modules', {
      headers: {
        'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''),
        'X-Company-Id': decodeURIComponent(get('currentCompanyId') || ''),
        Accept: 'application/json',
      },
      credentials: 'include',
    })
    const j = await res.json()
    const mod = (j.modules || []).find(m => m.key === k)
    const sub = (j.addon_subscriptions || []).find(s => s.module_key === k || s.key === k)

    return { display_state: mod?.display_state, is_active: mod?.is_active, scheduledOff: !!sub?.deactivated_at }
  }, key)
}

test('owner: activate an add-on module then deactivate it (persisted round-trip)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/modules', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1500)

  // Precondition: target starts available (not active).
  const before = await getModule(page, KEY)
  expect(before.display_state, `${KEY} must start available`).toBe('available')

  // ─── Activate (add-on quote flow) ─────────────────────────────────────────
  await page.getByRole('tab', { name: 'Extensions' }).click()
  await page.waitForTimeout(800)
  await page.locator(`[data-test="module-activate-${KEY}"]`).click()
  // Quote dialog → wait for the quote to load (confirm is disabled until quoteData arrives) → PUT enable.
  const quoteConfirm = page.locator('[data-test="module-quote-confirm"]')
  await expect(quoteConfirm, 'quote dialog open').toBeVisible({ timeout: 10000 })
  await expect(quoteConfirm, 'quote loaded → confirm enabled').toBeEnabled({ timeout: 10000 })
  await clickAndExpectApi(page, { urlIncludes: `/modules/${KEY}/enable`, method: 'PUT' },
    () => quoteConfirm.click())

  await page.waitForTimeout(1500)
  const activated = await getModule(page, KEY)
  expect(activated.is_active, `${KEY} is active after enable`).toBe(true)

  // ─── Deactivate ───────────────────────────────────────────────────────────
  await page.goto('/company/modules', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  await page.getByRole('tab', { name: 'Actifs' }).click()
  await page.waitForTimeout(800)
  await page.locator(`[data-test="module-deactivate-${KEY}"]`).click()
  // Deactivate dialog (shows proration preview for add-ons) → confirm → PUT disable.
  await clickAndExpectApi(page, { urlIncludes: `/modules/${KEY}/disable`, method: 'PUT' },
    () => page.locator('[data-test="module-deactivate-confirm"]').click())

  // Persistence: the deactivation is recorded — either the module is immediately inactive,
  // or (end-of-period add-on timing) a scheduled deactivation is registered.
  await page.waitForTimeout(1500)
  const after = await getModule(page, KEY)
  expect(
    after.is_active === false || after.scheduledOff,
    `${KEY} deactivation recorded (is_active=${after.is_active}, scheduledOff=${after.scheduledOff})`,
  ).toBeTruthy()
})
