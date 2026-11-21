// @ts-check
// Leezr 4.2 M7 (ADR-844) — time / planning / absence exports, browser recipe with REAL downloads on the MARKED clone.
// Fixture : scripts/audit842-m7-fixture.php (fictitious company 106, March 2026). Expected values written by hand
// (Europe/Paris = UTC+1 in March). Each downloaded file is opened and parsed : BOM, separator, headers, values.
import fs from 'node:fs'
import { expect, test } from '@playwright/test'
import { loginCompany } from './helpers'

test.skip(!process.env.AUDIT_PASSWORD_FILE, 'Leezr 4.2 M7 recipe only (AUDIT_PASSWORD_FILE)')
const PASSWORD = process.env.AUDIT_PASSWORD_FILE ? fs.readFileSync(process.env.AUDIT_PASSWORD_FILE, 'utf8').trim() : ''
const OUT = 'docs/bmad/leezr-4-2-audit/m7'
const shot = async (page, name) => { await page.waitForTimeout(500); await page.screenshot({ path: `${OUT}/apres-${name}.jpg`, type: 'jpeg', quality: 60 }) }

function watch(page) {
  const problems = []
  page.on('pageerror', e => problems.push(`js ${e.message}`))
  page.on('response', r => {
    if (r.status() >= 400 && r.url().includes('/api/') && !r.url().includes('/realtime/') && !(r.status() === 429 && r.url().includes('/api/public/')))
      problems.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https?:\/\/[^/]+/, '').replace(/\?.*/, '')}`)
  })

  return problems
}

async function openExport(page) {
  await page.goto('/company/workforce/time/timesheets', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1000)
  await page.locator('[data-test="time-export-open"]').click()
  await expect(page.locator('[data-test="time-export-dialog"]')).toBeVisible()
  await page.locator('[data-test="time-export-range"]').click()
  await page.locator('.v-overlay--active .v-list-item', { hasText: 'Période personnalisée' }).click()
  await page.locator('[data-test="time-export-from"] input').fill('2026-03-01')
  await page.locator('[data-test="time-export-to"] input').fill('2026-03-31')
  for (const d of ['entries', 'breaks', 'daily', 'planning', 'leaves']) {
    const box = page.locator(`[data-test="time-export-dataset-${d}"] input`)
    if (!await box.isChecked()) await box.check()
  }
}

async function downloadCsv(page, dataset) {
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator(`[data-test="time-export-download-${dataset}"]`).click()])
  const raw = fs.readFileSync(await dl.path())
  fs.copyFileSync(await dl.path(), `${OUT}/exemple-${dataset}.csv`)
  expect([...raw.subarray(0, 3)], `${dataset} : UTF-8 BOM`).toEqual([0xEF, 0xBB, 0xBF])
  const text = raw.subarray(3).toString('utf8')
  expect(text, 'UTF-8 accents survive').not.toContain('�')

  return { name: dl.suggestedFilename(), rows: text.trim().split(/\r?\n/).map(l => l.split(';').map(c => c.replace(/^"|"$/g, ''))) }
}

const count = async (page, d) => Number((await page.locator(`[data-test="time-export-count-${d}"]`).innerText()).replace(/\D/g, ''))

test.beforeEach(({ page }) => { page.setDefaultTimeout(15000); fs.mkdirSync(OUT, { recursive: true }) })

test('Équipe entière : aperçu, avertissements, 5 fichiers téléchargés et vérifiés', async ({ page }) => {
  test.setTimeout(5 * 60 * 1000)
  const problems = watch(page)
  await loginCompany(page, 'owner.at4f8@audit842.test', PASSWORD)
  await openExport(page)
  await page.locator('[data-test="time-export-preview"]').click()
  await expect(page.locator('[data-test="time-export-preview-daily"]')).toBeVisible()
  await expect(page.locator('[data-test="time-export-filters"]')).toContainText('2026-03-01')
  await expect(page.locator('[data-test="time-export-filters"]')).toContainText('Europe/Paris')
  expect(await count(page, 'entries')).toBe(2)
  expect(await count(page, 'breaks')).toBe(1)
  expect(await count(page, 'daily')).toBe(2)
  expect(await count(page, 'planning')).toBe(1)
  expect(await count(page, 'leaves')).toBe(2)
  await expect(page.locator('[data-test="time-export-warning-unvalidated"]')).toContainText('1 journée')
  await expect(page.locator('[data-test="time-export-warning-pending_leaves"]')).toBeVisible()
  await shot(page, 'A1-apercu-equipe')

  const entries = await downloadCsv(page, 'entries')
  expect(entries.name).toBe('pointages_2026-03-01_2026-03-31.csv')
  expect(entries.rows[0]).toEqual(['Date', 'Matricule', 'Nom', 'Prénom', 'Service', 'Arrivée', 'Départ', 'Pauses (min)', 'Travaillé (min)', 'Travaillé (hh:mm)', 'Statut', 'Origine'])
  expect(entries.rows.slice(1)).toEqual([
    ['2026-03-10', 'M7-A', 'Export', 'Alice', '', '2026-03-10 09:00', '2026-03-10 17:30', '30', '480', '08:00', 'terminé', 'web'],
    ['2026-03-12', 'M7-B', 'Éxport', 'Bruno', '', '2026-03-12 08:00', '2026-03-12 16:00', '0', '480', '08:00', 'terminé', 'kiosk'],
  ])
  // the file = what the preview shows (first row, same cells)
  const shown = await page.locator('[data-test="time-export-preview-entries"] tbody tr').first().locator('td').allInnerTexts()
  expect(shown.map(s => s.trim())).toEqual(entries.rows[1])

  expect((await downloadCsv(page, 'breaks')).rows[1]).toEqual(['2026-03-10', 'M7-A', 'Export', 'Alice', '2026-03-10 13:00', '2026-03-10 13:30', 'repas', '30'])
  const daily = (await downloadCsv(page, 'daily')).rows
  expect(daily.find(r => r[1] === 'M7-A').slice(4)).toEqual(['450', '480', '30', '30', '30', '0', '', 'non', '0', 'approuvée', 'oui'])
  expect(daily.find(r => r[1] === 'M7-B').slice(4)).toEqual(['420', '480', '60', '0', '60', '0', '', 'non', '0', 'soumise', 'non'])
  expect((await downloadCsv(page, 'planning')).rows[1]).toEqual(['2026-03-10', 'M7-A', 'Export', 'Alice', 'Agence M7', '2026-03-10 09:00', '2026-03-10 16:30', 'Europe/Paris', '450', 'publié'])
  const leaves = (await downloadCsv(page, 'leaves')).rows
  expect(leaves.find(r => r[0] === 'M7-B').slice(3)).toEqual(['Congés payés', '2026-03-16', '2026-03-18', '', '', '2,5', '', 'approuvée', ''])
  expect(leaves.find(r => r[0] === 'M7-A')[10]).toBe('en attente')
  await shot(page, 'A2-telechargements')
  expect(problems).toEqual([])
})

test('Un collaborateur, une période plus courte, aucune donnée', async ({ page }) => {
  test.setTimeout(4 * 60 * 1000)
  const problems = watch(page)
  await loginCompany(page, 'owner.at4f8@audit842.test', PASSWORD)
  await openExport(page)
  await page.locator('[data-test="time-export-employees"]').click()
  await page.locator('.v-overlay--active .v-list-item', { hasText: 'M7-A' }).click()
  await page.keyboard.press('Escape')
  await page.locator('[data-test="time-export-preview"]').click()
  await expect(page.locator('[data-test="time-export-preview-entries"]')).toBeVisible()
  expect(await count(page, 'entries')).toBe(1)
  expect(await count(page, 'leaves')).toBe(1)
  const one = (await downloadCsv(page, 'entries')).rows
  expect(one.length).toBe(2)
  expect(one[1][1]).toBe('M7-A')
  await shot(page, 'B1-un-collaborateur')

  // a period without data
  await page.locator('[data-test="time-export-from"] input').fill('2026-03-21')
  await page.locator('[data-test="time-export-to"] input').fill('2026-03-31')
  await page.locator('[data-test="time-export-preview"]').click()
  await expect(page.locator('[data-test="time-export-warning-empty"]').first()).toContainText('Aucune donnée')
  await expect(page.locator('[data-test="time-export-download-entries"]')).toBeDisabled()
  await shot(page, 'B2-periode-vide')
  expect(problems).toEqual([])
})

test('Permissions limitées, sans droit d’export, autre société', async ({ page, browser }) => {
  test.setTimeout(4 * 60 * 1000)
  // team lead : export right, sees herself + Alice only
  const problems = watch(page)
  await loginCompany(page, 'chef844@audit842.test', PASSWORD)
  await openExport(page)
  await page.locator('[data-test="time-export-preview"]').click()
  await expect(page.locator('[data-test="time-export-preview-entries"]')).toBeVisible()
  expect(await count(page, 'entries'), 'Bruno is outside her team').toBe(1)
  expect((await downloadCsv(page, 'entries')).rows.slice(1).map(r => r[1])).toEqual(['M7-A'])
  await shot(page, 'C1-cheffe-equipe')
  // forcing Bruno through the API still returns nothing
  const forced = await page.evaluate(async () => {
    const r = await fetch('/api/workforce/exports/time/preview?from=2026-03-01&to=2026-03-31&datasets[]=entries', { headers: { 'Accept': 'application/json', 'X-Company-Id': '106' }, credentials: 'include' })

    return (await r.json()).datasets.entries.count
  })
  expect(forced).toBe(1)
  // another company : refused
  const other = await page.evaluate(async () => (await fetch('/api/workforce/exports/time/preview?from=2026-03-01&to=2026-03-31&datasets[]=entries', { headers: { 'Accept': 'application/json', 'X-Company-Id': '105' }, credentials: 'include' })).status)
  expect(other).toBe(403)
  expect(problems.filter(p => p !== '403 GET /api/workforce/exports/time/preview'), 'only the refusal provoked on purpose').toEqual([])

  // a member without the export right : no button, API refused
  const ctx = await browser.newContext()
  const p2 = await ctx.newPage()
  p2.setDefaultTimeout(15000)
  await loginCompany(p2, 'manager843@audit842.test', PASSWORD)
  await p2.goto('/company/workforce/planning', { waitUntil: 'networkidle' })
  await p2.waitForTimeout(1000)
  await expect(p2.locator('[data-test="time-export-open"]')).toHaveCount(0)
  const st = await p2.evaluate(async () => (await fetch('/api/workforce/exports/time/download?from=2026-03-01&to=2026-03-31&dataset=entries', { headers: { 'Accept': 'application/json', 'X-Company-Id': '105' }, credentials: 'include' })).status)
  expect([403, 422]).toContain(st)
  await ctx.close()
})
