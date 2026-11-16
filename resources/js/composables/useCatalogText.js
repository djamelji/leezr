import { useI18n } from 'vue-i18n'

/**
 * ADR-806 — translated texts of the permission catalog (capabilities = bundles, modules, permissions).
 *
 * The catalog keys contain dots (`shipments.operations`), so `t('permissionCatalog.bundles.shipments.operations.label')`
 * can NEVER resolve : vue-i18n splits the path on every dot. Every screen therefore showed the English
 * manifest label. The group is read as an object (`tm`) and the entry looked up by its literal key ; the
 * locale chain (fallback `en`) is kept by `tm`. Returns `fallback` (the manifest text) when untranslated.
 */
export function useCatalogText() {
  const { tm, rt } = useI18n()

  const catalogText = (kind, key, field, fallback = null) => {
    const group = tm(`permissionCatalog.${kind}`)
    const value = group && typeof group === 'object' ? group[key]?.[field] : null

    return value ? rt(value) : fallback
  }

  // Module names : `moduleCatalog.<key with _>.name` (same source as the Modules page), manifest name otherwise.
  const { t, te } = useI18n()
  const moduleName = (key, fallback = null) => {
    const k = `moduleCatalog.${String(key || '').replace(/\./g, '_')}.name`

    return te(k) ? t(k) : (fallback ?? key)
  }

  return {
    catalogText,
    moduleName,
    capLabel: c => catalogText('bundles', c.key, 'label', c.label ?? null),
    capHint: c => catalogText('bundles', c.key, 'hint', c.hint ?? null),
  }
}
