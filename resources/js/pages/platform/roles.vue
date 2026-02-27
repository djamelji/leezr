<script setup>
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { useAppToast } from '@/composables/useAppToast'
import PermissionMatrix from '@/pages/shared/_PermissionMatrix.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.roles',
    permission: 'manage_roles',
  },
})

const { t } = useI18n()
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

const headers = computed(() => [
  { title: t('platformRoles.key'), key: 'key' },
  { title: t('common.name'), key: 'name' },
  { title: t('platformRoles.permissions'), key: 'permissions', sortable: false },
  { title: t('platformRoles.users'), key: 'users_count', align: 'center' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '150px', sortable: false },
])

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
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
  finally {
    drawerLoading.value = false
  }
}

const deleteRole = async role => {
  if (!confirm(t('platformRoles.confirmDeleteRole', { name: role.name })))
    return

  actionLoading.value = role.id

  try {
    const data = await rolesStore.deleteRole(role.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformRoles.failedToDeleteRole'), 'error')
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
        {{ t('platformRoles.title') }}
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="openCreateDrawer"
        >
          {{ t('platformRoles.addRole') }}
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        {{ t('platformRoles.subtitle') }}
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
            {{ t('platformRoles.allPermissionsStructural') }}
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
            {{ t('platformRoles.noRoles') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create/Edit Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="500"
    >
      <AppDrawerHeaderSection
        :title="isEditMode ? t('platformRoles.editRole') : t('platformRoles.addRole')"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
        <VCardText>
          <VForm @submit.prevent="handleDrawerSubmit">
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="drawerForm.key"
                  :label="t('platformRoles.key')"
                  :placeholder="t('platformRoles.keyPlaceholder')"
                  :disabled="isEditMode"
                />
              </VCol>
              <VCol cols="12">
                <AppTextField
                  v-model="drawerForm.name"
                  :label="t('common.name')"
                  :placeholder="t('platformRoles.namePlaceholder')"
                />
              </VCol>

              <!-- Permission matrix or super_admin info -->
              <VCol
                v-if="isSuperAdminRole"
                cols="12"
              >
                <VAlert
                  type="info"
                  variant="tonal"
                  density="compact"
                >
                  {{ t('platformRoles.allPermissionsInfo') }}
                </VAlert>
              </VCol>

              <template v-else>
                <VCol cols="12">
                  <VDivider />
                </VCol>
                <VCol cols="12">
                  <PermissionMatrix
                    v-model:selected-permissions="drawerForm.permissions"
                    :permission-catalog="rolesStore.permissionCatalog"
                    :permission-modules="rolesStore.permissionModules"
                    :is-administrative="true"
                    scope="platform"
                  />
                </VCol>
              </template>

              <VCol cols="12">
                <VBtn
                  type="submit"
                  class="me-3"
                  :loading="drawerLoading"
                >
                  {{ isEditMode ? t('common.update') : t('common.create') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="isDrawerOpen = false"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </div>
    </VNavigationDrawer>
  </div>
</template>
