<script setup>
definePage({ meta: { surface: 'structure', module: 'core.roles' } })

import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

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
const isAdvancedMode = ref(false)

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

// ─── Inactive module permissions (FIX: make them visible + uncheckable) ─────
const inactiveModulePermissions = computed(() => {
  const catalog = settingsStore.permissionCatalog
  const selected = new Set(drawerForm.value.permissions)

  // Permissions from inactive modules that are currently in the form
  const inactive = catalog.filter(p => !p.module_active && selected.has(p.id))

  // Group by module
  const groups = {}
  for (const p of inactive) {
    if (!groups[p.module_key]) {
      groups[p.module_key] = {
        module_key: p.module_key,
        name: p.module_name,
        module_icon: moduleIconMap.value?.[p.module_key] || null,
        permissions: [],
      }
    }
    groups[p.module_key].permissions.push(p)
  }

  return Object.values(groups)
})

const hasInactivePermissions = computed(() => inactiveModulePermissions.value.length > 0)

// ─── i18n helpers for permission catalog ─────
const te = useI18n().te
const modName = key => te(`permissionCatalog.modules.${key}.name`) ? t(`permissionCatalog.modules.${key}.name`) : null
const modDesc = key => te(`permissionCatalog.modules.${key}.description`) ? t(`permissionCatalog.modules.${key}.description`) : null
const capLabel = key => te(`permissionCatalog.bundles.${key}.label`) ? t(`permissionCatalog.bundles.${key}.label`) : null
const capHint = key => te(`permissionCatalog.bundles.${key}.hint`) ? t(`permissionCatalog.bundles.${key}.hint`) : null
const permLabel = key => te(`permissionCatalog.permissions.${key}.label`) ? t(`permissionCatalog.permissions.${key}.label`) : null
const permHint = key => te(`permissionCatalog.permissions.${key}.hint`) ? t(`permissionCatalog.permissions.${key}.hint`) : null

