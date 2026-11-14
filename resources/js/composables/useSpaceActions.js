import { useNavStore } from '@/core/stores/nav'

/**
 * ADR-775 (Company Experience 2.0 / UX0) — contextual actions of an intent space.
 *
 * Modules contribute actions through their manifest ; the server returns only those the user may
 * perform (active module + RBAC). A host asks for ITS space and context — it never lists modules :
 *
 *   const { actions, primary } = useSpaceActions('team')                  // space header
 *   const { actions } = useSpaceActions('team', { context: 'person' })    // person file
 *   const { actions } = useHomeActions()                                  // home page
 *
 * Each action : { key, space, icon, to, context, home, primary, label } — `label` is the i18n key
 * `actions.<key>`. `to` is null when the host opens the action in place (drawer, dialog).
 */
const withLabel = a => ({ ...a, label: `actions.${a.key}` })

export function useSpaceActions(space, { context = 'space' } = {}) {
  const nav = useNavStore()
  const actions = computed(() => nav.companyActions.filter(a => a.space === space && a.context === context).map(withLabel))
  const primary = computed(() => actions.value.find(a => a.primary) || null)

  return { actions, primary }
}

export function useHomeActions(limit = 4) {
  const nav = useNavStore()
  const actions = computed(() => nav.companyActions.filter(a => a.home && a.to).slice(0, limit).map(withLabel))

  return { actions }
}
