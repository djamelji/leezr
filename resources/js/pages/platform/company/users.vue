<script setup>
import { usePlatformStore } from '@/core/stores/platform'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'view_company_users',
  },
})

const platformStore = usePlatformStore()
const isLoading = ref(true)

onMounted(async () => {
  try {
    await platformStore.fetchCompanyUsers()
  }
  finally {
    isLoading.value = false
  }
})

const headers = [
  { title: 'Name', key: 'display_name' },
  { title: 'Email', key: 'email' },
  { title: 'Companies', key: 'companies', sortable: false },
  { title: 'Created', key: 'created_at' },
]

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  })
}

const onPageChange = async page => {
  isLoading.value = true

  try {
    await platformStore.fetchCompanyUsers(page)
  }
  finally {
    isLoading.value = false
  }
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-users-group"
          class="me-2"
        />
        Company Users
      </VCardTitle>
      <VCardSubtitle>
        Read-only supervision of company users across all tenants.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="platformStore.companyUsers"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Name -->
        <template #item.display_name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              color="primary"
              variant="tonal"
              size="34"
            >
              <span class="text-sm">{{ item.display_name?.charAt(0)?.toUpperCase() }}</span>
            </VAvatar>
            <span class="text-body-1 font-weight-medium text-high-emphasis">
              {{ item.display_name }}
            </span>
          </div>
        </template>

        <!-- Companies -->
        <template #item.companies="{ item }">
          <VChip
            v-for="company in item.companies"
            :key="company.id"
            size="small"
            color="primary"
            variant="tonal"
            class="me-1"
          >
            {{ company.name }}
          </VChip>
          <span
            v-if="!item.companies?.length"
            class="text-disabled"
          >
            —
          </span>
        </template>

        <!-- Created -->
        <template #item.created_at="{ item }">
          {{ formatDate(item.created_at) }}
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            No company users found.
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VCardText
        v-if="platformStore.companyUsersPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="platformStore.companyUsersPagination.current_page"
          :length="platformStore.companyUsersPagination.last_page"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>
  </div>
</template>
