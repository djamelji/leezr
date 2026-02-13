import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'
import { safeRedirect } from '@/utils/safeRedirect'

export const setupGuards = router => {
  router.beforeEach(async to => {
    // Public routes — always accessible
    if (to.meta.public)
      return

    // ─── Platform scope ─────────────────────────────────
    if (to.meta.platform) {
      const platformAuth = usePlatformAuthStore()

      // Hydrate session on first navigation (cookie = cache, server = truth)
      if (!platformAuth._hydrated && platformAuth.isLoggedIn) {
        await platformAuth.fetchMe()
      }

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

    // Hydrate session on first navigation (cookie = cache, server = truth)
    if (!auth._hydrated && auth.isLoggedIn) {
      await auth.fetchMe()

      // Session was stale — server says not authenticated
      if (!auth.isLoggedIn) {
        return { path: '/login', query: { redirect: safeRedirect(to.fullPath) } }
      }
    }

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
      return { path: '/login', query: { redirect: safeRedirect(to.fullPath) } }

    // Module guard — block access to inactive module routes
    if (to.meta.module) {
      const moduleStore = useModuleStore()

      // If modules not loaded yet (e.g., page refresh), load them now
      if (!moduleStore._loaded) {
        try {
          await moduleStore.fetchModules()
        }
        catch {
          // Fail-closed: if fetch fails, block access to module route
          const { toast } = useAppToast()

          toast('Unable to verify module access. Please try again.', 'error')

          return '/'
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
