// @ts-check
// W-HR-UX-2 — Passage 1 sur le VRAI DEV (dev.leezr.com) : observation visuelle du parcours d'embauche.
import { test, expect } from '@playwright/test'
const S='/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'

async function devLogin(page, email='owner@leezr.test') {
  await page.goto('/login', { waitUntil:'domcontentloaded' }); await page.waitForTimeout(1500)
  await page.fill('input[type="email"], input[name="email"]', email)
  await page.fill('input[type="password"], input[name="password"]', 'password')
  await Promise.all([
    page.waitForResponse(r => r.url().includes('/api/login') && r.request().method()==='POST', { timeout:25000 }).catch(()=>null),
    page.keyboard.press('Enter'),
  ])
  await page.waitForTimeout(2500)
}

test('DEV pass1: employees + add drawer (observe date field + friction)', async ({ page }) => {
  test.setTimeout(90000)
  await devLogin(page)
  await page.goto('/company/workforce/employees', { waitUntil:'domcontentloaded' }); await page.waitForTimeout(2500)
  await page.screenshot({ path:`${S}/hrux2-dev-employees.png`, fullPage:true })

  await page.getByRole('button', { name:/Ajouter Un Employé/i }).click(); await page.waitForTimeout(1500)
  await page.screenshot({ path:`${S}/hrux2-dev-adddrawer.png`, fullPage:true })
  const drawer = page.locator('.v-navigation-drawer').filter({ hasText:'Ajouter un employé' })
  // Le champ "Date d'embauche" montre-t-il qu'il est requis ?
  const lbls = await drawer.locator('.v-label, label').allInnerTexts()
  console.log('ADD_LABELS=' + JSON.stringify(lbls.map(l=>l.trim()).filter(Boolean)))
  // Tenter une création avec juste nom (reproduire la friction 422 silencieuse)
  const ins = drawer.locator('input:not([type="file"])')
  await ins.nth(0).fill('Thomas'); await ins.nth(1).fill('BernardAudit')
  const [resp] = await Promise.all([
    page.waitForResponse(r=>/\/workforce\/employees$/.test(new URL(r.url()).pathname) && r.request().method()==='POST',{timeout:15000}).catch(()=>null),
    drawer.getByRole('button', { name:/^Créer$/i }).click(),
  ])
  await page.waitForTimeout(1500)
  console.log('CREATE_NO_DATE_STATUS=' + (resp?resp.status():'none'))
  await page.screenshot({ path:`${S}/hrux2-dev-nodate-error.png` })
})
