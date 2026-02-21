<script setup>
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_roles',
  },
})

const rolesStore = usePlatformRolesStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingRole = ref(null)
const drawerForm = ref({ key: '', name: '', permissions: [] })
const drawerLoading = ref(false)

const isSuperAdminRole = computed(() => editingRole.value?.key === 'super_admin')

// Permissions options for VSelect (from catalog)
const permissionOptions = computed(() =>
  rolesStore.permissionCatalog.map(p => ({ title: p.label, value: p.id })),
)

onMounted(async () => {
  try {
    await Promise.all([
      rolesStore.fetchRoles(),
      rolesStore.fetchPermissionCatalog(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const headers = [
  { title: 'Key', key: 'key' },
  { title: 'Name', key: 'name' },
  { title: 'Permissions', key: 'permissions', sortable: false },
  { title: 'Users', key: 'users_count', align: 'center' },
  { title: 'Actions', key: 'actions', align: 'center', width: '150px', sortable: false },
]

const openCreateDrawer = () => {
  isEditMode.value = false
  editingRole.value = null
  drawerForm.value = { key: '', name: '', permissions: [] }
  isDrawerOpen.value = true
}

const openEditDrawer = role => {
  isEditMode.value = true
  editingRole.value = role
  drawerForm.value = {
    key: role.key,
    name: role.name,
    permissions: role.permissions?.map(p => p.id) || [],
  }
  isDrawerOpen.value = true
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    if (isEditMode.value) {
      const payload = { name: drawerForm.value.name }

      // Never send permissions for super_admin
      if (!isSuperAdminRole.value)
        payload.permissions = drawerForm.value.permissions

      const data = await rolesStore.updateRole(editingRole.value.id, payload)

      toast(data.message, 'success')
    }
    else {
      const data = await rolesStore.createRole({
        key: drawerForm.value.key,
        name: drawerForm.value.name,
        permissions: drawerForm.value.permissions,
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

const deleteRole = async role => {
  if (!confirm(`Delete role "${role.name}"?`))
    return

  actionLoading.value = role.id

  try {
    const data = await rolesStore.deleteRole(role.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete role.', 'error')
  }
  finally {
    actionLoading.value = null
  }
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-shield-lock"
          class="me-2"
        />
        Platform Roles
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="openCreateDrawer"
        >
          Add Role
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        Manage platform roles and their permissions.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="rolesStore.roles"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Key -->
        <template #item.key="{ item }">
          <VChip
            size="small"
            :color="item.key === 'super_admin' ? 'error' : 'secondary'"
            variant="tonal"
          >
            {{ item.key }}
          </VChip>
        </template>

        <!-- Permissions -->
        <template #item.permissions="{ item }">
          <VChip
            v-if="item.key === 'super_admin'"
            size="small"
            color="error"
            variant="tonal"
          >
            All permissions (structural)
          </VChip>
          <template v-else>
            <VChip
              v-for="perm in item.permissions"
              :key="perm.key"
              size="small"
              color="info"
              variant="tonal"
              class="me-1 mb-1"
            >
              {{ perm.label }}
            </VChip>
            <span
              v-if="!item.permissions?.length"
              class="text-disabled"
            >
              —
            </span>
          </template>
        </template>

        <!-- Users count -->
        <template #item.users_count="{ item }">
          <VChip
            size="small"
            :color="item.users_count > 0 ? 'primary' : 'default'"
            variant="tonal"
          >
            {{ item.users_count }}
          </VChip>
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
              v-if="item.key !== 'super_admin'"
              icon
              variant="text"
              size="small"
              color="error"
              :loading="actionLoading === item.id"
              @click="deleteRole(item)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            No roles found.
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <AppDrawerHeaderSection
        :title="isEditMode ? 'Edit Role' : 'Add Role'"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <VCardText>
        <VForm @submit.prevent="handleDrawerSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.key"
                label="Key"
                placeholder="e.g. support"
                :disabled="isEditMode"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="drawerForm.name"
                label="Name"
                placeholder="e.g. Support Agent"
              />
            </VCol>
            <VCol
              v-if="!isSuperAdminRole"
              cols="12"
            >
              <AppSelect
                v-model="drawerForm.permissions"
                :items="permissionOptions"
                label="Permissions"
                placeholder="Select permissions"
                multiple
                chips
                closable-chips
              />
            </VCol>
            <VCol
              v-else
              cols="12"
            >
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                All permissions (structural) — cannot be modified.
              </VAlert>
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
