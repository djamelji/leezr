// ADR-786 — actions proposées sur la fiche d'un collègue (menu ouvert, aucune action exécutée).
import fs from 'node:fs'
import { test } from '@playwright/test'
import { loginCompany } from './helpers.js'

const USER = process.env.RBAC_USER || 'bob@leezr.test'
const TAG = process.env.RBAC_TAG || 'bob'
const REF = process.env.RBAC_REF || 'user-2'

test(`actions fiche ${REF} — ${TAG}`, async ({ page }) => {
  await loginCompany(page, USER)
  await page.goto(`/company/people/${REF}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(2500)
  const out = { user: USER, ref: REF, tabs: {}, menu: [] }
  const actions = page.getByRole('button', { name: 'Actions' })
  if (await actions.count()) {
    await actions.first().click()
    await page.waitForTimeout(500)
    out.menu = await page.locator('.v-overlay--active .v-list-item').allInnerTexts()
    await page.screenshot({ path: `docs/bmad/adr786/${TAG}-person-${REF}-actions.jpg` })
    await page.keyboard.press('Escape')
  }
  for (const tab of await page.locator('.v-tab').allInnerTexts()) {
    await page.locator('.v-tab', { hasText: tab.trim() }).first().click()
    await page.waitForTimeout(1200)
    out.tabs[tab.trim()] = (await page.locator('main .v-window-item--active, main .v-window').first().innerText().catch(() => '')).slice(0, 600)
  }
  fs.writeFileSync(`docs/bmad/adr786/${TAG}-person-${REF}.json`, JSON.stringify(out, null, 2))
})
