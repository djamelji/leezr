import { useAuthStore } from '@/core/stores/auth'

export const setupGuards = router => {
  router.beforeEach(to => {
    // Public routes — always accessible
    if (to.meta.public)
      return

    const auth = useAuthStore()
    const isLoggedIn = auth.isLoggedIn

    // Unauthenticated-only routes (login, register) — redirect home if logged in
    if (to.meta.unauthenticatedOnly) {
      if (isLoggedIn)
        return '/'
      else
        return undefined
    }

    // Protected routes — redirect to login if not logged in
    if (!isLoggedIn)
      return { path: '/login', query: { redirect: to.fullPath } }
  })
}
