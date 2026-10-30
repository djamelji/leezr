import { test } from '@playwright/test'
import { loginCompany } from './helpers'
const S='/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'
test('employee portal first login (Mon espace)', async ({ page }) => {
  test.setTimeout(90000)
  const errs=[]; page.on('console',m=>{if(m.type()==='error')errs.push(m.text())})
  await loginCompany(page, 'bob@leezr.test')
  console.log('PORTAL_URL='+page.url())
  await page.waitForTimeout(2000)
  await page.screenshot({path:`${S}/hrux2-portal-home.png`,fullPage:true})
  // Menu latéral visible pour ce compte
  const nav = await page.locator('.layout-vertical-nav a, nav a, .v-list-item').allInnerTexts()
  console.log('NAV='+JSON.stringify([...new Set(nav.map(n=>n.trim()).filter(Boolean))].slice(0,30)))
  // Tenter "Mon espace"
  await page.goto('/company/workforce/me', {waitUntil:'domcontentloaded'}).catch(()=>{}); await page.waitForTimeout(1500)
  await page.screenshot({path:`${S}/hrux2-portal-me.png`,fullPage:true})
  console.log('ME_URL='+page.url())
  console.log('JSERR='+JSON.stringify(errs.filter(e=>!/favicon|ResizeObserver|resource/i.test(e))))
})
