import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/core/stores/auth'
import { useNavStore } from '@/core/stores/nav'

/**
 * Company navigation items — manifest-driven, permission-filtered.
 *
 * Converts backend nav groups to flat nav items with headings.
 */
export function useCompanyNav() {
  const { t } = useI18n()
  const auth = useAuthStore()
  const navStore = useNavStore()

  const navItems = computed(() => {
    return companyGroupsToNavItems(navStore.companyGroups, auth, t)
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
 * Filter out orphan headings (heading followed by another heading or end).
 */
function orphanHeadingFilter(item, index, arr) {
  if (item.heading) {
    const next = arr[index + 1]

    return next && !next.heading
  }

  return true
}
