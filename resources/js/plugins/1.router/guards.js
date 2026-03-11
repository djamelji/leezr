import { useRuntimeStore } from '@/core/runtime/runtime'
import { bootMachine } from '@/core/runtime/bootMachine'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useModuleStore } from '@/core/stores/module'
import { useWorldStore } from '@/core/stores/world'
import { useAppToast } from '@/composables/useAppToast'
import { safeRedirect } from '@/utils/safeRedirect'

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
      // Teardown if switching from a non-public scope (prevents ready→ready)
      if (bootMachine.scope.value && bootMachine.scope.value !== 'public') {
        runtime.teardown()
      }
      await runtime.boot('public')

      return
    }

    // ─── Determine scope ─────────────────────────────────
    const scope = to.meta.platform ? 'platform' : 'company'

    // Ensure world settings are fetched (fire-and-forget, non-blocking)
    useWorldStore().fetch()

    // ─── Boot runtime (idempotent) ───────────────────────
    // ADR-160: Single awaitable boot() replaces needsBoot + whenReady.
    // - Already READY for same scope → no-op (returns immediately)
    // - Already BOOTING for same scope → awaits existing boot (dedup)
    // - Different scope → teardown + fresh boot
    // - FAILED → re-entry blocked; AppShellGate shows error
    if (bootMachine.scope.value !== scope || !bootMachine.isReady.value) {
      if (bootMachine.scope.value && bootMachine.scope.value !== scope) {
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

      // Module guard — block if module is disabled globally
      if (to.meta.module && platformAuth.isModuleInactive(to.meta.module)) {
        const { toast } = useAppToast()

        toast('This module is currently disabled.', 'warning')

        return '/platform'
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
      return auth.isLoggedIn ? '/dashboard' : undefined
    }

    if (!auth.isLoggedIn) {
      return { path: '/login', query: { redirect: safeRedirect(to.fullPath) } }
    }

    // Surface guard — structure routes require tenant hydration + management level
    if (to.meta.surface === 'structure') {
      if (!auth.currentCompany) {
        return { path: '/login' }
      }

      if (auth.roleLevel === 'operational') {
        return { name: 'company403' }
      }
    }

    // Module guard — boot is already complete, modules are hydrated
    if (to.meta.module) {
      const moduleStore = useModuleStore()

      if (!moduleStore.isActive(to.meta.module)) {
        const { toast } = useAppToast()

        toast('Module not available for your company.', 'warning')

        return '/dashboard'
      }
    }
  })

  // ─── Overlay cleanup (ADR-075) ─────────────────────────
  // After each navigation, clean up stray overlay artifacts.
  // Uses el.click() instead of class removal so the Vue click handler
  // in VerticalNavLayout resets the reactive refs (isOverlayNavActive,
  // isLayoutOverlayVisible) — class-only removal gets re-added by Vue.
  // ADR-330b: Overlay cleanup after navigation.
  // NOTE: lzr-chunk-error is NOT removed here — it must persist until either:
  //   1. Post-mount cleanup (main.js) confirms Vue mounted successfully
  //   2. Smart refresh reloads the page entirely
  // Removing it in afterEach caused "blank page" because Vue partially mounts,
  // router navigates (afterEach fires), overlay disappears, but sub-components
  // still fail → user sees empty page for several seconds.
  router.afterEach(() => {
    // Layout overlay stuck in visible state — dismiss at ALL breakpoints.
    // The VerticalNav watcher already handles intentional nav close on
    // route change, so this is purely a safety net for stuck overlays.
    document.querySelectorAll('.layout-overlay.visible').forEach(el => {
      el.click()
    })
  })
}
