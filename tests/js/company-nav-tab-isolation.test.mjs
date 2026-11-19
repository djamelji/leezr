// ADR-831 — a named company menu target never inherits the current page's optional `tab`.
// Reproduces the real route shape (every page wrapped in a layout parent carrying `:tab?`) with the project's
// vue-router, then checks the targets the menu builds. Run : node --test tests/js/
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createMemoryHistory, createRouter } from 'vue-router'

// isolateTabParam is a pure function of the composable : evaluate it alone (the composable imports Pinia stores).
const src = readFileSync(join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/composables/useCompanyNav.js'), 'utf8')
const body = src.slice(src.indexOf('export function isolateTabParam'))
const fnSrc = body.slice(0, body.indexOf('\n}\n') + 2).replace('export function', 'function')
const isolateTabParam = new Function(`${fnSrc}; return isolateTabParam`)()

const Page = { render: () => null }
const wrapped = (path, name) => ({ path, component: Page, children: [{ path: '', name, component: Page }] })
const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    wrapped('/company/workforce/time/:tab?', 'company-workforce-time'),
    wrapped('/company/workforce/leave/:tab?', 'company-workforce-leave'),
    wrapped('/company/workforce/payroll/:tab?', 'company-workforce-payroll'),
    wrapped('/company/people', 'company-people'),
  ],
})

test('without isolation, vue-router carries the current tab into another page (the defect)', async () => {
  await router.push('/company/workforce/leave/config')
  assert.equal(router.resolve({ name: 'company-workforce-payroll' }).fullPath, '/company/workforce/payroll/config')
})

test('a named target with a tab param lands on its default tab, from any page', async () => {
  for (const from of ['/company/workforce/leave/config', '/company/workforce/time/timesheets', '/company/people']) {
    await router.push(from)
    assert.equal(router.resolve(isolateTabParam({ name: 'company-workforce-payroll' }, router)).fullPath, '/company/workforce/payroll')
    assert.equal(router.resolve(isolateTabParam({ name: 'company-workforce-leave' }, router)).fullPath, '/company/workforce/leave')
  }
})

test('an explicit tab is kept, routes without tab and non-named targets are untouched', async () => {
  await router.push('/company/workforce/time/timesheets')
  assert.equal(router.resolve(isolateTabParam({ name: 'company-workforce-leave', params: { tab: 'config' } }, router)).fullPath, '/company/workforce/leave/config')
  const people = { name: 'company-people' }
  assert.equal(isolateTabParam(people, router), people)
  assert.equal(isolateTabParam('/home', router), '/home')
  assert.equal(isolateTabParam(undefined, router), undefined)
})
