<script setup>
definePage({ meta: { surface: 'structure', module: 'core.roles' } })

import { useAuthStore } from '@/core/stores/auth'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useMembersStore } from '@/modules/company/members/members.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import PermissionMatrix from '@/pages/shared/_PermissionMatrix.vue'

const { t } = useI18n()
const auth = useAuthStore()
const settingsStore = useCompanySettingsStore()
const membersStore = useMembersStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const isLoading = ref(true)
const actionLoading = ref(null)

// Drawer state
const isDrawerOpen = ref(false)
const isEditMode = ref(false)
const editingRole = ref(null)
const drawerForm = ref({ name: '', is_administrative: false, permissions: [], field_config: [] })
const drawerLoading = ref(false)

// ADR-164: Field config state
const fieldActivations = ref([])
const fieldActivationsLoaded = ref(false)

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

// ─── Field config helpers ───────────────────────────
const loadFieldActivations = async () => {
  if (fieldActivationsLoaded.value) return
  await membersStore.fetchFieldActivations()
  fieldActivations.value = membersStore.fieldActivations
    .filter(a => a.enabled && a.definition?.scope === 'company_user')
  fieldActivationsLoaded.value = true
}

const getFieldConfigEntry = code => {
  return drawerForm.value.field_config.find(f => f.code === code)
}

const isFieldVisible = code => {
  const entry = getFieldConfigEntry(code)

  return entry ? (entry.visible !== false) : true
}

const isFieldRequired = code => {
  const entry = getFieldConfigEntry(code)

  return entry?.required || false
}

const getFieldOrder = code => {
  const entry = getFieldConfigEntry(code)

  return entry?.order ?? ''
}

const getFieldGroup = code => {
  const entry = getFieldConfigEntry(code)

  return entry?.group || ''
}

const updateFieldConfig = (code, prop, value) => {
  const idx = drawerForm.value.field_config.findIndex(f => f.code === code)

  if (idx === -1) {
    // Create a new entry
    const entry = { code, scope: 'company_user', visible: true, required: false, order: 0 }

    entry[prop] = value
    drawerForm.value.field_config.push(entry)
  }
  else {
    drawerForm.value.field_config[idx][prop] = value
  }
}

const hasFieldConfig = computed(() => drawerForm.value.field_config.length > 0)

// ─── Drawer actions ─────────────────────────────────
const openCreateDrawer = async () => {
  isEditMode.value = false
  editingRole.value = null
  drawerForm.value = { name: '', is_administrative: false, permissions: [], field_config: [] }
  isDrawerOpen.value = true
  await loadFieldActivations()
}

const openEditDrawer = async role => {
  isEditMode.value = true
  editingRole.value = role
  drawerForm.value = {
    name: role.name,
    is_administrative: role.is_administrative,
    permissions: role.permissions?.map(p => p.id) || [],
    field_config: role.field_config ? JSON.parse(JSON.stringify(role.field_config)) : [],
  }
  isDrawerOpen.value = true
  await loadFieldActivations()
}

const cloneRole = async role => {
  isEditMode.value = false
  editingRole.value = null
  drawerForm.value = {
    name: `${role.name} Copy`,
    is_administrative: role.is_administrative,
    permissions: role.permissions?.map(p => p.id) || [],
    field_config: role.field_config ? JSON.parse(JSON.stringify(role.field_config)) : [],
  }
  isDrawerOpen.value = true
  await loadFieldActivations()
}

const handleDrawerSubmit = async () => {
  drawerLoading.value = true

  try {
    const payload = {
      name: drawerForm.value.name,
      is_administrative: drawerForm.value.is_administrative,
      permissions: drawerForm.value.permissions,
      field_config: drawerForm.value.field_config.length > 0 ? drawerForm.value.field_config : null,
    }

    if (isEditMode.value) {
      const data = await settingsStore.updateCompanyRole(editingRole.value.id, payload)

      toast(data.message, 'success')
    }
    else {
      const data = await settingsStore.createCompanyRole(payload)

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
  const ok = await confirm({
    question: t('roles.confirmDelete', { name: role.name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('common.deleteSuccess'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
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
            <VChip
              v-if="item.field_config?.length"
              size="x-small"
              color="success"
              variant="tonal"
            >
              {{ t('roles.fieldProfile') }}
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

              <!-- ADR-164: Field Configuration -->
              <VCol
                v-if="fieldActivations.length"
                cols="12"
              >
                <VDivider class="mb-4" />
                <div class="d-flex align-center gap-2 mb-3">
                  <h6 class="text-h6">
                    {{ t('roles.fieldConfiguration') }}
                  </h6>
                  <VTooltip location="top">
                    <template #activator="{ props: tp }">
                      <VIcon
                        icon="tabler-info-circle"
                        size="16"
                        class="text-disabled"
                        v-bind="tp"
                      />
                    </template>
                    {{ t('roles.fieldConfigTooltip') }}
                  </VTooltip>
                </div>

                <VTable
                  density="compact"
                  class="text-no-wrap"
                >
                  <thead>
                    <tr>
                      <th>{{ t('common.name') }}</th>
                      <th style="width: 80px;">
                        {{ t('roles.visible') }}
                      </th>
                      <th style="width: 80px;">
                        {{ t('members.required') }}
                      </th>
                      <th style="width: 80px;">
                        {{ t('roles.order') }}
                      </th>
                      <th style="width: 100px;">
                        {{ t('roles.group') }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="fa in fieldActivations"
                      :key="fa.definition.code"
                    >
                      <td class="font-weight-medium">
                        {{ fa.definition.label }}
                      </td>
                      <td>
                        <VCheckbox
                          :model-value="isFieldVisible(fa.definition.code)"
                          density="compact"
                          hide-details
                          @update:model-value="updateFieldConfig(fa.definition.code, 'visible', $event)"
                        />
                      </td>
                      <td>
                        <VCheckbox
                          :model-value="isFieldRequired(fa.definition.code)"
                          density="compact"
                          hide-details
                          :disabled="!isFieldVisible(fa.definition.code)"
                          @update:model-value="updateFieldConfig(fa.definition.code, 'required', $event)"
                        />
                      </td>
                      <td>
                        <AppTextField
                          :model-value="getFieldOrder(fa.definition.code)"
                          type="number"
                          density="compact"
                          hide-details
                          style="max-inline-size: 70px;"
                          @update:model-value="updateFieldConfig(fa.definition.code, 'order', parseInt($event) || 0)"
                        />
                      </td>
                      <td>
                        <AppTextField
                          :model-value="getFieldGroup(fa.definition.code)"
                          density="compact"
                          hide-details
                          style="max-inline-size: 90px;"
                          :placeholder="t('roles.group')"
                          @update:model-value="updateFieldConfig(fa.definition.code, 'group', $event || null)"
                        />
                      </td>
                    </tr>
                  </tbody>
                </VTable>
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

    <ConfirmDialogComponent />
  </div>
</template>
