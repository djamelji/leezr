<script setup>
/**
 * Members Tab — VDataTableServer for thousands of users
 * ADR-446: Moved from supervision/ to companies/
 * Pattern: resources/ui/presets/apps/roles/UserList.vue
 * ADR-381
 */
import { usePlatformUsersStore } from '@/modules/platform-admin/users/users.store'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const usersStore = usePlatformUsersStore()
const isLoading = ref(true)

// Preset pattern: filters + pagination state
const searchQuery = ref('')
const itemsPerPage = ref(10)
const page = ref(1)
const sortBy = ref()
const orderBy = ref()
const selectedRows = ref([])
const searchTimeout = ref(null)

const updateOptions = options => {
  sortBy.value = options.sortBy[0]?.key
  orderBy.value = options.sortBy[0]?.order
}

const fetchData = async () => {
  isLoading.value = true
  try {
    await usersStore.fetchCompanyUsers({
      page: page.value,
      per_page: itemsPerPage.value > 0 ? itemsPerPage.value : 100,
      ...(searchQuery.value ? { search: searchQuery.value } : {}),
    })
  }
  finally {
    isLoading.value = false
  }
}

// Debounced search
watch(searchQuery, () => {
  clearTimeout(searchTimeout.value)
  searchTimeout.value = setTimeout(() => {
    page.value = 1
    fetchData()
  }, 400)
})

watch([page, itemsPerPage], () => fetchData())

onMounted(() => fetchData())

// Preset pattern: headers
const headers = computed(() => [
  { title: t('platformCompanyUsers.user'), key: 'display_name' },
  { title: t('common.email'), key: 'email' },
  { title: t('platformCompanyUsers.companies'), key: 'companies', sortable: false },
  { title: t('common.created'), key: 'created_at', width: '140px' },
])

const totalUsers = computed(() => usersStore.companyUsersPagination.total || 0)

const fmtDate = dateStr => {
  if (!dateStr)
    return '\u2014'

  return formatDate(dateStr)
}
</script>

<template>
  <section>
    <VCard>
      <!-- Preset pattern: filter bar -->
      <VCardText class="d-flex flex-wrap gap-4">
        <div class="d-flex gap-2 align-center">
          <p class="text-body-1 mb-0">
            {{ t('common.show') }}
          </p>
          <AppSelect
            :model-value="itemsPerPage"
            :items="[
              { value: 10, title: '10' },
              { value: 25, title: '25' },
              { value: 50, title: '50' },
              { value: 100, title: '100' },
              { value: -1, title: t('common.all') },
            ]"
            style="inline-size: 5.5rem;"
            @update:model-value="itemsPerPage = parseInt($event, 10)"
          />
        </div>

        <VSpacer />

        <div class="d-flex align-center flex-wrap gap-4">
          <!-- Search -->
          <AppTextField
            v-model="searchQuery"
            :placeholder="t('platformCompanyUsers.searchPlaceholder')"
            style="inline-size: 15.625rem;"
          />
        </div>
      </VCardText>

      <VDivider />

      <!-- Preset pattern: VDataTableServer -->
      <VDataTableServer
        v-model:items-per-page="itemsPerPage"
        v-model:model-value="selectedRows"
        v-model:page="page"
        :items-per-page-options="[
          { value: 10, title: '10' },
          { value: 20, title: '20' },
          { value: 50, title: '50' },
          { value: -1, title: '$vuetify.dataFooter.itemsPerPageAll' },
        ]"
        :items="usersStore.companyUsers"
        :items-length="totalUsers"
        :headers="headers"
        :loading="isLoading"
        class="text-no-wrap"
        show-select
        @update:options="updateOptions"
      >
        <!-- Preset pattern: User column (avatar + name) -->
        <template #item.display_name="{ item }">
          <div class="d-flex align-center gap-x-4">
            <VAvatar
              size="34"
              variant="tonal"
              color="primary"
            >
              <span>{{ avatarText(item.display_name) }}</span>
            </VAvatar>
            <div class="d-flex flex-column">
              <h6 class="text-base font-weight-medium text-high-emphasis">
                {{ item.display_name }}
              </h6>
            </div>
          </div>
        </template>

        <!-- Companies column (chips) -->
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
            &mdash;
          </span>
        </template>

        <!-- Created at -->
        <template #item.created_at="{ item }">
          {{ fmtDate(item.created_at) }}
        </template>

        <!-- Preset pattern: TablePagination in #bottom -->
        <template #bottom>
          <TablePagination
            v-model:page="page"
            :items-per-page="itemsPerPage"
            :total-items="totalUsers"
          />
        </template>
      </VDataTableServer>
    </VCard>
  </section>
</template>
