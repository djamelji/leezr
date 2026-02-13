export default [
  {
    title: 'Dashboard',
    to: { name: 'platform' },
    icon: { icon: 'tabler-dashboard' },
  },
  { heading: 'Management' },
  {
    title: 'Companies',
    to: { name: 'platform-companies' },
    icon: { icon: 'tabler-building' },
    permission: 'manage_companies',
  },
  {
    title: 'Company Users',
    to: { name: 'platform-company-users' },
    icon: { icon: 'tabler-users-group' },
    permission: 'view_company_users',
  },
  {
    title: 'Platform Users',
    to: { name: 'platform-users' },
    icon: { icon: 'tabler-user-shield' },
    permission: 'manage_platform_users',
  },
  {
    title: 'Roles',
    to: { name: 'platform-roles' },
    icon: { icon: 'tabler-shield-lock' },
    permission: 'manage_roles',
  },
  {
    title: 'Modules',
    to: { name: 'platform-modules' },
    icon: { icon: 'tabler-puzzle' },
    permission: 'manage_modules',
  },
  {
    title: 'Job Domains',
    to: { name: 'platform-jobdomains' },
    icon: { icon: 'tabler-briefcase' },
    permission: 'manage_jobdomains',
  },
  {
    title: 'Custom Fields',
    to: { name: 'platform-fields' },
    icon: { icon: 'tabler-forms' },
    permission: 'manage_field_definitions',
  },
]
