import staticNavItems, { coreNavItems } from '@/navigation/vertical'
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'

// Route names from core fallback items (for deduplication)
const coreRouteNames = new Set(coreNavItems.map(i => i.to?.name).filter(Boolean))

/**
 * Company navigation items — filtered by surface, permission, ownerOnly.
 * Shared between layout and forbidden page (single source of truth).
 */
export function useCompanyNav() {
  const auth = useAuthStore()
  const moduleStore = useModuleStore()

  const navItems = computed(() => {
    const moduleNavItems = moduleStore.activeNavItems.map(item => ({
      title: item.title,
      to: item.to,
      icon: { icon: item.icon },
      permission: item.permission,
      surface: item.surface,
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

    // Filter by surface → ownerOnly → permission
    return items.filter(item => {
      if (item.heading) return true
      if (item.surface === 'structure' && auth.roleLevel !== 'management') return false
      if (item.ownerOnly && !auth.isOwner) return false
      if (item.permission && !auth.hasPermission(item.permission)) return false

      return true
    }).filter((item, index, arr) => {
      // Remove orphan headings (heading followed by another heading or end)
      if (item.heading) {
        const next = arr[index + 1]

        return next && !next.heading
      }

      return true
    })
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
