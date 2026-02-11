/**
 * Static navigation items (always visible).
 * Module-driven items (Members, Settings, Shipments...) are injected
 * dynamically from useModuleStore().activeNavItems in the layout.
 */
export default [
  {
    title: 'Dashboard',
    to: { name: 'root' },
    icon: { icon: 'tabler-smart-home' },
  },
  { heading: 'Company' },
  // Module nav items are injected here dynamically by the layout
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
