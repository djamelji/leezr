import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useNavStore } from '@/core/stores/nav'
import { useEmailUnreadStore } from '@/core/stores/emailUnread'

/**
 * Platform navigation items — manifest-driven, permission-filtered.
 *
 * Converts backend nav groups to flat nav items with headings.
 * Titles are i18n KEYS — @layouts components translate via getDynamicI18nProps.
 */
export function usePlatformNav() {
  const auth = usePlatformAuthStore()
  const navStore = useNavStore()
  const emailUnread = useEmailUnreadStore()

  // ADR-610 (LOT 2): boot the unread counter for users who can access messaging, so the badge is
  // live even when the Inbox page is never opened. Idempotent (the store guards re-subscription).
  if (auth.hasPermission('manage_email')) emailUnread.init()

  const navItems = computed(() => {
    // Reading badgeContent here makes navItems recompute when the count changes (reactive badge).
    return groupsToNavItems(navStore.platformGroups, auth, emailUnread.badgeContent)
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
function groupsToNavItems(groups, auth, messagingBadge = undefined) {
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

      // ADR-610 (LOT 2): live unread badge on the Messaging item (native Vuexy badgeContent).
      // Falsy → no badge (count 0). bg-error = the Vuexy error-tinted pill.
      if (item.key === 'platform-messaging' && messagingBadge) {
        navItem.badgeContent = messagingBadge
        navItem.badgeClass = 'bg-error'
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
