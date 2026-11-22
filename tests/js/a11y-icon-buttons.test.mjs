// ADR-860 (Leezr 5.0 L7) — every icon-only button of a harmonised page has an accessible name.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import { unlabeledIconButtons } from '../../resources/js/utils/a11yIconButtons.js'

/** pages harmonised by L7 (and the shared shells they use) — the list grows sub-lot after sub-lot */
export const HARMONISED = [
  'resources/js/components/app/AppPageHeader.vue',
  'resources/js/pages/platform/billing/[tab].vue',
  'resources/js/pages/platform/settings/[tab].vue',
  'resources/js/layouts/components/PlatformLayoutWithVerticalNav.vue',
  'resources/js/pages/company/people/_PeopleSpace.vue',
  'resources/js/pages/company/people/_PeopleDirectory.vue',
  'resources/js/pages/company/people/_PersonPreview.vue',
]

test('the detector sees an unlabeled icon button and accepts a labeled one', () => {
  assert.equal(unlabeledIconButtons('<IconBtn @click="x"><VIcon icon="a" /></IconBtn>').length, 1)
  assert.equal(unlabeledIconButtons('<IconBtn :aria-label="t(\'x\')" />').length, 0)
  assert.equal(unlabeledIconButtons('<VBtn icon="tabler-x" title="Fermer" />').length, 0)
  assert.equal(unlabeledIconButtons('<VBtn icon variant="text"><VIcon icon="tabler-x" /></VBtn>').length, 1)
  assert.equal(unlabeledIconButtons('<VBtn prepend-icon="tabler-plus">Ajouter</VBtn>').length, 0)
  assert.equal(unlabeledIconButtons('<IconBtn v-bind="props" />').length, 1) // an activator gets no name from its menu
  assert.equal(unlabeledIconButtons('<IconBtn v-bind="props" :aria-label="t(\'x\')" />').length, 0)
  assert.equal(unlabeledIconButtons('<VBtn icon aria-hidden="true" tabindex="-1" style="pointer-events: none;" />').length, 0) // decorative
  assert.equal(unlabeledIconButtons('<VBtn icon aria-hidden="true" @click="x" />').length, 1) // hidden but still focusable : not accepted
})

// ADR-861 (L7.9) — the whole application : no icon-only button without a name (was 697 → 65 → 0)
test('no unlabeled icon button anywhere in the application', () => {
  const walk = d => fs.readdirSync(d, { withFileTypes: true }).flatMap(e => {
    const p = path.join(d, e.name)
    if (e.isDirectory()) return ['@core', '@layouts', 'design-lab'].includes(e.name) ? [] : walk(p)

    return p.endsWith('.vue') ? [p] : []
  })
  const found = walk(path.resolve('resources/js')).flatMap(f => unlabeledIconButtons(fs.readFileSync(f, 'utf8')).map(x => `${path.relative(process.cwd(), f)}:${x.line}`))
  assert.deepEqual(found, [])
})

for (const f of HARMONISED) {
  test(`no unlabeled icon button : ${f}`, () => {
    const found = unlabeledIconButtons(fs.readFileSync(path.resolve(f), 'utf8'))
    assert.deepEqual(found.map(x => `${x.line}: ${x.tag}`), [])
  })
}
