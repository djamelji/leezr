// @ts-check
// W-HR-UX-2 — Passage 2 sur le VRAI DEV : création d'un collaborateur fictif via l'UI (après correctif
// friction A) → redirection fiche → checklist d'intégration. Preuve de navigation sur dev.leezr.com.
import { test, expect } from '@playwright/test'
const S='/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'
async function devLogin(page,email='owner@leezr.test'){
  await page.goto('/login',{waitUntil:'domcontentloaded'}); await page.waitForTimeout(1500)
  await page.fill('input[type="email"], input[name="email"]', email)
  await page.fill('input[type="password"], input[name="password"]', 'password')
  await Promise.all([page.waitForResponse(r=>r.url().includes('/api/login')&&r.request().method()==='POST',{timeout:25000}).catch(()=>null), page.keyboard.press('Enter')])
  await page.waitForTimeout(2500)
}
test('DEV pass2: create fictitious employee via UI → fiche + checklist', async ({ page }) => {
  test.setTimeout(120000); const stamp=Date.now().toString().slice(-6)
  await devLogin(page)
  await page.goto('/company/workforce/employees',{waitUntil:'domcontentloaded'}); await page.waitForTimeout(2500)
  await page.getByRole('button',{name:/Ajouter Un Employé/i}).click(); await page.waitForTimeout(1500)
  const d=page.locator('.v-navigation-drawer').filter({hasText:'Ajouter un employé'})
  const ins=d.locator('input:not([type="file"])')
  await ins.nth(0).fill('Thomas'); await ins.nth(1).fill(`Bernard${stamp}`); await ins.nth(2).fill(`thomas.b.${stamp}@leezr-test.invalid`)
  await page.screenshot({path:`${S}/hrux2-dev-quickadd-filled.png`})
  const [resp]=await Promise.all([
    page.waitForResponse(r=>/\/workforce\/employees$/.test(new URL(r.url()).pathname)&&r.request().method()==='POST',{timeout:20000}).catch(()=>null),
    d.getByRole('button',{name:/^Créer$/i}).click(),
  ])
  await page.waitForTimeout(4000)
  console.log('DEV_CREATE_STATUS='+(resp?resp.status():'none'))
  console.log('DEV_URL_AFTER='+page.url())
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/)
  await expect(page.getByText(/Intégration du collaborateur/i)).toBeVisible({timeout:12000})
  await page.screenshot({path:`${S}/hrux2-dev-fiche-checklist.png`,fullPage:true})
})
