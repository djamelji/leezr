import { useI18n } from 'vue-i18n'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useNavStore } from '@/core/stores/nav'

/**
 * Platform navigation items — manifest-driven, permission-filtered.
 *
 * When navStore is loaded (backend groups available), converts groups to nav items.
 * Falls back to legacy cookie-based nav when navStore not yet loaded.
 */
export function usePlatformNav() {
  const { t } = useI18n()
  const auth = usePlatformAuthStore()
  const navStore = useNavStore()

  const navItems = computed(() => {
    if (navStore.platformLoaded) {
      return groupsToNavItems(navStore.platformGroups, auth, t)
    }

    // TODO(ADR-114): Remove legacy fallback. Convergence test: NavEndpointTest::test_platform_nav_matches_legacy
    return legacyPlatformNav(auth)
  })

  const firstAccessibleRoute = computed(() => {
    const first = navItems.value.find(i => !i.heading && i.to)

    return first?.to ?? '/platform'
  })

  return { navItems, firstAccessibleRoute }
}

/**
 * Convert backend groups to flat nav items with headings.
 * Uses t(group.titleKey) for i18n headings.
 * Last-barrier permission check only (backend already filtered).
 */
function groupsToNavItems(groups, auth, t) {
  const items = []

  for (const group of groups) {
    if (group.titleKey) {
      items.push({ heading: t(group.titleKey) })
    }

    for (const item of group.items) {
      const navItem = {
        title: item.title,
        to: item.to,
        icon: { icon: item.icon },
        permission: item.permission || null,
      }

      // Only set children when non-empty — Vuexy uses 'children' in item
      // to decide between VerticalNavLink vs VerticalNavGroup
      const children = (item.children || []).map(c => ({
        title: c.title,
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
 * Legacy fallback: existing usePlatformNav logic (cookie-based).
 */
function legacyPlatformNav(auth) {
  const moduleNavItems = (auth.platformModuleNavItems || []).map(item => ({
    title: item.title,
    to: item.to,
    icon: { icon: item.icon },
    permission: item.permission || null,
  }))

  const dashboard = moduleNavItems.find(i => i.to?.name === 'platform')
  const rest = moduleNavItems.filter(i => i.to?.name !== 'platform')

  const items = [
    ...(dashboard ? [dashboard] : []),
    { heading: 'Management' },
    ...rest,
  ]

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
