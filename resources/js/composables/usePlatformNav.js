import { usePlatformAuthStore } from '@/core/stores/platformAuth'

const staticItems = [
  { heading: 'Management' },
]

/**
 * Platform navigation items — module-driven, permission-filtered.
 * Mirrors useCompanyNav() for architectural convergence.
 */
export function usePlatformNav() {
  const auth = usePlatformAuthStore()

  const navItems = computed(() => {
    const moduleNavItems = (auth.platformModuleNavItems || []).map(item => ({
      title: item.title,
      to: item.to,
      icon: { icon: item.icon },
      permission: item.permission || null,
    }))

    // Dashboard is always first (no heading above it)
    const dashboard = moduleNavItems.find(i => i.to?.name === 'platform')
    const rest = moduleNavItems.filter(i => i.to?.name !== 'platform')

    const items = [
      ...(dashboard ? [dashboard] : []),
      ...staticItems,
      ...rest,
    ]

    return items.filter(item => {
      if (item.heading) return true
      if (!item.permission) return true

      return auth.hasPermission(item.permission)
    }).filter((item, index, arr) => {
      if (item.heading) {
        const next = arr[index + 1]

        return next && !next.heading
      }

      return true
    })
  })

  const firstAccessibleRoute = computed(() => {
    const first = navItems.value.find(i => !i.heading && i.to)

    return first?.to ?? '/platform'
  })

  return { navItems, firstAccessibleRoute }
}
