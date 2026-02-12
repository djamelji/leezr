<script setup>
import { usePlatformStore } from '@/core/stores/platform'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_platform_users',
  },
})

const platformStore = usePlatformStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingUser = ref(null)
const drawerForm = ref({ name: '', email: '', password: '', roles: [] })
const drawerLoading = ref(false)

// Roles options for VSelect
const roleOptions = computed(() =>
  platformStore.roles.map(r => ({ title: r.name, value: r.id })),
)

onMounted(async () => {
  try {
    await Promise.all([
      platformStore.fetchPlatformUsers(),
      platformStore.fetchRoles(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Email', key: 'email' },
  { title: 'Roles', key: 'roles', sortable: false },
  { title: 'Actions', key: 'actions', align: 'center', width: '120px', sortable: false },
]

const hasRole = (user, key) => {
  return user.roles?.some(r => r.key === key)
}

const openCreateDrawer = () => {
  isEditMode.value = false
  editingUser.value = null
  drawerForm.value = { name: '', email: '', password: '', roles: [] }
  isDrawerOpen.value = true
}

const openEditDrawer = user => {
  isEditMode.value = true
  editingUser.value = user
  drawerForm.value = {
    name: user.name,
    email: user.email,
    password: '',
    roles: user.roles?.map(r => r.id) || [],
  }
  isDrawerOpen.value = true
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    if (isEditMode.value) {
      const payload = {
        name: drawerForm.value.name,
        email: drawerForm.value.email,
        roles: drawerForm.value.roles,
      }

      if (drawerForm.value.password)
        payload.password = drawerForm.value.password

      const data = await platformStore.updatePlatformUser(editingUser.value.id, payload)

      toast(data.message, 'success')
    }
    else {
      const data = await platformStore.createPlatformUser({
        name: drawerForm.value.name,
        email: drawerForm.value.email,
        password: drawerForm.value.password,
        roles: drawerForm.value.roles,
      })

      toast(data.message, 'success')
    }
    isDrawerOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || 'Operation failed.', 'error')
  }
  finally {
    drawerLoading.value = false
  }
}

const deleteUser = async user => {
  if (!confirm(`Delete platform user "${user.name}"?`))
    return

  actionLoading.value = user.id

  try {
    const data = await platformStore.deletePlatformUser(user.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete user.', 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const onPageChange = async page => {
  isLoading.value = true

  try {
    await platformStore.fetchPlatformUsers(page)
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
          icon="tabler-user-shield"
          class="me-2"
        />
        Platform Users
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="openCreateDrawer"
        >
          Add User
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        Manage platform employees and their roles.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="platformStore.platformUsers"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Name -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              :color="hasRole(item, 'super_admin') ? 'error' : 'secondary'"
              variant="tonal"
              size="34"
            >
              <span class="text-sm">{{ item.name?.charAt(0)?.toUpperCase() }}</span>
            </VAvatar>
            <span class="text-body-1 font-weight-medium text-high-emphasis">
              {{ item.name }}
            </span>
          </div>
        </template>

        <!-- Roles -->
        <template #item.roles="{ item }">
          <VChip
            v-for="role in item.roles"
            :key="role.key"
            color="error"
            size="small"
            class="me-1"
          >
            {{ role.name }}
          </VChip>
          <span
            v-if="!item.roles?.length"
            class="text-disabled"
          >
            —
          </span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <VBtn
              icon
              variant="text"
              size="small"
              color="default"
              @click="openEditDrawer(item)"
            >
              <VIcon icon="tabler-pencil" />
            </VBtn>
            <VBtn
              icon
              variant="text"
              size="small"
              color="error"
              :loading="actionLoading === item.id"
              @click="deleteUser(item)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            No platform users found.
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VCardText
        v-if="platformStore.platformUsersPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="platformStore.platformUsersPagination.current_page"
          :length="platformStore.platformUsersPagination.last_page"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>

    <!-- Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <AppDrawerHeaderSection
        :title="isEditMode ? 'Edit Platform User' : 'Add Platform User'"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <VCardText>
        <VForm @submit.prevent="handleDrawerSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.name"
                label="Name"
                placeholder="John Doe"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.email"
                label="Email"
                type="email"
                placeholder="john@leezr.com"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.password"
                label="Password"
                type="password"
                :placeholder="isEditMode ? 'Leave blank to keep current' : 'Min 8 characters'"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="drawerForm.roles"
                :items="roleOptions"
                label="Roles"
                placeholder="Select roles"
                multiple
                chips
                closable-chips
              />
            </VCol>
            <VCol cols="12">
              <VBtn
                type="submit"
                class="me-3"
                :loading="drawerLoading"
              >
                {{ isEditMode ? 'Update' : 'Create' }}
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                @click="isDrawerOpen = false"
              >
                Cancel
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VNavigationDrawer>
  </div>
</template>
