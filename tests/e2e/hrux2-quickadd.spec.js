import { test, expect } from '@playwright/test'
import { loginCompany } from './helpers'
const S='/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'
test('quick add with name only (date prefilled) → creates + redirects to fiche', async ({ page }) => {
  test.setTimeout(90000); const stamp=Date.now().toString().slice(-6)
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/workforce/employees',{waitUntil:'domcontentloaded'}); await page.waitForTimeout(1500)
  await page.getByRole('button',{name:/Ajouter Un Employé/i}).click(); await page.waitForTimeout(1200)
  const d=page.locator('.v-navigation-drawer').filter({hasText:'Ajouter un employé'})
  const ins=d.locator('input:not([type="file"])')
  await ins.nth(0).fill('Thomas'); await ins.nth(1).fill(`Bernard${stamp}`)
  const [resp]=await Promise.all([
    page.waitForResponse(r=>/\/workforce\/employees$/.test(new URL(r.url()).pathname)&&r.request().method()==='POST',{timeout:15000}).catch(()=>null),
    d.getByRole('button',{name:/^Créer$/i}).click(),
  ])
  await page.waitForTimeout(3000)
  console.log('QUICKADD_STATUS='+(resp?resp.status():'none'))
  console.log('URL='+page.url())
  await page.screenshot({path:`${S}/hrux2-quickadd-result.png`,fullPage:true})
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/)
})
