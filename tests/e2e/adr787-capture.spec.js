// ADR-787 — captures (lecture seule) : Mon activité, profil, centre d'aide — desktop / mobile, clair / sombre.
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const USER = process.env.CAP_USER || 'bob@leezr.test'
const PHASE = process.env.CAP_PHASE || 'before'
const PAGES = (process.env.CAP_PAGES || '/company/workforce/me').split(',')
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } }

for (const [vp, size] of Object.entries(VIEWPORTS)) {
  for (const theme of (process.env.CAP_THEMES || 'light,dark').split(',')) {
    test(`${PHASE} ${vp} ${theme}`, async ({ page }) => {
      test.setTimeout(180000)
      await page.setViewportSize(size)
      await page.emulateMedia({ colorScheme: theme })
      await loginCompany(page, USER)
      for (const path of PAGES) {
        await page.goto(path, { waitUntil: 'domcontentloaded' })
        await page.waitForTimeout(2500)
        // Rendu clair/sombre par émulation du navigateur : la préférence enregistrée de l'utilisateur
        // n'est jamais modifiée (elle reste « system »).
        await page.waitForTimeout(300)
        const name = path.replace(/\//g, '_').replace(/^_/, '').replace(/\?.*/, '')
        await page.screenshot({ path: `docs/bmad/adr787/${PHASE}-${name}-${vp}-${theme}.jpg`, fullPage: true, quality: 70, type: 'jpeg' })
      }
    })
  }
}
