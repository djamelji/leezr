import { useRuntimeStore } from '@/core/runtime/runtime'
import { bootMachine } from '@/core/runtime/bootMachine'
import { useAuthStore } from '@/core/stores/auth'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useModuleStore } from '@/core/stores/module'
import { useWorldStore } from '@/core/stores/world'

export const setupGuards = router => {
  router.beforeEach(async to => {
    // Version mismatch check (ADR-045e / ADR-341)
    // Instead of an immediate reload (which loops), show the overlay once.
    const mismatch = sessionStorage.getItem('lzr:version-mismatch')
    if (mismatch) {
      sessionStorage.removeItem('lzr:version-mismatch')

      if (typeof window.__lzrShowVersionOverlay === 'function') {
        window.__lzrShowVersionOverlay()
      }

      return false
    }

    // Logistics Only — laboratoires de conception (ui-lab, dev-lab, presentation-lab) : serveur de
    // développement uniquement, jamais dans le produit construit (routes non livrées).
    if (to.meta.devOnly && !import.meta.env.DEV)
      return { path: '/' }

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
      // ADR-801 : `/platform` is the dispatcher (fallback of every refusal) — never bounce it onto itself.
      // ADR-839 (M3, UXP-04) : an explicit page, never a silent bounce.
      if (to.meta.module && to.path !== '/platform' && platformAuth.isModuleInactive(to.meta.module))
        return { name: 'platform403', query: { reason: 'module', from: to.fullPath } }

      // ADR-801 (P0-2) — tabbed hubs declare `anyPermission` (union of their tabs' permissions), same
      // contract as the company branch. A forbidden page sends back to `/platform`, which dispatches to
      // the first accessible entry (never assumed super-admin).
      const deniedPermission = to.meta.permission && !platformAuth.hasPermission(to.meta.permission)
      const deniedAny = Array.isArray(to.meta.anyPermission) && !to.meta.anyPermission.some(p => platformAuth.hasPermission(p))
      if (deniedPermission || deniedAny)
        return to.path === '/platform' ? false : { name: 'platform403', query: { from: to.fullPath } }

      return
    }

    // ─── Company scope ───────────────────────────────────
    const auth = useAuthStore()

    if (to.meta.unauthenticatedOnly) {
      // ADR-357: Redirect to correct workspace
      return auth.isLoggedIn ? '/home' : undefined // ADR-818 : un seul accueil
    }

    if (!auth.isLoggedIn) {
      // ADR-357: Clean URLs — sessionStorage instead of ?redirect=
      if (to.fullPath !== '/dashboard' && to.fullPath !== '/home' && to.fullPath !== '/') {
        sessionStorage.setItem('auth_redirect', to.fullPath)
      }

      return { path: '/login' }
    }

    // ADR-818 (chantier K) : connecté mais sans AUCUNE société active (invitation pas encore acceptée,
    // accès retiré) → la page des invitations, jamais un écran vide ni des 400 en cascade.
    if (auth.companies.length === 0 && to.path !== '/invitations')
      return { path: '/invitations' }

    // ADR-357: Workspace cross-guard. Un field worker ne doit PAS accéder à la gestion →
    // /dashboard est renvoyé vers /home. L'inverse n'est PLUS forcé (ADR-751b) : un
    // administrateur (workspace 'dashboard') qui est AUSSI salarié peut consulter
    // explicitement son espace opérationnel /home (accès proposé depuis /dashboard). Le
    // landing par défaut reste piloté par le workspace (unauthenticatedOnly ci-dessus).
    if (to.path === '/dashboard' && auth.workspace === 'home') {
      return { path: '/home' }
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

      // ADR-839 (Leezr 4.2 M3, UXC-02) — never a silent redirect : an explicit page says whether the module is
      // not activated, not in the plan or not available, and offers only the actions the user may take.
      if (!moduleStore.isActive(to.meta.module))
        return { name: 'company-module-unavailable', query: { module: to.meta.module, from: to.fullPath } }
    }

    // Permission guard — ADR-418: router = autorité centrale RBAC
    // ADR-840 (M3.1) : a right awaiting an administrator's confirmation gets its own explanation (still refused).
    if (to.meta.permission && !auth.hasPermission(to.meta.permission)) {
      return auth.isPermissionPending(to.meta.permission) ? { name: 'company403', query: { reason: 'pending', from: to.fullPath } } : { name: 'company403' }
    }
    // E2 (ADR-690): anyPermission — accessible if the user holds AT LEAST ONE key.
    // Lets an activity space open for any of its capabilities (e.g. Temps de travail
    // for planning_view OR timesheet_view). Tabs inside self-gate per fine permission.
    if (Array.isArray(to.meta.anyPermission) && !to.meta.anyPermission.some(p => auth.hasPermission(p))) {
      return to.meta.anyPermission.some(p => auth.isPermissionPending(p)) ? { name: 'company403', query: { reason: 'pending', from: to.fullPath } } : { name: 'company403' }
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
