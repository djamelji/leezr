<script setup>
import { usePlatformUsersStore } from '@/modules/platform-admin/users/users.store'
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_platform_users',
  },
})

const router = useRouter()
const usersStore = usePlatformUsersStore()
const rolesStore = usePlatformRolesStore()
const platformAuthStore = usePlatformAuthStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state (create only)
const isDrawerOpen = ref(false)
const drawerForm = ref({ first_name: '', last_name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' })
const drawerLoading = ref(false)

// Permission check
const canManageCredentials = computed(() =>
  platformAuthStore.hasPermission('manage_platform_user_credentials'),
)

// Roles options for VSelect
const roleOptions = computed(() =>
  rolesStore.roles.map(r => ({ title: r.name, value: r.id })),
)

onMounted(async () => {
  try {
    await Promise.all([
      usersStore.fetchPlatformUsers(),
      rolesStore.fetchRoles(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const headers = [
  { title: 'Name', key: 'display_name' },
  { title: 'Email', key: 'email' },
  { title: 'Status', key: 'status', width: '140px' },
  { title: 'Roles', key: 'roles', sortable: false },
  { title: 'Actions', key: 'actions', align: 'center', width: '120px', sortable: false },
]

const hasRole = (user, key) => {
  return user.roles?.some(r => r.key === key)
}

const openCreateDrawer = () => {
  drawerForm.value = { first_name: '', last_name: '', email: '', roles: [], credentialMode: 'invite', password: '', password_confirmation: '' }
  isDrawerOpen.value = true
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    const payload = {
      first_name: drawerForm.value.first_name,
      last_name: drawerForm.value.last_name,
      email: drawerForm.value.email,
      roles: drawerForm.value.roles,
    }

    if (canManageCredentials.value && drawerForm.value.credentialMode === 'password') {
      payload.invite = false
      payload.password = drawerForm.value.password
      payload.password_confirmation = drawerForm.value.password_confirmation
    }

    const data = await usersStore.createPlatformUser(payload)

    toast(data.message, 'success')
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
  if (!confirm(`Delete platform user "${user.display_name}"?`))
    return

  actionLoading.value = user.id

  try {
    const data = await usersStore.deletePlatformUser(user.id)

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
    await usersStore.fetchPlatformUsers(page)
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
        :items="usersStore.platformUsers"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Name -->
        <template #item.display_name="{ item }">
          <div class="d-flex align-center gap-x-3 py-2">
            <VAvatar
              :color="hasRole(item, 'super_admin') ? 'error' : 'secondary'"
              variant="tonal"
              size="34"
            >
              <span class="text-sm">{{ item.first_name?.charAt(0)?.toUpperCase() }}</span>
            </VAvatar>
            <RouterLink
              :to="`/platform/users/${item.id}`"
              class="text-body-1 font-weight-medium text-high-emphasis text-link"
            >
              {{ item.display_name }}
            </RouterLink>
          </div>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <VChip
            :color="item.status === 'invited' ? 'warning' : 'success'"
            size="small"
          >
            {{ item.status === 'invited' ? 'Invitation pending' : 'Active' }}
          </VChip>
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
              @click="router.push(`/platform/users/${item.id}`)"
            >
              <VIcon icon="tabler-pencil" />
            </VBtn>
            <VBtn
              v-if="!hasRole(item, 'super_admin')"
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
        v-if="usersStore.platformUsersPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="usersStore.platformUsersPagination.current_page"
          :length="usersStore.platformUsersPagination.last_page"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>

    <!-- Create Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <AppDrawerHeaderSection
        title="Add Platform User"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <VCardText>
        <VForm @submit.prevent="handleDrawerSubmit">
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="drawerForm.first_name"
                label="First Name"
                placeholder="John"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="drawerForm.last_name"
                label="Last Name"
                placeholder="Doe"
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

            <!-- Credential mode (if permission) -->
            <template v-if="canManageCredentials">
              <VCol cols="12">
                <VDivider class="mb-2" />
                <div class="text-body-2 font-weight-medium mb-2">
                  Credentials
                </div>
                <VRadioGroup
                  v-model="drawerForm.credentialMode"
                  inline
                >
                  <VRadio
                    label="Send invitation link"
                    value="invite"
                  />
                  <VRadio
                    label="Set password now"
                    value="password"
                  />
                </VRadioGroup>
              </VCol>

              <template v-if="drawerForm.credentialMode === 'password'">
                <VCol cols="12">
                  <AppTextField
                    v-model="drawerForm.password"
                    label="Password"
                    type="password"
                    placeholder="Min 8 characters"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextField
                    v-model="drawerForm.password_confirmation"
                    label="Confirm Password"
                    type="password"
                    placeholder="Repeat password"
                  />
                </VCol>
              </template>
            </template>

            <!-- Invitation info (no permission) -->
            <VCol
              v-if="!canManageCredentials"
              cols="12"
            >
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                An invitation email will be sent to set their password.
              </VAlert>
            </VCol>

            <VCol cols="12">
              <VBtn
                type="submit"
                class="me-3"
                :loading="drawerLoading"
              >
                Create
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
