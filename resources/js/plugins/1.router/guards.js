export const setupGuards = router => {
  router.beforeEach(to => {
    // Public routes — always accessible
    if (to.meta.public)
      return

    const isLoggedIn = !!(useCookie('userData').value && useCookie('accessToken').value)

    // Unauthenticated-only routes (login, register) — redirect home if logged in
    if (to.meta.unauthenticatedOnly) {
      if (isLoggedIn)
        return '/'
      else
        return undefined
    }

    // TODO: Add Passport/Sanctum auth guard here
  })
}
