/**
 * Static horizontal nav items.
 * Core items serve as fallback. Module items replace/augment dynamically.
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
    to: { name: 'dashboard' },
    icon: { icon: 'tabler-smart-home' },
  },
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
  {
    title: 'Account Settings',
    to: { name: 'account-settings-tab', params: { tab: 'account' } },
    icon: { icon: 'tabler-settings' },
  },
]
