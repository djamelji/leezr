/**
 * Settings Panel Registry
 *
 * Maps component names declared in module capabilities (settingsPanels)
 * to lazy-loaded Vue component imports.
 *
 * To register a new module's settings panel, add one entry to the Map.
 * The page renderer ([key].vue) resolves panels dynamically — zero per-module branching.
 */
const registry = new Map([
  ['ThemeRoleVisibility', () => import('@/pages/company/modules/_ThemeRoleVisibility.vue')],
])

export function resolveSettingsPanel(name) {
  return registry.get(name) ?? null
}
