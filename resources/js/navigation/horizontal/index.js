/**
 * Static horizontal nav items.
 * Module-driven items injected dynamically by the layout.
 */
export default [
  {
    title: 'Dashboard',
    to: { name: 'root' },
    icon: { icon: 'tabler-smart-home' },
  },
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
