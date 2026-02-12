import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'

export const setupGuards = router => {
  router.beforeEach(async to => {
    // Public routes — always accessible
    if (to.meta.public)
      return

    // ─── Platform scope ─────────────────────────────────
    if (to.meta.platform) {
      const platformAuth = usePlatformAuthStore()

      // Platform unauthenticated-only routes (platform login)
      if (to.meta.unauthenticatedOnly) {
        if (platformAuth.isLoggedIn)
          return '/platform'
        else
          return undefined
      }

      // Platform protected routes — must be logged in as platform user
      if (!platformAuth.isLoggedIn)
        return { path: '/platform/login' }

      // Platform permission guard
      if (to.meta.permission && !platformAuth.hasPermission(to.meta.permission)) {
        const { toast } = useAppToast()

        toast('You do not have permission to access this page.', 'error')

        return '/platform'
      }

      return
    }

    // ─── Company scope ──────────────────────────────────
    const auth = useAuthStore()
    const isLoggedIn = auth.isLoggedIn

    // Unauthenticated-only routes (login, register) — redirect to home if logged in
    if (to.meta.unauthenticatedOnly) {
      if (isLoggedIn)
        return '/'
      else
        return undefined
    }

    // Protected routes — redirect to login if not logged in
    if (!isLoggedIn)
      return { path: '/login', query: { redirect: to.fullPath } }

    // Module guard — block access to inactive module routes
    if (to.meta.module) {
      const moduleStore = useModuleStore()

      // If modules not loaded yet (e.g., page refresh), load them now
      if (!moduleStore._loaded) {
        try {
          await moduleStore.fetchModules()
        }
        catch {
          // If fetch fails, allow navigation (layout will retry)
          return
        }
      }

      if (!moduleStore.isActive(to.meta.module)) {
        const { toast } = useAppToast()

        toast('Module not available for your company.', 'warning')

        return '/'
      }
    }
  })
}
