// @ts-check
// W-HR-UX-3 — DEV réel : créer un collaborateur via UI → depuis la fiche, DONNER ACCÈS (checklist) →
// dialog → invitation → état "invité". Le P0 (accès depuis la fiche) sans passer par Members.
import { test, expect } from '@playwright/test'
const S='/private/tmp/claude-501/-Users-djamel-sites-leezr/b5712db8-7698-4fe2-b06f-1c13ecc2368f/scratchpad'
async function devLogin(page,email='owner@leezr.test'){
  await page.goto('/login',{waitUntil:'domcontentloaded'}); await page.waitForTimeout(1500)
  await page.fill('input[type="email"], input[name="email"]', email)
  await page.fill('input[type="password"], input[name="password"]', 'password')
  await Promise.all([page.waitForResponse(r=>r.url().includes('/api/login')&&r.request().method()==='POST',{timeout:25000}).catch(()=>null), page.keyboard.press('Enter')])
  await page.waitForTimeout(2500)
}
test('DEV: grant portal access from fiche checklist', async ({ page }) => {
  test.setTimeout(120000); const stamp=Date.now().toString().slice(-6)
  await devLogin(page)
  await page.goto('/company/workforce/employees',{waitUntil:'domcontentloaded'}); await page.waitForTimeout(2500)
  await page.getByRole('button',{name:/Ajouter Un Employé/i}).click(); await page.waitForTimeout(1500)
  const d=page.locator('.v-navigation-drawer').filter({hasText:'Ajouter un employé'})
  const ins=d.locator('input:not([type="file"])')
  await ins.nth(0).fill('Lucie'); await ins.nth(1).fill(`Durand${stamp}`); await ins.nth(2).fill(`lucie.d.${stamp}@leezr-test.invalid`)
  await d.getByRole('button',{name:/^Créer$/i}).click(); await page.waitForTimeout(4000)
  await expect(page).toHaveURL(/\/company\/workforce\/employees\/\d+/)

  // Checklist → étape "Accès à l'espace collaborateur" → bouton "Faire"
  const accessRow = page.locator('.v-list-item', { hasText: /Accès à l.espace collaborateur/i })
  await accessRow.getByRole('button', { name: /Faire/i }).click()
  await page.waitForTimeout(1500)
  await page.screenshot({ path:`${S}/hrux3-dev-access-dialog.png` })
  // Dialog "Accès à l'espace collaborateur" → Donner accès
  const dlg = page.locator('.v-dialog').filter({ hasText: /Accès à l.espace collaborateur/i })
  await expect(dlg).toBeVisible({ timeout: 8000 })
  const [resp]=await Promise.all([
    page.waitForResponse(r=>/\/access$/.test(new URL(r.url()).pathname)&&r.request().method()==='POST',{timeout:15000}).catch(()=>null),
    dlg.getByRole('button',{name:/Donner accès/i}).click(),
  ])
  await page.waitForTimeout(2000)
  console.log('GRANT_STATUS='+(resp?resp.status():'none'))
  // État "invité" affiché
  await expect(dlg.getByText(/Invitation envoyée/i)).toBeVisible({ timeout: 8000 })
  await page.screenshot({ path:`${S}/hrux3-dev-access-invited.png` })
  // Fermer → la checklist reflète l'accès (invité → étape Accès reste "Faire" car non ACTIVÉ)
  await dlg.getByRole('button',{name:/Fermer/i}).click(); await page.waitForTimeout(1500)
  await page.screenshot({ path:`${S}/hrux3-dev-fiche-after-access.png`, fullPage:true })
})
