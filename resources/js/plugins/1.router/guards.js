import { useRuntimeStore } from '@/core/runtime/runtime'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'
import { safeRedirect } from '@/utils/safeRedirect'

export const setupGuards = router => {
  router.beforeEach(async to => {
    const runtime = useRuntimeStore()

    // ─── Public routes ───────────────────────────────────
    if (to.meta.public) {
      if (runtime.phase === 'cold') {
        await runtime.boot('public')
      }

      return
    }

    // ─── Determine scope ─────────────────────────────────
    const scope = to.meta.platform ? 'platform' : 'company'

    // ─── Boot runtime if cold or scope switched ──────────
    if (runtime.phase === 'cold' || runtime.scope !== scope) {
      if (runtime.scope && runtime.scope !== scope) {
        runtime.teardown()
      }
      await runtime.boot(scope)
    }

    // ─── Platform scope ──────────────────────────────────
    if (scope === 'platform') {
      const platformAuth = usePlatformAuthStore()

      if (to.meta.unauthenticatedOnly) {
        return platformAuth.isLoggedIn ? '/platform' : undefined
      }

      if (!platformAuth.isLoggedIn) {
        return { path: '/platform/login' }
      }

      if (to.meta.permission && !platformAuth.hasPermission(to.meta.permission)) {
        const { toast } = useAppToast()

        toast('You do not have permission to access this page.', 'error')

        return '/platform'
      }

      return
    }

    // ─── Company scope ───────────────────────────────────
    const auth = useAuthStore()

    if (to.meta.unauthenticatedOnly) {
      return auth.isLoggedIn ? '/' : undefined
    }

    if (!auth.isLoggedIn) {
      return { path: '/login', query: { redirect: safeRedirect(to.fullPath) } }
    }

    // Module guard — block access to inactive module routes
    if (to.meta.module) {
      const moduleStore = useModuleStore()

      // Modules should be loaded by runtime, but guard as safety net
      if (!moduleStore._loaded) {
        try {
          await moduleStore.fetchModules()
        }
        catch {
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
