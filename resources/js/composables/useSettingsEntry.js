import { useNavStore } from '@/core/stores/nav'

/**
 * ADR-781 (UX5) — a CONTEXTUAL link to a setting, from the work it configures. The entry is taken from
 * the Settings hub delivered by the server (already filtered by module + RBAC) : no entry, no link —
 * never a link to a page the user cannot open. `back` brings the user back to where they were.
 */
export function useSettingsEntry(key) {
  const nav = useNavStore()
  const route = useRoute()

  const entry = computed(() => {
    const hub = nav.companyGroups.find(g => g.key === 'settings')?.items?.find(i => i.hub)

    return (hub?.children || []).find(c => c.key === key) || null
  })
  const to = computed(() => (entry.value
    ? { ...entry.value.to, query: { ...(entry.value.to?.query || {}), back: route.fullPath } }
    : null))

  return { entry, to }
}
