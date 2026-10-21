// @ts-check
// AUDIT (no assertions that fail the run — captures the REAL behaviour of re-login while an
// existing session is active). Reproduces: logged in as Alice → go to /login → try to log in as
// owner, WITHOUT an explicit logout. Captures where the identity actually ends up.
import { test } from '@playwright/test'

async function me(page) {
  return page.evaluate(async () => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    const r = await fetch('/api/me', { headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || ''), Accept: 'application/json' }, credentials: 'include' }).catch(() => null)
    if (!r) return { status: 0 }
    let j = null; try { j = await r.json() } catch {}

    return { status: r.status, email: (j?.user ?? j)?.email ?? null, name: (j?.user ?? j)?.display_name ?? null }
  })
}

async function rawLogin(page, email, password = 'password') {
  await page.goto('/login', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1200)
  const url1 = page.url()
  const hasForm = await page.locator('input[type="email"], input[name="email"]').count()
  console.log(`[audit] goto /login as(${email}) → url=${url1} emailInputPresent=${hasForm}`)
  if (hasForm > 0) {
    await page.fill('input[type="email"], input[name="email"]', email)
    await page.fill('input[type="password"], input[name="password"]', password)
    const [resp] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/api/login') && r.request().method() === 'POST', { timeout: 15000 }).catch(() => null),
      page.keyboard.press('Enter'),
    ])
    await page.waitForTimeout(2000)
    console.log(`[audit] submit(${email}) → loginPOST=${resp ? resp.status() : 'none'} url=${page.url()}`)
  }

  return { urlAfterGotoLogin: url1, hadForm: hasForm > 0 }
}

test('AUDIT: re-login as owner while an Alice session is active (no explicit logout)', async ({ browser }) => {
  const context = await browser.newContext()
  const page = await context.newPage()

  // 1) Log in as Alice (admin).
  await rawLogin(page, 'admin@leezr.test')
  console.log('[audit] after Alice login, /api/me =', JSON.stringify(await me(page)))

  // 2) WITHOUT logout, try to log in as owner from /login (the user's real action).
  const r = await rawLogin(page, 'owner@leezr.test')
  console.log('[audit] re-login-as-owner reachable form =', r.hadForm)
  console.log('[audit] AFTER attempting owner login (no logout), /api/me =', JSON.stringify(await me(page)))
  console.log('[audit] DOM body contains "Alice" =', (await page.locator('body').innerText()).includes('Alice'))
  console.log('[audit] DOM body contains "admin@leezr.test" =', (await page.locator('body').innerText()).includes('admin@leezr.test'))

  // 3) Now explicit logout + login owner (the correct flow) for comparison.
  await page.evaluate(async () => {
    const get = n => (document.cookie.match(new RegExp(n + '=([^;]+)')) || [])[1]
    await fetch('/api/logout', { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(get('XSRF-TOKEN') || '') }, credentials: 'include' }).catch(() => {})
  })
  await rawLogin(page, 'owner@leezr.test')
  console.log('[audit] after explicit logout + owner login, /api/me =', JSON.stringify(await me(page)))

  await context.close()
})
