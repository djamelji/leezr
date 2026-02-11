// 👉 Redirects
export const redirects = [
  // ℹ️ We are redirecting to different pages based on role.
  // TODO: Uncomment role-based redirects when dashboard pages are created
  // {
  //   path: '/',
  //   name: 'index',
  //   redirect: to => {
  //     const userData = useCookie('userData')
  //     const userRole = userData.value?.role
  //     if (userRole === 'admin')
  //       return { name: 'dashboards-crm' }
  //     if (userRole === 'client')
  //       return { name: 'access-control' }
  //     return { name: 'login', query: to.query }
  //   },
  // },
]

// 👉 Routes
// TODO: Add app-specific routes as pages are created from presets
export const routes = []
