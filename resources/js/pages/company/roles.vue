<script setup>
definePage({ meta: { surface: 'structure', module: 'core.roles' } })

import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import PermissionMatrix from '@/pages/shared/_PermissionMatrix.vue'

const { t } = useI18n()
const auth = useAuthStore()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingRole = ref(null)
const drawerForm = ref({ name: '', is_administrative: false, permissions: [] })
const drawerLoading = ref(false)

onMounted(async () => {
  try {
    await Promise.all([
      settingsStore.fetchCompanyRoles(),
      settingsStore.fetchPermissionCatalog(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

// Strip sensitive permissions when switching to Operational
watch(() => drawerForm.value.is_administrative, newVal => {
  if (!newVal) {
    const adminIds = new Set(
      settingsStore.permissionCatalog.filter(p => p.is_admin).map(p => p.id),
    )

    drawerForm.value.permissions = drawerForm.value.permissions.filter(id => !adminIds.has(id))
  }
})

// ─── Table ──────────────────────────────────────────
const headers = computed(() => [
  { title: t('common.name'), key: 'name' },
  { title: t('common.level'), key: 'level', width: '140px', sortable: false },
  { title: t('Members'), key: 'memberships_count', align: 'center', width: '100px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '180px', sortable: false },
])

// ─── Drawer actions ─────────────────────────────────
const openCreateDrawer = () => {
  isEditMode.value = false
  editingRole.value = null
  drawerForm.value = { name: '', is_administrative: false, permissions: [] }
  isDrawerOpen.value = true
}

const openEditDrawer = role => {
  isEditMode.value = true
  editingRole.value = role
  drawerForm.value = {
    name: role.name,
    is_administrative: role.is_administrative,
    permissions: role.permissions?.map(p => p.id) || [],
  }
  isDrawerOpen.value = true
}

const cloneRole = role => {
  isEditMode.value = false
  editingRole.value = null
  drawerForm.value = {
    name: `${role.name} Copy`,
    is_administrative: role.is_administrative,
    permissions: role.permissions?.map(p => p.id) || [],
  }
  isDrawerOpen.value = true
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    if (isEditMode.value) {
      const data = await settingsStore.updateCompanyRole(editingRole.value.id, {
        name: drawerForm.value.name,
        is_administrative: drawerForm.value.is_administrative,
        permissions: drawerForm.value.permissions,
      })

      toast(data.message, 'success')
    }
    else {
      const data = await settingsStore.createCompanyRole({
        name: drawerForm.value.name,
        is_administrative: drawerForm.value.is_administrative,
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
  if (!confirm(t('roles.confirmDelete', { name: role.name })))
    return

  actionLoading.value = role.id

  try {
    const data = await settingsStore.deleteCompanyRole(role.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('roles.failedToDelete'), 'error')
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
        {{ t('roles.title') }}
        <VSpacer />
        <VBtn
          size="small"
          prepend-icon="tabler-plus"
          @click="openCreateDrawer"
        >
          {{ t('roles.addRole') }}
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        {{ t('roles.subtitle') }}
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="settingsStore.roles"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
      >
        <!-- Name -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-2">
            <span class="text-body-1 font-weight-medium">{{ item.name }}</span>
            <VChip
              v-if="item.is_system"
              size="x-small"
              color="warning"
              variant="tonal"
            >
              {{ t('common.system') }}
            </VChip>
          </div>
        </template>

        <!-- Level badge -->
        <template #item.level="{ item }">
          <VChip
            :color="item.is_administrative ? 'warning' : 'info'"
            size="small"
            variant="tonal"
          >
            {{ item.is_administrative ? t('roles.management') : t('roles.operational') }}
          </VChip>
        </template>

        <!-- Members count -->
        <template #item.memberships_count="{ item }">
          <VChip
            size="small"
            :color="item.memberships_count > 0 ? 'primary' : 'default'"
            variant="tonal"
          >
            {{ item.memberships_count }}
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
              <VTooltip
                activator="parent"
                location="top"
              >
                {{ t('common.edit') }}
              </VTooltip>
            </VBtn>
            <VBtn
              icon
              variant="text"
              size="small"
              color="default"
              @click="cloneRole(item)"
            >
              <VIcon icon="tabler-copy" />
              <VTooltip
                activator="parent"
                location="top"
              >
                {{ t('common.clone') }}
              </VTooltip>
            </VBtn>
            <VBtn
              v-if="!item.is_system"
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
            {{ t('roles.noRoles') }}
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
        :title="isEditMode ? t('roles.editRole') : t('roles.newRole')"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
        <VCardText>
          <VForm @submit.prevent="handleDrawerSubmit">
            <VRow>
              <!-- Name -->
              <VCol cols="12">
                <AppTextField
                  v-model="drawerForm.name"
                  :label="t('roles.roleName')"
                  :placeholder="t('roles.roleNamePlaceholder')"
                />
              </VCol>

              <!-- Role Level -->
              <VCol cols="12">
                <h6 class="text-h6 mb-3">
                  {{ t('roles.roleLevel') }}
                </h6>
                <VRadioGroup
                  :model-value="drawerForm.is_administrative ? 'management' : 'operational'"
                  @update:model-value="drawerForm.is_administrative = $event === 'management'"
                >
                  <VRadio value="operational">
                    <template #label>
                      <div>
                        <span class="font-weight-medium">{{ t('roles.operational') }}</span>
                        <div class="text-body-2 text-disabled">
                          {{ t('roles.operationalDescription') }}
                        </div>
                      </div>
                    </template>
                  </VRadio>
                  <VRadio
                    value="management"
                    class="mt-2"
                  >
                    <template #label>
                      <div>
                        <span class="font-weight-medium">{{ t('roles.management') }}</span>
                        <VTooltip location="top">
                          <template #activator="{ props: tooltipProps }">
                            <VIcon
                              icon="tabler-info-circle"
                              size="16"
                              class="ms-1 text-disabled"
                              v-bind="tooltipProps"
                            />
                          </template>
                          {{ t('roles.managementTooltip') }}
                        </VTooltip>
                        <div class="text-body-2 text-disabled">
                          {{ t('roles.managementDescription') }}
                        </div>
                      </div>
                    </template>
                  </VRadio>
                </VRadioGroup>
              </VCol>

              <VCol cols="12">
                <VDivider />
              </VCol>

              <!-- Permissions (shared component) -->
              <VCol cols="12">
                <PermissionMatrix
                  v-model:selected-permissions="drawerForm.permissions"
                  :permission-catalog="settingsStore.permissionCatalog"
                  :permission-modules="settingsStore.permissionModules"
                  :is-administrative="drawerForm.is_administrative"
                  scope="company"
                />
              </VCol>

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
