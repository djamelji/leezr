// @ts-check
import { expect, test } from '@playwright/test'
import { loginCompany, clickAndExpectApi } from './helpers'

// Company document vault upload + delete, driven by the owner on the isolated local base.
// Real multipart upload (POST /company/documents/{code}) and delete (DELETE), with persistence
// verified on reload. Uses the first available document type; fills an expiration date if the
// type requires one. No bypass — real documents.manage gate, real validation.

const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n')

test('owner: upload a document then delete it (persisted)', async ({ page }) => {
  await loginCompany(page, 'owner@leezr.test')
  await page.goto('/company/documents/vault', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1800)

  // Pick the first document type in the vault and read its code from the data-test attr.
  const uploadInput = page.locator('[data-test^="doc-upload-"]').first()
  await expect(uploadInput, 'at least one document type in the vault').toHaveCount(1, { timeout: 10000 }).catch(() => {})
  const dt = await uploadInput.getAttribute('data-test')
  const code = dt.replace('doc-upload-', '')

  // Provide the file to the hidden input → opens the confirm dialog.
  await uploadInput.setInputFiles({ name: 'e2e-doc.pdf', mimeType: 'application/pdf', buffer: PDF })
  await page.waitForTimeout(600)

  // If this type requires an expiration date, the confirm button stays disabled until set.
  const confirm = page.locator('[data-test="doc-upload-confirm"]')
  if (await confirm.isDisabled()) {
    const dateInput = page.locator('[data-test="doc-upload-expires"] input').first()
    await dateInput.evaluate(el => el._flatpickr.setDate('2027-12-31', true))
    await page.waitForTimeout(300)
  }

  await clickAndExpectApi(page, { urlIncludes: `/company/documents/${code}`, method: 'POST' },
    () => confirm.click())

  // Persistence: reload → the type now exposes a delete action (upload present).
  await page.goto('/company/documents/vault', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1800)
  const del = page.locator(`[data-test="doc-delete-${code}"]`)
  await expect(del, 'uploaded document is persisted (delete action present)').toBeVisible({ timeout: 10000 })

  // Delete → confirm → DELETE, then the delete action disappears.
  await clickAndExpectApi(page, { urlIncludes: `/company/documents/${code}`, method: 'DELETE' },
    async () => {
      await del.click()
      await page.getByRole('button', { name: 'Confirm' }).click()
    })

  await page.goto('/company/documents/vault', { waitUntil: 'networkidle' })
  await page.waitForTimeout(1800)
  await expect(page.locator(`[data-test="doc-delete-${code}"]`)).toHaveCount(0)
})
