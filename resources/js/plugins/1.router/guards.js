import { useRuntimeStore } from '@/core/runtime/runtime'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'
import { safeRedirect } from '@/utils/safeRedirect'

/**
 * Structure routes — governance pages hidden from operational roles.
 * Checked AFTER tenant hydration so roleLevel is reliable.
 */
const STRUCTURE_ROUTES = new Set([
  'company-members',
  'company-members-id',
  'company-settings',
  'company-modules',
  'company-jobdomain',
  'company-roles',
])

export const setupGuards = router => {
  router.beforeEach(async to => {
    // Version mismatch check (ADR-045e)
    const mismatch = sessionStorage.getItem('lzr:version-mismatch')
    if (mismatch) {
      sessionStorage.removeItem('lzr:version-mismatch')
      window.location.reload()

      return false
    }

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
      runtime.boot(scope)                    // fire (starts full boot)
      await runtime.whenAuthResolved()       // await auth phase ONLY
    }
    // ─── Re-boot if in error state ─────────────────────
    else if (runtime.phase === 'error') {
      runtime.teardown()
      runtime.boot(scope)
      await runtime.whenAuthResolved()
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

    // Surface guard — structure routes require tenant hydration + management level
    if (STRUCTURE_ROUTES.has(to.name)) {
      if (!runtime.isReady) {
        await runtime.whenReady(5000)
      }

      if (!auth.currentCompany) {
        return { path: '/login' }
      }

      if (auth.roleLevel === 'operational') {
        return { name: 'company403' }
      }
    }

    // Module guard — must await ready for module-gated routes
    if (to.meta.module) {
      if (!runtime.isReady) {
        await runtime.whenReady(5000)
      }

      const moduleStore = useModuleStore()

      if (!moduleStore.isActive(to.meta.module)) {
        const { toast } = useAppToast()

        toast('Module not available for your company.', 'warning')

        return '/dashboard'
      }
    }
  })

  // ─── Overlay cleanup (ADR-075) ─────────────────────────
  // After each navigation, clean up stray overlay artifacts that may
  // persist from bfcache restoration, chunk error recovery, or Vuetify
  // overlay leaks. This runs AFTER the new page renders.
  router.afterEach(() => {
    // Chunk error overlay (raw DOM, not managed by Vue)
    document.getElementById('lzr-chunk-error')?.remove()

    // Layout overlay stuck in visible state (e.g. bfcache restore at mobile breakpoint)
    // Only clean up if we're on desktop (>= 1280px) — on mobile the overlay may be intentional
    if (window.innerWidth >= 1280) {
      document.querySelectorAll('.layout-overlay.visible').forEach(el => {
        el.classList.remove('visible')
      })
    }
  })
}
