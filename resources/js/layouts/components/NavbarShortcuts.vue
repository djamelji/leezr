<script setup>
const shortcuts = [
  {
    icon: 'tabler-dashboard',
    title: 'Dashboard',
    subtitle: 'Platform overview',
    to: { name: 'platform' },
  },
  {
    icon: 'tabler-user-shield',
    title: 'Users',
    subtitle: 'Platform Users',
    to: { name: 'platform-users' },
  },
  {
    icon: 'tabler-building',
    title: 'Companies',
    subtitle: 'Manage Companies',
    to: { name: 'platform-companies' },
  },
  {
    icon: 'tabler-briefcase',
    title: 'Job Domains',
    subtitle: 'Domain Management',
    to: { name: 'platform-jobdomains' },
  },
  {
    icon: 'tabler-forms',
    title: 'Custom Fields',
    subtitle: 'Field Definitions',
    to: { name: 'platform-fields' },
  },
  {
    icon: 'tabler-shield-lock',
    title: 'Roles',
    subtitle: 'Role Management',
    to: { name: 'platform-roles' },
  },
]
</script>

<template>
  <Shortcuts :shortcuts="shortcuts" />
</template>
