/**
 * Static navigation items — always visible regardless of module state.
 * Core items (Members, Settings) serve as fallback before modules load.
 * The layout replaces these with module-driven items once available,
 * and appends additional module items (Shipments, etc.) after them.
 *
 * surface: 'structure' = governance (hidden from operational roles)
 * surface: 'operations' = business (visible to all roles)
 * no surface = always visible (Dashboard, Account)
 */
export const coreNavItems = [
  {
    title: 'Members',
    to: { name: 'company-members' },
    icon: { icon: 'tabler-users' },
    permission: 'members.view',
    surface: 'structure',
  },
  {
    title: 'Settings',
    to: { name: 'company-settings' },
    icon: { icon: 'tabler-building' },
    permission: 'settings.view',
    surface: 'structure',
  },
]

export default [
  {
    title: 'Dashboard',
    to: { name: 'dashboard' },
    icon: { icon: 'tabler-smart-home' },
  },
  { heading: 'Company' },
  ...coreNavItems,
  { heading: 'Account' },
  {
    title: 'Account Settings',
    to: { name: 'account-settings-tab', params: { tab: 'account' } },
    icon: { icon: 'tabler-settings' },
  },
]
