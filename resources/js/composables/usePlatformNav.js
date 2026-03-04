import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useNavStore } from '@/core/stores/nav'

/**
 * Platform navigation items — manifest-driven, permission-filtered.
 *
 * Converts backend nav groups to flat nav items with headings.
 * Titles are i18n KEYS — @layouts components translate via getDynamicI18nProps.
 */
export function usePlatformNav() {
  const auth = usePlatformAuthStore()
  const navStore = useNavStore()

  const navItems = computed(() => {
    return groupsToNavItems(navStore.platformGroups, auth)
  })

  const firstAccessibleRoute = computed(() => {
    const first = navItems.value.find(i => !i.heading && i.to)

    return first?.to ?? '/platform'
  })

  return { navItems, firstAccessibleRoute }
}

/**
 * Convert backend groups to flat nav items with headings.
 * Titles are i18n KEYS — @layouts components translate via getDynamicI18nProps.
 * Last-barrier permission check only (backend already filtered).
 */
function groupsToNavItems(groups, auth) {
  const items = []

  for (const group of groups) {
    if (group.titleKey) {
      items.push({ heading: group.titleKey })
    }

    for (const item of group.items) {
      const navItem = {
        title: `nav.platform.${item.key}`,
        to: item.to,
        icon: { icon: item.icon },
        permission: item.permission || null,
      }

      // Only set children when non-empty — Vuexy uses 'children' in item
      // to decide between VerticalNavLink vs VerticalNavGroup
      const children = (item.children || []).map(c => ({
        title: `nav.platform.${c.key}`,
        to: c.to,
        icon: { icon: c.icon },
      }))

      if (children.length > 0) {
        navItem.children = children
      }

      items.push(navItem)
    }
  }

  return items.filter(item => {
    if (item.heading) return true
    if (!item.permission) return true

    return auth.hasPermission(item.permission)
  }).filter(orphanHeadingFilter)
}

/**
 * Filter out orphan headings (heading followed by another heading or end).
 */
function orphanHeadingFilter(item, index, arr) {
  if (item.heading) {
    const next = arr[index + 1]

    return next && !next.heading
  }

  return true
}
