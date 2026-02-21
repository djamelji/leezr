<script setup>
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { usePlatformUsersStore } from '@/modules/platform-admin/users/users.store'
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
  },
})

const companiesStore = usePlatformCompaniesStore()
const usersStore = usePlatformUsersStore()
const rolesStore = usePlatformRolesStore()
const settingsStore = usePlatformSettingsStore()
const isLoading = ref(true)

const stats = ref({
  companies: 0,
  platformUsers: 0,
  companyUsers: 0,
  roles: 0,
  modules: 0,
})

onMounted(async () => {
  try {
    await Promise.all([
      companiesStore.fetchCompanies(),
      usersStore.fetchPlatformUsers(),
      usersStore.fetchCompanyUsers(),
      rolesStore.fetchRoles(),
      settingsStore.fetchModules(),
    ])

    stats.value = {
      companies: companiesStore.companiesPagination.total,
      platformUsers: usersStore.platformUsersPagination.total,
      companyUsers: usersStore.companyUsersPagination.total,
      roles: rolesStore.roles.length,
      modules: settingsStore.modules.length,
    }
  }
  finally {
    isLoading.value = false
  }
})

const cards = computed(() => [
  {
    title: 'Companies',
    value: stats.value.companies,
    icon: 'tabler-building',
    color: 'primary',
    to: { name: 'platform-companies' },
  },
  {
    title: 'Platform Users',
    value: stats.value.platformUsers,
    icon: 'tabler-user-shield',
    color: 'error',
    to: { name: 'platform-users' },
  },
  {
    title: 'Company Users',
    value: stats.value.companyUsers,
    icon: 'tabler-users-group',
    color: 'info',
    to: { name: 'platform-company-users' },
  },
  {
    title: 'Roles',
    value: stats.value.roles,
    icon: 'tabler-shield-lock',
    color: 'success',
    to: { name: 'platform-roles' },
  },
  {
    title: 'Modules',
    value: stats.value.modules,
    icon: 'tabler-puzzle',
    color: 'warning',
    to: { name: 'platform-modules' },
  },
])
</script>

<template>
  <div>
    <h4 class="text-h4 mb-6">
      Platform Dashboard
    </h4>

    <VRow>
      <VCol
        v-for="card in cards"
        :key="card.title"
        cols="12"
        sm="6"
        md="4"
      >
        <VCard :loading="isLoading">
          <VCardText class="d-flex align-center gap-x-4">
            <VAvatar
              :color="card.color"
              variant="tonal"
              size="44"
              rounded
            >
              <VIcon
                :icon="card.icon"
                size="28"
              />
            </VAvatar>
            <div>
              <p class="text-body-1 mb-0 text-high-emphasis font-weight-medium">
                {{ card.title }}
              </p>
              <h4 class="text-h4">
                {{ isLoading ? '—' : card.value }}
              </h4>
            </div>
            <VSpacer />
            <VBtn
              :to="card.to"
              variant="tonal"
              :color="card.color"
              size="small"
            >
              View
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
