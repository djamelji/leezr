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
  assert.equal(unlabeledIconButtons('<IconBtn v-bind="props" />').length, 0) // activator : named by its tooltip / menu
})

for (const f of HARMONISED) {
  test(`no unlabeled icon button : ${f}`, () => {
    const found = unlabeledIconButtons(fs.readFileSync(path.resolve(f), 'utf8'))
    assert.deepEqual(found.map(x => `${x.line}: ${x.tag}`), [])
  })
}
