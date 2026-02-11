/**
 * Static navigation items — always visible regardless of module state.
 * Core items (Members, Settings) serve as fallback before modules load.
 * The layout replaces these with module-driven items once available,
 * and appends additional module items (Shipments, etc.) after them.
 */
export const coreNavItems = [
  {
    title: 'Members',
    to: { name: 'company-members' },
    icon: { icon: 'tabler-users' },
  },
  {
    title: 'Settings',
    to: { name: 'company-settings' },
    icon: { icon: 'tabler-building' },
  },
]

export default [
  {
    title: 'Dashboard',
    to: { name: 'root' },
    icon: { icon: 'tabler-smart-home' },
  },
  { heading: 'Company' },
  ...coreNavItems,
  {
    title: 'Modules',
    to: { name: 'company-modules' },
    icon: { icon: 'tabler-puzzle' },
  },
  {
    title: 'Industry',
    to: { name: 'company-jobdomain' },
    icon: { icon: 'tabler-briefcase' },
  },
  { heading: 'Account' },
  {
    title: 'Account Settings',
    to: { name: 'account-settings-tab', params: { tab: 'account' } },
    icon: { icon: 'tabler-settings' },
  },
]
