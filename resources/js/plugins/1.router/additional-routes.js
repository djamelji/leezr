// 👉 Redirects
export const redirects = [
  { path: '/platform/users', redirect: '/platform/access/users' },
  { path: '/platform/roles', redirect: '/platform/access/roles' },
  { path: '/platform/supervision/companies', redirect: '/platform/companies' },
  { path: '/platform/supervision/members', redirect: '/platform/companies' },
  { path: '/platform/company/users', redirect: '/platform/companies' },
  { path: '/platform/audit', redirect: '/platform/access/logs' },

  // ADR-770 (M7 LOT 4) — chemins « nus » des espaces à onglets : sans onglet, ils tombaient sur la
  // page 404. Ils mènent à l'onglet par défaut (la page décide ensuite selon les capacités).
  { path: '/company/my-space', redirect: '/company/my-space/overview' },
  { path: '/company/documents', redirect: '/company/documents/overview' },
  { path: '/account-settings', redirect: '/account-settings/account' },
  { path: '/profile', redirect: '/account-settings/account' },
]

// 👉 Routes
export const routes = []
