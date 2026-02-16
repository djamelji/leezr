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
const isAdvancedMode = ref(false)

// Surface guard: structure pages require management level
onMounted(async () => {
  if (auth.roleLevel !== 'management') {
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

// ─── Simple mode: Capability bundles per module ─────
const capabilityModules = computed(() => {
  const modules = companyStore.permissionModules
  const isManagement = drawerForm.value.is_administrative

  return modules
    .filter(m => m.module_active)
    .map(mod => ({
      ...mod,
      capabilities: mod.capabilities
        .filter(cap => isManagement || !cap.is_admin),
    }))
    .filter(m => m.capabilities.length > 0)
})

const coreModules = computed(() => capabilityModules.value.filter(m => m.is_core))
const businessModules = computed(() => capabilityModules.value.filter(m => !m.is_core))

// Capability state: 'checked' | 'unchecked' | 'custom'
const getCapabilityState = cap => {
  const selected = new Set(drawerForm.value.permissions)
  const allChecked = cap.permission_ids.every(id => selected.has(id))
  const noneChecked = cap.permission_ids.every(id => !selected.has(id))

  if (allChecked) return 'checked'
  if (noneChecked) return 'unchecked'

  return 'custom'
}

const toggleCapability = cap => {
  const selected = new Set(drawerForm.value.permissions)
  const allChecked = cap.permission_ids.every(id => selected.has(id))

  if (allChecked) {
    cap.permission_ids.forEach(id => selected.delete(id))
  }
  else {
    cap.permission_ids.forEach(id => selected.add(id))
  }

  drawerForm.value.permissions = [...selected]
}

// ─── Advanced mode: Permission groups ───────────────
const permissionGroups = computed(() => {
  const catalog = companyStore.permissionCatalog
  const isManagement = drawerForm.value.is_administrative
  const coreGroups = {}
  const moduleGroups = {}

  for (const p of catalog) {
    if (!p.module_active) continue
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

  return [
    ...Object.values(coreGroups),
    ...Object.values(moduleGroups),
  ]
})

const hasCoreGroups = computed(() =>
  permissionGroups.value.some(g => g.isCore),
)

const hasModuleGroups = computed(() =>
  permissionGroups.value.some(g => !g.isCore),
)

// Strip sensitive permissions when switching to Operational
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
  { title: 'Actions', key: 'actions', align: 'center', width: '180px', sortable: false },
]

// ─── Drawer actions ─────────────────────────────────
const openCreateDrawer = () => {
  isEditMode.value = false
  editingRole.value = null
  drawerForm.value = { name: '', is_administrative: false, permissions: [] }
  isAdvancedMode.value = false
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
  isAdvancedMode.value = false
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
  isAdvancedMode.value = false
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
              <VTooltip
                activator="parent"
                location="top"
              >
                Edit
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
                Clone
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
              <!-- Name -->
              <VCol cols="12">
                <AppTextField
                  v-model="drawerForm.name"
                  label="Role Name"
                  placeholder="e.g. Dispatcher, Accountant"
                />
              </VCol>

              <!-- Role Level -->
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

              <!-- Capabilities header + mode toggle -->
              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-4">
                  <h6 class="text-h6">
                    Capabilities
                  </h6>
                  <VBtn
                    variant="text"
                    size="small"
                    color="default"
                    :prepend-icon="isAdvancedMode ? 'tabler-layout-grid' : 'tabler-adjustments'"
                    @click="isAdvancedMode = !isAdvancedMode"
                  >
                    {{ isAdvancedMode ? 'Simple view' : 'Advanced' }}
                  </VBtn>
                </div>

                <!-- ═══ SIMPLE MODE ═══ -->
                <template v-if="!isAdvancedMode">
                  <!-- Core capabilities -->
                  <template v-if="coreModules.length">
                    <div class="d-flex align-center gap-2 mb-3">
                      <VIcon
                        icon="tabler-building"
                        size="20"
                        color="primary"
                      />
                      <span class="text-body-1 font-weight-medium">Core &mdash; Team &amp; Company</span>
                    </div>

                    <template
                      v-for="mod in coreModules"
                      :key="mod.module_key"
                    >
                      <div class="ms-7 mb-4">
                        <div
                          v-if="mod.module_description"
                          class="text-body-2 text-disabled mb-2"
                        >
                          {{ mod.module_description }}
                        </div>
                        <div
                          v-for="cap in mod.capabilities"
                          :key="cap.key"
                          class="d-flex align-center"
                        >
                          <VCheckbox
                            :model-value="getCapabilityState(cap) === 'checked'"
                            :indeterminate="getCapabilityState(cap) === 'custom'"
                            hide-details
                            density="compact"
                            @update:model-value="toggleCapability(cap)"
                          >
                            <template #label>
                              <span>{{ cap.label }}</span>
                              <VTooltip
                                v-if="cap.hint"
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
                                {{ cap.hint }}
                              </VTooltip>
                            </template>
                          </VCheckbox>
                          <VSpacer />
                          <VChip
                            v-if="getCapabilityState(cap) === 'custom'"
                            size="x-small"
                            color="warning"
                            variant="tonal"
                          >
                            Custom
                          </VChip>
                          <VChip
                            v-else-if="cap.is_admin"
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

                  <!-- Business module capabilities -->
                  <template v-if="businessModules.length">
                    <VDivider
                      v-if="coreModules.length"
                      class="mb-3"
                    />

                    <template
                      v-for="mod in businessModules"
                      :key="mod.module_key"
                    >
                      <div class="d-flex align-center gap-2 mb-3">
                        <VIcon
                          icon="tabler-package"
                          size="20"
                          color="info"
                        />
                        <span class="text-body-1 font-weight-medium">{{ mod.module_name }}</span>
                      </div>

                      <div class="ms-7 mb-4">
                        <div
                          v-if="mod.module_description"
                          class="text-body-2 text-disabled mb-2"
                        >
                          {{ mod.module_description }}
                        </div>
                        <div
                          v-for="cap in mod.capabilities"
                          :key="cap.key"
                          class="d-flex align-center"
                        >
                          <VCheckbox
                            :model-value="getCapabilityState(cap) === 'checked'"
                            :indeterminate="getCapabilityState(cap) === 'custom'"
                            hide-details
                            density="compact"
                            @update:model-value="toggleCapability(cap)"
                          >
                            <template #label>
                              <span>{{ cap.label }}</span>
                              <VTooltip
                                v-if="cap.hint"
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
                                {{ cap.hint }}
                              </VTooltip>
                            </template>
                          </VCheckbox>
                          <VSpacer />
                          <VChip
                            v-if="getCapabilityState(cap) === 'custom'"
                            size="x-small"
                            color="warning"
                            variant="tonal"
                          >
                            Custom
                          </VChip>
                          <VChip
                            v-else-if="cap.is_admin"
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
                </template>

                <!-- ═══ ADVANCED MODE ═══ -->
                <template v-else>
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