// ─── Simple mode: Capability bundles per module ─────
const capabilityModules = computed(() => {
  const modules = settingsStore.permissionModules
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

// ─── Simple mode: unbundled permissions (modules with perms but no bundles) ─────
// Module icon lookup from permissionModules (which carry module_icon from API)
const moduleIconMap = computed(() => {
  const map = {}
  for (const m of settingsStore.permissionModules) {
    map[m.module_key] = m.module_icon
  }

  return map
})

const unbundledModules = computed(() => {
  const catalog = settingsStore.permissionCatalog
  const isManagement = drawerForm.value.is_administrative
  const bundledModuleKeys = new Set(capabilityModules.value.map(m => m.module_key))
  const groups = {}

  for (const p of catalog) {
    if (!p.module_active) continue
    if (bundledModuleKeys.has(p.module_key)) continue
    if (!isManagement && p.is_admin) continue

    if (!groups[p.module_key]) {
      groups[p.module_key] = {
        module_key: p.module_key,
        name: p.module_name,
        description: p.module_description || '',
        isCore: p.module_key.startsWith('core.'),
        module_icon: moduleIconMap.value[p.module_key] || null,
        permissions: [],
      }
    }
    groups[p.module_key].permissions.push(p)
  }

  return Object.values(groups)
})

// Capability state: 'checked' | 'unchecked' | 'custom'
const getCapabilityState = cap => {
  if (!cap.permission_ids?.length) return 'unchecked'

  const selected = new Set(drawerForm.value.permissions)
  const allChecked = cap.permission_ids.every(id => selected.has(id))
  const noneChecked = cap.permission_ids.every(id => !selected.has(id))

  if (allChecked) return 'checked'
  if (noneChecked) return 'unchecked'

  return 'custom'
}

const toggleCapability = cap => {
  if (!cap.permission_ids?.length) return

  const selected = new Set(drawerForm.value.permissions)
  const state = getCapabilityState(cap)

  if (state === 'unchecked') {
    // Nothing selected → check all
    cap.permission_ids.forEach(id => selected.add(id))
  }
  else {
    // 'checked' or 'custom' (indeterminate) → clear all
    cap.permission_ids.forEach(id => selected.delete(id))
  }

  drawerForm.value.permissions = [...selected]
}

// ─── Advanced mode: Permission groups ───────────────
const permissionGroups = computed(() => {
  const catalog = settingsStore.permissionCatalog
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
        module_icon: moduleIconMap.value[p.module_key] || null,
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

              <!-- Capabilities header + mode toggle -->
              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-4">
                  <h6 class="text-h6">
                    {{ t('roles.capabilities') }}
                  </h6>
                  <VBtn
                    variant="text"
                    size="small"
                    color="default"
                    :prepend-icon="isAdvancedMode ? 'tabler-layout-grid' : 'tabler-adjustments'"
                    @click="isAdvancedMode = !isAdvancedMode"
                  >
                    {{ isAdvancedMode ? t('roles.simpleView') : t('roles.advanced') }}
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
                      <span class="text-body-1 font-weight-medium">{{ t('roles.coreTeamCompany') }}</span>
                    </div>

                    <template
                      v-for="mod in coreModules"
                      :key="mod.module_key"
                    >
                      <div class="ms-7 mb-4">
                        <div class="d-flex align-center gap-2 mb-1">
                          <VIcon
                            :icon="mod.module_icon || 'tabler-puzzle'"
                            size="18"
                            color="primary"
                          />
                          <span class="text-body-1 font-weight-medium">{{ modName(mod.module_key) || mod.module_name }}</span>
                        </div>
                        <div
                          v-if="mod.module_description"
                          class="text-body-2 text-disabled mb-2 ms-7"
                        >
                          {{ modDesc(mod.module_key) || mod.module_description }}
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
                              <span>{{ capLabel(cap.key) || cap.label }}</span>
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
                                {{ capHint(cap.key) || cap.hint }}
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
                            {{ t('common.custom') }}
                          </VChip>
                          <VChip
                            v-else-if="cap.is_admin"
                            size="x-small"
                            color="error"
                            variant="tonal"
                          >
                            {{ t('common.sensitive') }}
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
                          :icon="mod.module_icon || 'tabler-package'"
                          size="20"
                          color="info"
                        />
                        <span class="text-body-1 font-weight-medium">{{ modName(mod.module_key) || mod.module_name }}</span>
                      </div>

                      <div class="ms-7 mb-4">
                        <div
                          v-if="mod.module_description"
                          class="text-body-2 text-disabled mb-2"
                        >
                          {{ modDesc(mod.module_key) || mod.module_description }}
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
                              <span>{{ capLabel(cap.key) || cap.label }}</span>
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
                                {{ capHint(cap.key) || cap.hint }}
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
                            {{ t('common.custom') }}
                          </VChip>
                          <VChip
                            v-else-if="cap.is_admin"
                            size="x-small"
                            color="error"
                            variant="tonal"
                          >
                            {{ t('common.sensitive') }}
                          </VChip>
                        </div>
                      </div>
                    </template>
                  </template>

                  <!-- Unbundled modules (permissions without bundles) -->
                  <template v-if="unbundledModules.length">
                    <VDivider
                      v-if="coreModules.length || businessModules.length"
                      class="mb-3"
                    />

                    <template
                      v-for="mod in unbundledModules"
                      :key="mod.module_key"
                    >
                      <div class="d-flex align-center gap-2 mb-3">
                        <VIcon
                          :icon="mod.module_icon || 'tabler-package'"
                          size="20"
                          color="info"
                        />
                        <span class="text-body-1 font-weight-medium">{{ modName(mod.module_key) || mod.name }}</span>
                      </div>

                      <div class="ms-7 mb-4">
                        <div
                          v-if="mod.description"
                          class="text-body-2 text-disabled mb-2"
                        >
                          {{ modDesc(mod.module_key) || mod.description }}
                        </div>
                        <div
                          v-for="perm in mod.permissions"
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
                              <span>{{ permLabel(perm.key) || perm.label }}</span>
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
                                {{ permHint(perm.key) || perm.hint }}
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
                            {{ t('common.sensitive') }}
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
                      <span class="text-body-1 font-weight-medium">{{ t('roles.coreTeamCompany') }}</span>
                    </div>

                    <template
                      v-for="group in permissionGroups.filter(g => g.isCore)"
                      :key="group.module_key"
                    >
                      <div class="ms-7 mb-4">
                        <div class="d-flex align-center gap-2">
                          <VIcon
                            :icon="group.module_icon || 'tabler-puzzle'"
                            size="18"
                            color="primary"
                          />
                          <span class="text-body-1 font-weight-medium">{{ modName(group.module_key) || group.name }}</span>
                        </div>
                        <div
                          v-if="group.description"
                          class="text-body-2 text-disabled mb-2"
                        >
                          {{ modDesc(group.module_key) || group.description }}
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
                              <span>{{ permLabel(perm.key) || perm.label }}</span>
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
                                {{ permHint(perm.key) || perm.hint }}
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
                            {{ t('common.sensitive') }}
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
                          :icon="group.module_icon || 'tabler-package'"
                          size="20"
                          color="info"
                        />
                        <span class="text-body-1 font-weight-medium">{{ modName(group.module_key) || group.name }}</span>
                      </div>

                      <div class="ms-7 mb-4">
                        <div
                          v-if="group.description"
                          class="text-body-2 text-disabled mb-2"
                        >
                          {{ modDesc(group.module_key) || group.description }}
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
                              <span>{{ permLabel(perm.key) || perm.label }}</span>
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
                                {{ permHint(perm.key) || perm.hint }}
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
                            {{ t('common.sensitive') }}
                          </VChip>
                        </div>
                      </div>
                    </template>
                  </template>
                </template>
              </VCol>

              <!-- ═══ INACTIVE MODULE PERMISSIONS ═══ -->
              <VCol
                v-if="hasInactivePermissions"
                cols="12"
              >
                <VDivider class="mb-3" />
                <VAlert
                  type="warning"
                  variant="tonal"
                  density="compact"
                  class="mb-3"
                >
                  {{ t('roles.inactiveModulePermissionsHint') }}
                </VAlert>

                <template
                  v-for="group in inactiveModulePermissions"
                  :key="group.module_key"
                >
                  <div class="d-flex align-center gap-2 mb-2">
                    <VIcon
                      icon="tabler-package-off"
                      size="20"
                      color="disabled"
                    />
                    <span class="text-body-1 font-weight-medium text-disabled">{{ modName(group.module_key) || group.name }}</span>
                    <VChip
                      size="x-small"
                      color="warning"
                      variant="tonal"
                    >
                      {{ t('roles.moduleInactive') }}
                    </VChip>
                  </div>

                  <div class="ms-7 mb-4">
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
                          <span class="text-disabled">{{ perm.label }}</span>
                        </template>
                      </VCheckbox>
                    </div>
                  </div>
                </template>
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
