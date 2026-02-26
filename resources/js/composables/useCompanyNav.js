import { useI18n } from 'vue-i18n'
import staticNavItems, { coreNavItems } from '@/navigation/vertical'
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
import { useNavStore } from '@/core/stores/nav'

// Route names from core fallback items (for deduplication)
const coreRouteNames = new Set(coreNavItems.map(i => i.to?.name).filter(Boolean))

/**
 * Company navigation items — manifest-driven, permission-filtered.
 *
 * When navStore is loaded (backend groups available), converts groups to nav items.
 * Falls back to legacy module-store-based nav when navStore not yet loaded.
 */
export function useCompanyNav() {
  const { t } = useI18n()
  const auth = useAuthStore()
  const moduleStore = useModuleStore()
  const navStore = useNavStore()

  const navItems = computed(() => {
    if (navStore.companyLoaded) {
      return companyGroupsToNavItems(navStore.companyGroups, auth, t)
    }

    // TODO(ADR-114): Remove legacy fallback. Convergence test: NavEndpointTest::test_company_nav_matches_legacy
    return legacyCompanyNav(auth, moduleStore)
  })

  /**
   * First navigable route from filtered nav items.
   * Used as fallback redirect for forbidden page.
   */
  const firstAccessibleRoute = computed(() => {
    const first = navItems.value.find(i => !i.heading && i.to)

    return first?.to ?? '/'
  })

  return { navItems, firstAccessibleRoute }
}

/**
 * Convert backend groups to flat nav items.
 * Static items (Dashboard, Account Settings) added around groups.
 * Frontend only applies last-barrier permission check.
 */
function companyGroupsToNavItems(groups, auth, t) {
  const items = [
    {
      title: 'Dashboard',
      to: { name: 'dashboard' },
      icon: { icon: 'tabler-smart-home' },
    },
  ]

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

  items.push({ heading: 'Account' })
  items.push({
    title: 'Account Settings',
    to: { name: 'account-settings-tab', params: { tab: 'account' } },
    icon: { icon: 'tabler-settings' },
  })

  return items.filter(item => {
    if (item.heading) return true
    if (!item.permission) return true

    return auth.hasPermission(item.permission)
  }).filter(orphanHeadingFilter)
}

/**
 * Legacy fallback: existing useCompanyNav logic (module-store-based).
 */
function legacyCompanyNav(auth, moduleStore) {
  const moduleNavItems = moduleStore.activeNavItems.map(item => ({
    title: item.title,
    to: item.to,
    icon: { icon: item.icon },
    permission: item.permission,
    surface: item.surface,
    operationalOnly: item.operationalOnly,
  }))

  let items

  // If modules loaded, filter out static core items that modules now provide
  if (moduleStore._loaded && moduleNavItems.length > 0) {
    const moduleRouteNames = new Set(moduleNavItems.map(i => i.to?.name).filter(Boolean))

    items = staticNavItems.filter(item => {
      if (!item.to?.name) return true

      // Keep static item unless a module provides it
      return !moduleRouteNames.has(item.to.name)
    })

    // Insert module items after "Company" heading
    const companyIdx = items.findIndex(i => i.heading === 'Company')
    if (companyIdx !== -1)
      items.splice(companyIdx + 1, 0, ...moduleNavItems)
    else
      items.push(...moduleNavItems)
  }
  else {
    // Fallback: static items as-is (includes core Members/Settings)
    items = [...staticNavItems]
  }

  // Filter by surface + permission
  return items.filter(item => {
    if (item.heading) return true
    if (item.surface === 'structure' && auth.roleLevel !== 'management') return false
    if (item.operationalOnly && auth.roleLevel === 'management') return false
    if (item.permission && !auth.hasPermission(item.permission)) return false

    return true
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
