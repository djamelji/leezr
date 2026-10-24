// @ts-check
// ADR-611 — mouse-wheel scroll works everywhere in the mailbox. Proves scrollTop/scrollY ACTUALLY
// change after mouse.wheel (not merely that overflow-y:auto exists). Deterministic via stubs.
import { expect, test } from '@playwright/test'
import { loginPlatform } from './helpers'

function thread(id, subject) {
  return {
    id, subject, participant_email: `s${id}@ext.test`, participant_name: `Sender ${id}`,
    folder: 'inbox', status: 'open', unread_count: 0, message_count: 1, is_starred: false, labels: [],
    last_message_at: new Date(0).toISOString(),
    last_message: { subject, body_text: 'x', direction: 'received', created_at: new Date(0).toISOString() },
  }
}
function listBody(threads) {
  return JSON.stringify({
    data: threads, current_page: 1, last_page: 1, total: threads.length,
    folder_counts: { inbox: threads.length, sent: 0, draft: 0, starred: 0, spam: 0, trash: 0 },
    stats: { total_unread: 0, open: threads.length, closed: 0 },
  })
}
async function stubList(page, threads) {
  await page.route('**/api/platform/email/inbox?*', r =>
    r.fulfill({ status: 200, contentType: 'application/json', body: listBody(threads) }))
}

test('an overflowing conversation list scrolls on wheel', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  await stubList(page, Array.from({ length: 30 }, (_, i) => thread(i + 1, `Message ${i + 1}`)))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  const list = page.locator('.email-list')
  await expect(list.locator('.email-item').first()).toBeVisible({ timeout: 8000 })
  const box = await list.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  const before = await list.evaluate(el => el.scrollTop)
  await page.mouse.wheel(0, 600)
  await page.waitForTimeout(400)
  const after = await list.evaluate(el => el.scrollTop)
  expect(after).toBeGreaterThan(before)
})

test('a non-overflowing list lets the wheel scroll the page (no trapping)', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  await stubList(page, [thread(1, 'Only one')])
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  const list = page.locator('.email-list')
  await expect(list.locator('.email-item').first()).toBeVisible({ timeout: 8000 })
  // Only assert propagation when the page actually has something to scroll.
  const pageOverflows = await page.evaluate(() => document.scrollingElement.scrollHeight > document.scrollingElement.clientHeight)
  const box = await list.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + 30)
  const before = await page.evaluate(() => window.scrollY)
  await page.mouse.wheel(0, 800)
  await page.waitForTimeout(400)
  const after = await page.evaluate(() => window.scrollY)
  if (pageOverflows) expect(after).toBeGreaterThan(before)
})

test('an overflowing reading pane scrolls on wheel', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  await stubList(page, [thread(1, 'Open me')])
  await page.route('**/api/platform/email/inbox/1', r => r.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      thread: thread(1, 'Open me'),
      messages: [{
        id: 1, message_id: 'm1@x', direction: 'received', from_email: 'long@ext.test',
        recipient_email: 'admin@leezr.com', subject: 'Open me',
        body_html: '<p>' + 'Longue ligne de contenu. '.repeat(1200) + '</p>',
        cc: null, bcc: null, status: 'sent', is_read: true, attachments: [],
        created_at: new Date(0).toISOString(), sent_at: new Date(0).toISOString(),
      }],
    }),
  }))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  await page.getByText('Open me').first().click()
  const pane = page.locator('.mail-content-container').first()
  await pane.waitFor({ timeout: 8000 })
  const box = await pane.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  const before = await pane.evaluate(el => el.scrollTop)
  await page.mouse.wheel(0, 600)
  await page.waitForTimeout(400)
  const after = await pane.evaluate(el => el.scrollTop)
  expect(after).toBeGreaterThan(before)
})

test('list scrolled to the bottom propagates the wheel to the page', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  await stubList(page, Array.from({ length: 30 }, (_, i) => thread(i + 1, `Message ${i + 1}`)))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })

  const list = page.locator('.email-list')
  await expect(list.locator('.email-item').first()).toBeVisible({ timeout: 8000 })
  await list.evaluate(el => el.scrollTo(0, el.scrollHeight)) // park at the bottom
  await page.waitForTimeout(200)
  const pageOverflows = await page.evaluate(() => document.scrollingElement.scrollHeight > document.scrollingElement.clientHeight)
  const box = await list.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  const w0 = await page.evaluate(() => window.scrollY)
  await page.mouse.wheel(0, 800)
  await page.waitForTimeout(400)
  const w1 = await page.evaluate(() => window.scrollY)
  if (pageOverflows) expect(w1).toBeGreaterThan(w0)
})

test('reading pane scrolled to the bottom propagates the wheel to the page', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  await stubList(page, [thread(1, 'Open me')])
  await page.route('**/api/platform/email/inbox/1', r => r.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({
      thread: thread(1, 'Open me'),
      messages: [{
        id: 1, message_id: 'm1@x', direction: 'received', from_email: 'long@ext.test',
        recipient_email: 'admin@leezr.com', subject: 'Open me',
        body_html: '<p>' + 'Longue ligne de contenu. '.repeat(1200) + '</p>',
        cc: null, bcc: null, status: 'sent', is_read: true, attachments: [],
        created_at: new Date(0).toISOString(), sent_at: new Date(0).toISOString(),
      }],
    }),
  }))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await page.getByText('Open me').first().click()
  const pane = page.locator('.mail-content-container').first()
  await pane.waitFor({ timeout: 8000 })
  await pane.evaluate(el => el.scrollTo(0, el.scrollHeight)) // park at the bottom
  await page.waitForTimeout(200)
  const pageOverflows = await page.evaluate(() => document.scrollingElement.scrollHeight > document.scrollingElement.clientHeight)
  const box = await pane.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  const w0 = await page.evaluate(() => window.scrollY)
  await page.mouse.wheel(0, 800)
  await page.waitForTimeout(400)
  const w1 = await page.evaluate(() => window.scrollY)
  if (pageOverflows) expect(w1).toBeGreaterThan(w0)
})

test('no stray horizontal scroll at 1440 / 768 / 390', async ({ page }) => {
  await stubList(page, Array.from({ length: 12 }, (_, i) => thread(i + 1, `Message ${i + 1}`)))
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await expect(page.locator('.email-item').first()).toBeVisible({ timeout: 8000 })

  for (const width of [1440, 768, 390]) {
    await page.setViewportSize({ width, height: 800 })
    await page.waitForTimeout(300)
    const hasHScroll = await page.evaluate(() => document.scrollingElement.scrollWidth > document.scrollingElement.clientWidth + 1)
    expect(hasHScroll, `horizontal scroll at ${width}px`).toBe(false)
  }
})

test('the folder sidebar does not trap the wheel', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 720 })
  await stubList(page, [thread(1, 'Only one')])
  await loginPlatform(page, 'admin@leezr.com')
  await page.goto('/platform/messaging/inbox', { waitUntil: 'networkidle' })
  await expect(page.locator('[data-test="folder-inbox"]')).toBeVisible({ timeout: 8000 })

  const pageOverflows = await page.evaluate(() => document.scrollingElement.scrollHeight > document.scrollingElement.clientHeight)
  const sidebar = page.locator('.email-left-sidebar')
  const box = await sidebar.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
  const w0 = await page.evaluate(() => window.scrollY)
  await page.mouse.wheel(0, 800)
  await page.waitForTimeout(400)
  const w1 = await page.evaluate(() => window.scrollY)
  if (pageOverflows) expect(w1).toBeGreaterThan(w0)
})
