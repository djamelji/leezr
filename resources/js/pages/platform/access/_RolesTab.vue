<script setup>
/**
 * Platform Roles Tab — Card grid layout
 * Pattern: resources/ui/presets/apps/roles/RoleCards.vue
 * Extracted from platform/roles.vue — ADR-380
 */
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import PermissionMatrix from '@/pages/shared/_PermissionMatrix.vue'
import PermissionDetail from './_PermissionDetail.vue'
import girlUsingMobile from '@images/pages/girl-using-mobile.png'

const { t } = useI18n()
const rolesStore = usePlatformRolesStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const isLoading = ref(true)
const actionLoading = ref(null)

// View drawer
const isViewDrawerOpen = ref(false)
const viewingRole = ref(null)

// Create/Edit drawer
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

const accessLevelColor = level => ({
  full_access: 'error',
  administration: 'warning',
  management: 'info',
  standard: 'success',
  limited: 'secondary',
  custom: 'default',
})[level] || 'default'

const openViewDrawer = role => {
  viewingRole.value = role
  isViewDrawerOpen.value = true
}

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
  if (role.is_system || role.users_count > 0) return

  const ok = await confirm({
    question: t('platformRoles.confirmDeleteRole', { name: role.name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('common.deleteSuccess'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
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
    <!-- Loading state -->
    <VRow v-if="isLoading">
      <VCol
        v-for="i in 4"
        :key="i"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard>
          <VCardText>
            <VSkeletonLoader type="text, text, actions" />
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Role Cards Grid — Pattern: RoleCards.vue preset -->
    <VRow
      v-else
      class="card-grid card-grid-sm"
    >
      <VCol
        v-for="role in rolesStore.roles"
        :key="role.id"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard
          class="cursor-pointer"
          @click="openViewDrawer(role)"
        >
          <!-- Top: user count + avatar group (preset pattern) -->
          <VCardText class="d-flex align-center pb-4">
            <div class="text-body-1">
              {{ t('platformRoles.totalUsers', { count: role.users_count }) }}
            </div>

            <VSpacer />

            <div class="v-avatar-group">
              <template
                v-for="(sample, idx) in role.users_sample"
                :key="idx"
              >
                <VAvatar
                  v-if="role.users_sample.length <= 4 || idx < 3"
                  size="36"
                  color="primary"
                  variant="tonal"
                >
                  <span class="text-xs">{{ sample.initials }}</span>
                  <VTooltip
                    activator="parent"
                    location="top"
                  >
                    {{ sample.name }}
                  </VTooltip>
                </VAvatar>
              </template>
              <VAvatar
                v-if="role.users_count > 4"
                size="36"
                :color="$vuetify.theme.current.dark ? '#373B50' : '#EEEDF0'"
              >
                <span class="text-xs">+{{ role.users_count - 3 }}</span>
              </VAvatar>
            </div>
          </VCardText>

          <!-- Bottom: role name, permissions count, actions -->
          <VCardText>
            <div class="d-flex justify-space-between align-center">
              <div>
                <h5 class="text-h5">
                  {{ role.name }}
                </h5>
                <div class="d-flex align-center gap-2 mt-1">
                  <VChip
                    :color="accessLevelColor(role.access_level)"
                    size="x-small"
                    variant="tonal"
                  >
                    {{ t(`platformRoles.accessLevels.${role.access_level}`) }}
                  </VChip>
                  <VChip
                    v-if="role.is_system"
                    size="x-small"
                    color="warning"
                    variant="tonal"
                    class="text-capitalize"
                  >
                    {{ t('common.system') }}
                  </VChip>
                </div>
                <span class="text-caption text-disabled d-block mt-1">
                  {{ t('platformRoles.permissionsCount', { count: role.permissions_count }) }}
                </span>
                <div class="d-flex align-center gap-3 mt-2">
                  <a
                    href="javascript:void(0)"
                    class="text-primary text-body-2 font-weight-medium"
                    @click.stop="openEditDrawer(role)"
                  >
                    {{ t('platformRoles.editRole') }}
                  </a>
                  <a
                    v-if="!role.is_system && role.users_count === 0"
                    href="javascript:void(0)"
                    class="text-error text-body-2"
                    @click.stop="deleteRole(role)"
                  >
                    {{ t('common.delete') }}
                  </a>
                </div>
              </div>

              <IconBtn @click.stop="openViewDrawer(role)">
                <VIcon
                  icon="tabler-eye"
                  class="text-high-emphasis"
                />
              </IconBtn>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Add New Role Card — Pattern: RoleCards.vue preset -->
      <VCol
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard :ripple="false">
          <VRow
            no-gutters
            class="h-100"
          >
            <VCol
              cols="5"
              class="d-flex flex-column justify-end align-center mt-5"
            >
              <img
                width="85"
                :src="girlUsingMobile"
              >
            </VCol>

            <VCol cols="7">
              <VCardText class="d-flex flex-column align-end justify-end gap-4">
                <VBtn
                  size="small"
                  prepend-icon="tabler-plus"
                  @click="openCreateDrawer"
                >
                  {{ t('platformRoles.addRole') }}
                </VBtn>
                <div class="text-end">
                  {{ t('platformRoles.addRoleDesc') }}
                </div>
              </VCardText>
            </VCol>
          </VRow>
        </VCard>
      </VCol>
    </VRow>

    <!-- View Permission Detail Drawer -->
    <Teleport to="body">
      <VNavigationDrawer
        v-model="isViewDrawerOpen"
        temporary
        location="end"
        width="450"
      >
        <AppDrawerHeaderSection
          :title="viewingRole?.name || t('platformRoles.viewRole')"
          @cancel="isViewDrawerOpen = false"
        />
        <VDivider />
        <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
          <VCardText v-if="viewingRole">
            <PermissionDetail :role="viewingRole" />
          </VCardText>
        </div>
      </VNavigationDrawer>
    </Teleport>

    <!-- Create/Edit Drawer -->
    <Teleport to="body">
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
    </Teleport>

    <ConfirmDialogComponent />
  </div>
</template>
