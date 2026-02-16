<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyStore } from '@/core/stores/company'
import { useAppToast } from '@/composables/useAppToast'

const auth = useAuthStore()
const companyStore = useCompanyStore()
const router = useRouter()
const { toast } = useAppToast()

const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingRole = ref(null)
const drawerForm = ref({ name: '', is_administrative: false, permissions: [] })
const drawerLoading = ref(false)

// Guard: owner-only
onMounted(async () => {
  if (!auth.isOwner) {
    router.push('/')

    return
  }

  try {
    await Promise.all([
      companyStore.fetchCompanyRoles(),
      companyStore.fetchPermissionCatalog(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

// ─── Permission groups for drawer ───────────────────
// Group by core vs module, with descriptions
const permissionGroups = computed(() => {
  const catalog = companyStore.permissionCatalog
  const isManagement = drawerForm.value.is_administrative
  const coreGroups = {}
  const moduleGroups = {}

  for (const p of catalog) {
    // Inactive module = invisible
    if (!p.module_active) continue

    // Invisible (not grayed) for operational roles
    if (!isManagement && p.is_admin) continue

    const isCore = p.module_key.startsWith('core.')
    const target = isCore ? coreGroups : moduleGroups

    if (!target[p.module_key]) {
      target[p.module_key] = {
        module_key: p.module_key,
        name: p.module_name,
        description: p.module_description || '',
        isCore,
        permissions: [],
      }
    }
    target[p.module_key].permissions.push(p)
  }

  // Core groups first, then module groups
  return [
    ...Object.values(coreGroups),
    ...Object.values(moduleGroups),
  ]
})

// Has any core groups?
const hasCoreGroups = computed(() =>
  permissionGroups.value.some(g => g.isCore),
)

const hasModuleGroups = computed(() =>
  permissionGroups.value.some(g => !g.isCore),
)

// Strip sensitive permissions from selection when switching to Operational
watch(() => drawerForm.value.is_administrative, newVal => {
  if (!newVal) {
    const adminIds = new Set(
      companyStore.permissionCatalog.filter(p => p.is_admin).map(p => p.id),
    )

    drawerForm.value.permissions = drawerForm.value.permissions.filter(id => !adminIds.has(id))
  }
})

// ─── Table ──────────────────────────────────────────
const headers = [
  { title: 'Name', key: 'name' },
  { title: 'Level', key: 'level', width: '140px', sortable: false },
  { title: 'Members', key: 'memberships_count', align: 'center', width: '100px' },
  { title: 'Actions', key: 'actions', align: 'center', width: '150px', sortable: false },
]

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

const isPermissionChecked = permId => {
  return drawerForm.value.permissions.includes(permId)
}

const togglePermission = permId => {
  const idx = drawerForm.value.permissions.indexOf(permId)
  if (idx === -1) {
    drawerForm.value.permissions.push(permId)
  }
  else {
    drawerForm.value.permissions.splice(idx, 1)
  }
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    if (isEditMode.value) {
      const data = await companyStore.updateCompanyRole(editingRole.value.id, {
        name: drawerForm.value.name,
        is_administrative: drawerForm.value.is_administrative,
        permissions: drawerForm.value.permissions,
      })

      toast(data.message, 'success')
    }
    else {
      const data = await companyStore.createCompanyRole({
        name: drawerForm.value.name,
        is_administrative: drawerForm.value.is_administrative,
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
    const data = await companyStore.deleteCompanyRole(role.id)

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
        Company Roles
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
        Define who can do what in your company.
      </VCardSubtitle>

      <VDataTable
        :headers="headers"
        :items="companyStore.roles"
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
              system
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
            {{ item.is_administrative ? 'Management' : 'Operational' }}
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
            No roles yet. Create one to get started.
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
        :title="isEditMode ? 'Edit Role' : 'New Role'"
        @cancel="isDrawerOpen = false"
      />

      <VDivider />

      <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
        <VCardText>
          <VForm @submit.prevent="handleDrawerSubmit">
            <VRow>
              <!-- Name only — no key field -->
              <VCol cols="12">
                <AppTextField
                  v-model="drawerForm.name"
                  label="Role Name"
                  placeholder="e.g. Dispatcher, Accountant"
                />
              </VCol>

              <!-- Role Level — radio buttons -->
              <VCol cols="12">
                <h6 class="text-h6 mb-3">
                  Role Level
                </h6>
                <VRadioGroup
                  :model-value="drawerForm.is_administrative ? 'management' : 'operational'"
                  @update:model-value="drawerForm.is_administrative = $event === 'management'"
                >
                  <VRadio value="operational">
                    <template #label>
                      <div>
                        <span class="font-weight-medium">Operational</span>
                        <div class="text-body-2 text-disabled">
                          Can manage daily work only.
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
                        <span class="font-weight-medium">Management</span>
                        <VTooltip location="top">
                          <template #activator="{ props: tooltipProps }">
                            <VIcon
                              icon="tabler-info-circle"
                              size="16"
                              class="ms-1 text-disabled"
                              v-bind="tooltipProps"
                            />
                          </template>
                          Management roles can configure company structure and sensitive settings.
                        </VTooltip>
                        <div class="text-body-2 text-disabled">
                          Can manage team and company configuration.
                        </div>
                      </div>
                    </template>
                  </VRadio>
                </VRadioGroup>
              </VCol>

              <VCol cols="12">
                <VDivider />
              </VCol>

              <!-- Capabilities — grouped with section headers -->
              <VCol cols="12">
                <h6 class="text-h6 mb-4">
                  Capabilities
                </h6>

                <!-- Core section -->
                <template v-if="hasCoreGroups">
                  <div class="d-flex align-center gap-2 mb-3">
                    <VIcon
                      icon="tabler-building"
                      size="20"
                      color="primary"
                    />
                    <span class="text-body-1 font-weight-medium">Core &mdash; Team &amp; Company</span>
                  </div>

                  <template
                    v-for="group in permissionGroups.filter(g => g.isCore)"
                    :key="group.module_key"
                  >
                    <div class="ms-7 mb-4">
                      <div class="text-body-1 font-weight-medium">
                        {{ group.name }}
                      </div>
                      <div
                        v-if="group.description"
                        class="text-body-2 text-disabled mb-2"
                      >
                        {{ group.description }}
                      </div>
                      <div
                        v-for="perm in group.permissions"
                        :key="perm.id"
                        class="d-flex align-center"
                      >
                        <VCheckbox
                          :model-value="isPermissionChecked(perm.id)"
                          hide-details
                          density="compact"
                          @update:model-value="togglePermission(perm.id)"
                        >
                          <template #label>
                            <span>{{ perm.label }}</span>
                            <VTooltip
                              v-if="perm.hint"
                              location="top"
                            >
                              <template #activator="{ props: tp }">
                                <VIcon
                                  icon="tabler-info-circle"
                                  size="14"
                                  class="ms-1 text-disabled"
                                  v-bind="tp"
                                />
                              </template>
                              {{ perm.hint }}
                            </VTooltip>
                          </template>
                        </VCheckbox>
                        <VSpacer />
                        <VChip
                          v-if="perm.is_admin"
                          size="x-small"
                          color="error"
                          variant="tonal"
                        >
                          Sensitive
                        </VChip>
                      </div>
                    </div>
                  </template>
                </template>

                <!-- Module section(s) -->
                <template v-if="hasModuleGroups">
                  <VDivider
                    v-if="hasCoreGroups"
                    class="mb-3"
                  />

                  <template
                    v-for="group in permissionGroups.filter(g => !g.isCore)"
                    :key="group.module_key"
                  >
                    <div class="d-flex align-center gap-2 mb-3">
                      <VIcon
                        icon="tabler-package"
                        size="20"
                        color="info"
                      />
                      <span class="text-body-1 font-weight-medium">{{ group.name }}</span>
                    </div>

                    <div class="ms-7 mb-4">
                      <div
                        v-if="group.description"
                        class="text-body-2 text-disabled mb-2"
                      >
                        {{ group.description }}
                      </div>
                      <div
                        v-for="perm in group.permissions"
                        :key="perm.id"
                        class="d-flex align-center"
                      >
                        <VCheckbox
                          :model-value="isPermissionChecked(perm.id)"
                          hide-details
                          density="compact"
                          @update:model-value="togglePermission(perm.id)"
                        >
                          <template #label>
                            <span>{{ perm.label }}</span>
                            <VTooltip
                              v-if="perm.hint"
                              location="top"
                            >
                              <template #activator="{ props: tp }">
                                <VIcon
                                  icon="tabler-info-circle"
                                  size="14"
                                  class="ms-1 text-disabled"
                                  v-bind="tp"
                                />
                              </template>
                              {{ perm.hint }}
                            </VTooltip>
                          </template>
                        </VCheckbox>
                        <VSpacer />
                        <VChip
                          v-if="perm.is_admin"
                          size="x-small"
                          color="error"
                          variant="tonal"
                        >
                          Sensitive
                        </VChip>
                      </div>
                    </div>
                  </template>
                </template>
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
      </div>
    </VNavigationDrawer>
  </div>
</template>
