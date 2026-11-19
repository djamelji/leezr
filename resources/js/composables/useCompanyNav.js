import { useAuthStore } from '@/core/stores/auth'
import { useNavStore } from '@/core/stores/nav'

/**
 * Company navigation items — manifest-driven, permission-filtered.
 *
 * Converts backend nav groups to flat nav items with headings.
 * Titles are i18n KEYS — @layouts components translate via getDynamicI18nProps.
 */
export function useCompanyNav() {
  const auth = useAuthStore()
  const navStore = useNavStore()
  const router = useRouter()

  const navItems = computed(() => {
    return companyGroupsToNavItems(navStore.companyGroups, auth, router)
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
 * All items are manifest-driven — NO hardcoded items.
 * Account Settings comes from SettingsModule (group: 'account').
 * Titles are i18n KEYS — @layouts components translate via getDynamicI18nProps.
 * Frontend only applies last-barrier permission check.
 */
function companyGroupsToNavItems(groups, auth, router) {
  const items = []
  const target = to => isolateTabParam(to, router)

  for (const group of groups) {
    // ADR-775 : groups placed in the avatar menu are not side-menu entries.
    if (group.placement === 'menu') continue

    if (group.titleKey) {
      items.push({ heading: group.titleKey })
    }

    for (const item of group.items) {
      const navItem = {
        // ADR-775 : an intent space labels its entry (`labelKey`) ; otherwise the item's own label.
        title: item.labelKey || `nav.company.${item.key}`,
        to: target(item.to),
        icon: { icon: item.icon },
        permission: item.permission || null,
      }

      // Only set children when non-empty — Vuexy uses 'children' in item
      // to decide between VerticalNavLink vs VerticalNavGroup
      // ADR-781 (UX5) : a HUB entry (Réglages) is ONE link to its hub page ; its entries live there.
      const children = (item.hub ? [] : (item.children || [])).map(c => ({
        title: `nav.company.${c.key}`,
        to: target(c.to),
        icon: { icon: c.icon },
      }))

      if (children.length > 0) {
        navItem.children = children
        if (Array.isArray(navItem.to) || !navItem.to || !Object.keys(navItem.to).length) delete navItem.to
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
 * ADR-831 — a named menu target never inherits the CURRENT page's optional `tab`.
 * vue-router keeps the optional params of the parent record when a named location gives none, and every page is
 * wrapped in a layout parent carrying `:tab?` : from /company/workforce/leave/config, « Préparation de la paie »
 * resolved to /company/workforce/payroll/config. A target whose route has a `tab` param and gives none explicitly
 * resets it (default tab) ; routes without `tab` are left untouched (no discarded-param warning).
 */
export function isolateTabParam(to, router) {
  if (!to || typeof to !== 'object' || Array.isArray(to) || !to.name || (to.params && 'tab' in to.params)) return to
  const record = router?.getRoutes().find(r => r.name === to.name)

  // '' (not undefined : vue-router drops nullish params before merging, which would re-inherit the current one)
  return record && /:tab\b/.test(record.path) ? { ...to, params: { ...(to.params || {}), tab: '' } } : to
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
