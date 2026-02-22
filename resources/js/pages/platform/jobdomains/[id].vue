<script setup>
import { usePlatformJobdomainsStore } from '@/modules/platform-admin/jobdomains/jobdomains.store'
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_jobdomains',
  },
})

const route = useRoute()
const router = useRouter()
const jobdomainsStore = usePlatformJobdomainsStore()
const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)
const activeTab = ref('overview')

// ─── Jobdomain state ───────────────────────────────
const jobdomain = ref(null)
const fieldDefinitions = ref([])
const permissionCatalog = ref([])

// ─── Overview form ──────────────────────────────────
const overviewForm = ref({ label: '', description: '', allowCustomFields: false })

const resetOverviewForm = () => {
  if (!jobdomain.value) return
  overviewForm.value = {
    label: jobdomain.value.label,
    description: jobdomain.value.description || '',
    allowCustomFields: jobdomain.value.allow_custom_fields || false,
  }
}

// ─── Delete dialog ──────────────────────────────────
const isDeleteDialogOpen = ref(false)

const handleDelete = async () => {
  try {
    const data = await jobdomainsStore.deleteJobdomain(jobdomain.value.id)

    toast(data.message, 'success')
    isDeleteDialogOpen.value = false
    router.push({ name: 'platform-jobdomains' })
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete.', 'error')
  }
}

// ─── Save overview ──────────────────────────────────
const saveOverview = async () => {
  isSaving.value = true

  try {
    const data = await jobdomainsStore.updateJobdomain(jobdomain.value.id, {
      label: overviewForm.value.label,
      description: overviewForm.value.description || null,
      allow_custom_fields: overviewForm.value.allowCustomFields,
    })

    jobdomain.value = data.jobdomain
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to save.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

// ─── Modules ────────────────────────────────────────
const allModules = computed(() => settingsStore.modules)
const jdKey = computed(() => jobdomain.value?.key)
const defaultModuleKeys = computed(() => new Set(jobdomain.value?.default_modules || []))

const isModuleSelected = moduleKey => {
  return defaultModuleKeys.value.has(moduleKey)
}

// Core modules — always active, cannot toggle
const coreModules = computed(() =>
  allModules.value.filter(m => m.type === 'core'),
)

// Included by default in this jobdomain (non-core)
const includedModules = computed(() =>
  allModules.value.filter(m => m.type !== 'core' && defaultModuleKeys.value.has(m.key)),
)

// Compatible with this jobdomain but not included by default
const compatibleModules = computed(() =>
  allModules.value.filter(m => {
    if (m.type === 'core') return false
    if (defaultModuleKeys.value.has(m.key)) return false

    // No restriction or matches this jobdomain
    return m.compatible_jobdomains === null || (jdKey.value && m.compatible_jobdomains.includes(jdKey.value))
  }),
)

// Incompatible with this jobdomain
const incompatibleModules = computed(() =>
  allModules.value.filter(m => {
    if (m.type === 'core') return false
    if (defaultModuleKeys.value.has(m.key)) return false
    if (m.compatible_jobdomains === null) return false

    return !jdKey.value || !m.compatible_jobdomains.includes(jdKey.value)
  }),
)

const planLabel = planKey => {
  const labels = { pro: 'Pro', business: 'Business' }

  return labels[planKey] || planKey
}

const toggleModule = async (moduleKey, enabled) => {
  if (!jobdomain.value) return

  const current = [...(jobdomain.value.default_modules || [])]
  const updated = enabled
    ? [...current, moduleKey]
    : current.filter(k => k !== moduleKey)

  try {
    const data = await jobdomainsStore.updateJobdomain(jobdomain.value.id, {
      default_modules: updated,
    })

    jobdomain.value = data.jobdomain
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update modules.', 'error')
  }
}

// ─── Fields — Preset management ─────────────────────
const defaultFields = computed(() => jobdomain.value?.default_fields || [])

const presetCodes = computed(() => new Set(defaultFields.value.map(f => f.code)))

// Resolve preset entries with definition metadata
const presetFields = computed(() => {
  return defaultFields.value.map(f => {
    const def = fieldDefinitions.value.find(d => d.code === f.code)

    return {
      ...f,
      label: def?.label || f.code,
      scope: def?.scope || 'unknown',
      is_system: def?.is_system || false,
    }
  })
})

// Available = not in preset, grouped by scope
const availableCompanyDefs = computed(() => {
  return fieldDefinitions.value.filter(d => d.scope === 'company' && !presetCodes.value.has(d.code))
})

const availableCompanyUserDefs = computed(() => {
  return fieldDefinitions.value.filter(d => d.scope === 'company_user' && !presetCodes.value.has(d.code))
})

const savePresetFields = async newFields => {
  try {
    const data = await jobdomainsStore.updateJobdomain(jobdomain.value.id, {
      default_fields: newFields,
    })

    jobdomain.value = data.jobdomain
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update fields.', 'error')
  }
}

const addField = async code => {
  const maxOrder = defaultFields.value.reduce((max, f) => Math.max(max, f.order ?? 0), -1)
  const updated = [...defaultFields.value, { code, required: false, order: maxOrder + 1 }]

  await savePresetFields(updated)
}

const removeField = async code => {
  const updated = defaultFields.value.filter(f => f.code !== code)

  await savePresetFields(updated)
}

const updateFieldRequired = async (code, required) => {
  const updated = defaultFields.value.map(f => f.code === code ? { ...f, required } : f)

  await savePresetFields(updated)
}

const updateFieldOrder = async (code, order) => {
  const parsed = parseInt(order, 10)
  if (isNaN(parsed) || parsed < 0) return

  const updated = defaultFields.value.map(f => f.code === code ? { ...f, order: parsed } : f)

  await savePresetFields(updated)
}

const scopeColor = scope => {
  return scope === 'company' ? 'primary' : 'warning'
}

// ─── Roles — Preset management ──────────────────────
const moduleBundles = ref([])

const defaultRoles = computed(() => {
  const roles = jobdomain.value?.default_roles || {}

  return Object.entries(roles).map(([key, def]) => ({
    key,
    name: def.name,
    is_administrative: def.is_administrative || false,
    bundles: def.bundles || [],
    permissions: def.permissions || [],
  }))
})

// Role drawer state
const isRoleDrawerOpen = ref(false)
const isRoleEditMode = ref(false)
const editingRoleKey = ref(null)
const roleForm = ref({ name: '', is_administrative: false, bundles: [], permissions: [] })
const roleDrawerLoading = ref(false)
const isRoleAdvancedMode = ref(false)

// ─── Simple mode: Capability bundles for role drawer ─
const roleCapabilityModules = computed(() => {
  const isManagement = roleForm.value.is_administrative

  return moduleBundles.value
    .map(mod => ({
      ...mod,
      bundles: mod.bundles.filter(b => isManagement || !b.is_admin),
    }))
    .filter(m => m.bundles.length > 0)
})

const roleCoreModules = computed(() => roleCapabilityModules.value.filter(m => m.is_core))
const roleBusinessModules = computed(() => roleCapabilityModules.value.filter(m => !m.is_core))

const getRoleBundleState = bundle => {
  const selected = new Set(roleForm.value.bundles)

  return selected.has(bundle.key) ? 'checked' : 'unchecked'
}

const toggleRoleBundle = bundle => {
  const idx = roleForm.value.bundles.indexOf(bundle.key)
  if (idx === -1) {
    roleForm.value.bundles.push(bundle.key)
  }
  else {
    roleForm.value.bundles.splice(idx, 1)
  }
}

// ─── Advanced mode: Permission groups (mirrors Company Roles) ─
const rolePermissionGroups = computed(() => {
  const isManagement = roleForm.value.is_administrative
  const coreGroups = {}
  const moduleGroups = {}

  // Build module metadata lookup from moduleBundles
  const modMeta = {}
  for (const m of moduleBundles.value) {
    modMeta[m.module_key] = { name: m.module_name, description: '', isCore: m.is_core }
  }

  for (const p of permissionCatalog.value) {
    if (!isManagement && p.is_admin) continue

    const meta = modMeta[p.module_key] || { name: p.module_key, description: '', isCore: false }
    const target = meta.isCore ? coreGroups : moduleGroups

    if (!target[p.module_key]) {
      target[p.module_key] = {
        module_key: p.module_key,
        name: meta.name,
        description: meta.description,
        isCore: meta.isCore,
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

const hasCorePermGroups = computed(() => rolePermissionGroups.value.some(g => g.isCore))
const hasModulePermGroups = computed(() => rolePermissionGroups.value.some(g => !g.isCore))

watch(() => roleForm.value.is_administrative, newVal => {
  if (!newVal) {
    // Strip admin bundles
    const adminBundleKeys = new Set(
      moduleBundles.value.flatMap(m => m.bundles.filter(b => b.is_admin).map(b => b.key)),
    )

    roleForm.value.bundles = roleForm.value.bundles.filter(k => !adminBundleKeys.has(k))

    // Strip admin permissions
    const adminKeys = new Set(
      permissionCatalog.value.filter(p => p.is_admin).map(p => p.key),
    )

    roleForm.value.permissions = roleForm.value.permissions.filter(k => !adminKeys.has(k))
  }
})

const slugify = str =>
  str.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').substring(0, 50)

const generateRoleKey = name => {
  const base = slugify(name)
  if (!base) return ''
  const current = jobdomain.value?.default_roles || {}
  if (!current[base]) return base
  let i = 2
  while (current[`${base}_${i}`]) i++
  return `${base}_${i}`
}

const openRoleCreateDrawer = () => {
  isRoleEditMode.value = false
  editingRoleKey.value = null
  roleForm.value = { name: '', is_administrative: false, bundles: [], permissions: [] }
  isRoleAdvancedMode.value = false
  isRoleDrawerOpen.value = true
}

const openRoleEditDrawer = role => {
  isRoleEditMode.value = true
  editingRoleKey.value = role.key
  roleForm.value = {
    name: role.name,
    is_administrative: role.is_administrative,
    bundles: [...role.bundles],
    permissions: [...role.permissions],
  }
  isRoleAdvancedMode.value = role.permissions.length > 0 && role.bundles.length === 0
  isRoleDrawerOpen.value = true
}

const isRolePermChecked = permKey => {
  return roleForm.value.permissions.includes(permKey)
}

const toggleRolePerm = permKey => {
  const idx = roleForm.value.permissions.indexOf(permKey)
  if (idx === -1) {
    roleForm.value.permissions.push(permKey)
  }
  else {
    roleForm.value.permissions.splice(idx, 1)
  }
}

const saveDefaultRoles = async updatedRoles => {
  try {
    const data = await jobdomainsStore.updateJobdomain(jobdomain.value.id, {
      default_roles: updatedRoles,
    })

    jobdomain.value = data.jobdomain
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update roles.', 'error')
  }
}

const handleRoleDrawerSubmit = async () => {
  if (!roleForm.value.name?.trim()) {
    toast('Role name is required.', 'error')

    return
  }

  roleDrawerLoading.value = true

  try {
    const current = { ...(jobdomain.value.default_roles || {}) }
    const roleData = {
      name: roleForm.value.name.trim(),
      is_administrative: roleForm.value.is_administrative,
    }

    // Include bundles if any are selected
    if (roleForm.value.bundles.length > 0) {
      roleData.bundles = roleForm.value.bundles
    }

    // Include permissions if any direct permissions (Advanced mode fallback)
    if (roleForm.value.permissions.length > 0) {
      roleData.permissions = roleForm.value.permissions
    }

    if (isRoleEditMode.value) {
      current[editingRoleKey.value] = roleData
    }
    else {
      const key = generateRoleKey(roleForm.value.name)
      if (!key) {
        toast('Could not generate a valid key from the role name.', 'error')

        return
      }
      current[key] = roleData
    }

    await saveDefaultRoles(current)
    isRoleDrawerOpen.value = false
  }
  finally {
    roleDrawerLoading.value = false
  }
}

const deletePresetRole = async role => {
  if (!confirm(`Remove preset role "${role.name}"?`))
    return

  const current = { ...(jobdomain.value.default_roles || {}) }

  delete current[role.key]
  await saveDefaultRoles(current)
}

// ─── Load data ──────────────────────────────────────
onMounted(async () => {
  try {
    const [jdData] = await Promise.all([
      jobdomainsStore.fetchJobdomain(route.params.id),
      settingsStore.fetchModules(),
    ])

    jobdomain.value = jdData.jobdomain
    fieldDefinitions.value = jdData.field_definitions || []
    permissionCatalog.value = jdData.permission_catalog || []
    moduleBundles.value = jdData.module_bundles || []
    resetOverviewForm()
  }
  catch {
    toast('Job domain not found.', 'error')
    await router.push({ name: 'platform-jobdomains' })
  }
  finally {
    isLoading.value = false
  }
})
</script>

<template>
  <div>
    <!-- Loading -->
    <VCard
      v-if="isLoading"
      class="pa-8 text-center"
    >
      <VProgressCircular indeterminate />
    </VCard>

    <template v-else-if="jobdomain">
      <!-- Header -->
      <VCard class="mb-4">
        <VCardText class="d-flex align-center gap-4">
          <VBtn
            icon
            variant="text"
            size="small"
            :to="{ name: 'platform-jobdomains' }"
          >
            <VIcon icon="tabler-arrow-left" />
          </VBtn>

          <div>
            <h5 class="text-h5">
              {{ jobdomain.label }}
            </h5>
            <div class="d-flex align-center gap-2 mt-1">
              <code class="text-body-2">{{ jobdomain.key }}</code>
              <VChip
                v-if="jobdomain.companies_count > 0"
                color="primary"
                size="small"
              >
                {{ jobdomain.companies_count }} {{ jobdomain.companies_count === 1 ? 'company' : 'companies' }}
              </VChip>
              <VChip
                v-else
                color="secondary"
                variant="tonal"
                size="small"
              >
                No companies
              </VChip>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Tabs -->
      <VTabs v-model="activeTab">
        <VTab value="overview">
          <VIcon
            icon="tabler-info-circle"
            class="me-1"
          />
          Overview
        </VTab>
        <VTab value="modules">
          <VIcon
            icon="tabler-puzzle"
            class="me-1"
          />
          Default Modules
        </VTab>
        <VTab value="fields">
          <VIcon
            icon="tabler-forms"
            class="me-1"
          />
          Default Fields
          <VChip
            size="x-small"
            class="ms-2"
          >
            {{ defaultFields.length }}
          </VChip>
        </VTab>
        <VTab value="roles">
          <VIcon
            icon="tabler-shield-lock"
            class="me-1"
          />
          Default Roles
          <VChip
            size="x-small"
            class="ms-2"
          >
            {{ defaultRoles.length }}
          </VChip>
        </VTab>
      </VTabs>

      <VWindow
        v-model="activeTab"
        class="mt-4"
      >
        <!-- ─── Tab 1: Overview ─────────────────────── -->
        <VWindowItem value="overview">
          <VCard>
            <VCardText>
              <VForm @submit.prevent="saveOverview">
                <VRow>
                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      :model-value="jobdomain.key"
                      label="Code"
                      disabled
                      hint="Immutable after creation."
                      persistent-hint
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      v-model="overviewForm.label"
                      label="Name"
                    />
                  </VCol>

                  <VCol cols="12">
                    <AppTextarea
                      v-model="overviewForm.description"
                      label="Description"
                      rows="3"
                    />
                  </VCol>

                  <VCol cols="12">
                    <VSwitch
                      v-model="overviewForm.allowCustomFields"
                      label="Allow companies to create custom fields"
                      hide-details
                      color="primary"
                    />
                  </VCol>

                  <VCol cols="12">
                    <div class="d-flex gap-3">
                      <VBtn
                        type="submit"
                        :loading="isSaving"
                      >
                        Save
                      </VBtn>
                      <VBtn
                        variant="tonal"
                        color="secondary"
                        @click="resetOverviewForm"
                      >
                        Reset
                      </VBtn>
                    </div>
                  </VCol>
                </VRow>
              </VForm>
            </VCardText>

            <VDivider />

            <!-- Delete section -->
            <VCardText>
              <div class="d-flex align-center justify-space-between">
                <div>
                  <div class="text-body-1 font-weight-medium text-error">
                    Delete Job Domain
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    This action is permanent and cannot be undone.
                  </div>
                </div>
                <VBtn
                  color="error"
                  variant="tonal"
                  :disabled="jobdomain.companies_count > 0"
                  @click="isDeleteDialogOpen = true"
                >
                  Delete
                  <VTooltip
                    v-if="jobdomain.companies_count > 0"
                    activator="parent"
                    location="top"
                  >
                    Cannot delete: assigned to {{ jobdomain.companies_count }} company(ies)
                  </VTooltip>
                </VBtn>
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- ─── Tab 2: Default Modules ──────────────── -->
        <VWindowItem value="modules">
          <VCard>
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-puzzle"
                class="me-2"
              />
              Module Configuration
              <VSpacer />
              <VChip
                color="info"
                variant="tonal"
                size="small"
              >
                Preset Only
              </VChip>
            </VCardTitle>

            <VAlert
              type="info"
              variant="tonal"
              class="mx-4 mt-2"
            >
              These presets are applied only when assigning this job domain to a company. Existing companies are not affected.
            </VAlert>

            <!-- Section: Core Modules -->
            <template v-if="coreModules.length">
              <VCardTitle class="text-body-1 mt-4">
                <VIcon
                  icon="tabler-shield-check"
                  size="20"
                  class="me-2"
                  color="primary"
                />
                Core Modules
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Always active for all companies. Cannot be removed.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in coreModules"
                    :key="mod.key"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-high-emphasis text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="primary"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Core
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="true"
                        density="compact"
                        hide-details
                        disabled
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <!-- Section: Included by Default -->
            <template v-if="includedModules.length">
              <VDivider class="my-2" />
              <VCardTitle class="text-body-1">
                <VIcon
                  icon="tabler-check"
                  size="20"
                  class="me-2"
                  color="success"
                />
                Included by Default
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Auto-activated when this job domain is assigned.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in includedModules"
                    :key="mod.key"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-high-emphasis text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="success"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Included
                      </VChip>
                      <VChip
                        v-if="mod.min_plan"
                        color="warning"
                        size="x-small"
                        variant="tonal"
                        class="ms-1"
                      >
                        Requires {{ planLabel(mod.min_plan) }}
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="true"
                        density="compact"
                        hide-details
                        @update:model-value="toggleModule(mod.key, $event)"
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <!-- Section: Compatible (Available to add) -->
            <template v-if="compatibleModules.length">
              <VDivider class="my-2" />
              <VCardTitle class="text-body-1">
                <VIcon
                  icon="tabler-puzzle"
                  size="20"
                  class="me-2"
                  color="info"
                />
                Available Modules
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Compatible with this job domain. Toggle to include by default.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in compatibleModules"
                    :key="mod.key"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-high-emphasis text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        v-if="mod.compatible_jobdomains"
                        color="info"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Marketplace
                      </VChip>
                      <VChip
                        v-if="mod.min_plan"
                        color="warning"
                        size="x-small"
                        variant="tonal"
                        class="ms-1"
                      >
                        Requires {{ planLabel(mod.min_plan) }}
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="false"
                        density="compact"
                        hide-details
                        @update:model-value="toggleModule(mod.key, $event)"
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <!-- Section: Incompatible -->
            <template v-if="incompatibleModules.length">
              <VDivider class="my-2" />
              <VCardTitle class="text-body-1">
                <VIcon
                  icon="tabler-lock"
                  size="20"
                  class="me-2"
                  color="secondary"
                />
                Incompatible Modules
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                Not available for this job domain.
              </VCardSubtitle>
              <VTable class="text-no-wrap">
                <tbody>
                  <tr
                    v-for="mod in incompatibleModules"
                    :key="mod.key"
                    class="text-disabled"
                  >
                    <td class="font-weight-medium">
                      <RouterLink
                        :to="{ name: 'platform-modules-key', params: { key: mod.key } }"
                        class="text-decoration-none"
                      >
                        {{ mod.name }}
                      </RouterLink>
                      <VChip
                        color="secondary"
                        size="x-small"
                        variant="tonal"
                        class="ms-2"
                      >
                        Not available
                      </VChip>
                    </td>
                    <td>
                      {{ mod.description }}
                    </td>
                    <td style="width: 100px;">
                      <VSwitch
                        :model-value="false"
                        density="compact"
                        hide-details
                        disabled
                      />
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </template>

            <VCardText
              v-if="!allModules.length"
              class="text-center text-disabled"
            >
              No modules available.
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- ─── Tab 3: Default Fields ───────────────── -->
        <VWindowItem value="fields">
          <VCard>
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-forms"
                class="me-2"
              />
              Default Fields
              <VSpacer />
              <VChip
                color="info"
                variant="tonal"
                size="small"
              >
                Preset Only
              </VChip>
            </VCardTitle>

            <VAlert
              type="info"
              variant="tonal"
              class="mx-4 mt-2"
            >
              These presets are applied only when assigning this job domain to a company. Existing company activations are never modified.
            </VAlert>

            <!-- Section 1: Preset Fields -->
            <VCardTitle class="text-body-1 mt-2">
              Preset Fields
            </VCardTitle>

            <VTable
              v-if="presetFields.length"
              class="text-no-wrap"
            >
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Scope</th>
                  <th style="width: 120px;">
                    Required
                  </th>
                  <th style="width: 100px;">
                    Order
                  </th>
                  <th style="width: 60px;" />
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="field in presetFields"
                  :key="field.code"
                >
                  <td>
                    <span class="font-weight-medium">{{ field.label }}</span>
                    <VChip
                      v-if="field.is_system"
                      color="warning"
                      variant="tonal"
                      size="x-small"
                      class="ms-2"
                    >
                      system
                    </VChip>
                  </td>
                  <td>
                    <VChip
                      :color="scopeColor(field.scope)"
                      size="small"
                      variant="tonal"
                    >
                      {{ field.scope }}
                    </VChip>
                  </td>
                  <td>
                    <VCheckbox
                      :model-value="field.required"
                      density="compact"
                      hide-details
                      @update:model-value="updateFieldRequired(field.code, $event)"
                    />
                  </td>
                  <td>
                    <AppTextField
                      :model-value="field.order"
                      type="number"
                      density="compact"
                      hide-details
                      style="max-inline-size: 80px;"
                      @change="updateFieldOrder(field.code, $event.target.value)"
                    />
                  </td>
                  <td>
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      color="error"
                      @click="removeField(field.code)"
                    >
                      <VIcon icon="tabler-x" />
                    </VBtn>
                  </td>
                </tr>
              </tbody>
            </VTable>

            <VCardText
              v-else
              class="text-disabled"
            >
              No fields in this preset. Add fields from the list below.
            </VCardText>

            <VDivider class="my-2" />

            <!-- Section 2: Available Fields -->
            <VCardTitle class="text-body-1">
              Available Fields
            </VCardTitle>

            <!-- Company scope -->
            <template v-if="availableCompanyDefs.length">
              <VCardText class="pb-2">
                <VChip
                  color="primary"
                  size="small"
                  class="me-2"
                >
                  company
                </VChip>
              </VCardText>
              <VCardText class="pt-0">
                <div class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="def in availableCompanyDefs"
                    :key="def.id"
                    variant="outlined"
                    color="primary"
                    @click="addField(def.code)"
                  >
                    <VIcon
                      icon="tabler-plus"
                      size="16"
                      start
                    />
                    {{ def.label }}
                  </VChip>
                </div>
              </VCardText>
            </template>

            <!-- Company user scope -->
            <template v-if="availableCompanyUserDefs.length">
              <VCardText class="pb-2">
                <VChip
                  color="warning"
                  size="small"
                  class="me-2"
                >
                  company_user
                </VChip>
              </VCardText>
              <VCardText class="pt-0">
                <div class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="def in availableCompanyUserDefs"
                    :key="def.id"
                    variant="outlined"
                    color="warning"
                    @click="addField(def.code)"
                  >
                    <VIcon
                      icon="tabler-plus"
                      size="16"
                      start
                    />
                    {{ def.label }}
                  </VChip>
                </div>
              </VCardText>
            </template>

            <VCardText
              v-if="!availableCompanyDefs.length && !availableCompanyUserDefs.length"
              class="text-disabled"
            >
              All fields are already in the preset.
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- ─── Tab 4: Default Roles ──────────────────── -->
        <VWindowItem value="roles">
          <VCard>
            <VCardTitle class="d-flex align-center">
              <VIcon
                icon="tabler-shield-lock"
                class="me-2"
              />
              Default Roles
              <VSpacer />
              <VBtn
                size="small"
                prepend-icon="tabler-plus"
                @click="openRoleCreateDrawer"
              >
                Add Role
              </VBtn>
            </VCardTitle>

            <VAlert
              type="info"
              variant="tonal"
              class="mx-4 mt-2"
            >
              These role presets are cloned when assigning this job domain to a company. Existing companies are not affected.
            </VAlert>

            <VTable
              v-if="defaultRoles.length"
              class="text-no-wrap mt-2"
            >
              <thead>
                <tr>
                  <th>Name</th>
                  <th style="width: 140px;">
                    Level
                  </th>
                  <th style="width: 140px;">
                    Capabilities
                  </th>
                  <th style="width: 100px;" />
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="role in defaultRoles"
                  :key="role.key"
                >
                  <td>
                    <span class="font-weight-medium">{{ role.name }}</span>
                  </td>
                  <td>
                    <VChip
                      :color="role.is_administrative ? 'warning' : 'info'"
                      size="small"
                      variant="tonal"
                    >
                      {{ role.is_administrative ? 'Management' : 'Operational' }}
                    </VChip>
                  </td>
                  <td>
                    <div class="d-flex gap-1">
                      <VChip
                        v-if="role.bundles.length > 0"
                        size="small"
                        color="primary"
                        variant="tonal"
                      >
                        {{ role.bundles.length }} {{ role.bundles.length === 1 ? 'capability' : 'capabilities' }}
                      </VChip>
                      <VChip
                        v-if="role.permissions.length > 0"
                        size="small"
                        color="secondary"
                        variant="tonal"
                      >
                        {{ role.permissions.length }} custom
                      </VChip>
                      <VChip
                        v-if="role.bundles.length === 0 && role.permissions.length === 0"
                        size="small"
                        color="default"
                        variant="tonal"
                      >
                        None
                      </VChip>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex gap-1 justify-end">
                      <VBtn
                        icon
                        variant="text"
                        size="small"
                        color="default"
                        @click="openRoleEditDrawer(role)"
                      >
                        <VIcon icon="tabler-pencil" />
                      </VBtn>
                      <VBtn
                        icon
                        variant="text"
                        size="small"
                        color="error"
                        @click="deletePresetRole(role)"
                      >
                        <VIcon icon="tabler-trash" />
                      </VBtn>
                    </div>
                  </td>
                </tr>
              </tbody>
            </VTable>

            <VCardText
              v-else
              class="text-center text-disabled"
            >
              No role presets. Add one to get started.
            </VCardText>
          </VCard>
        </VWindowItem>
      </VWindow>

      <!-- ─── Role Drawer ──────────────────────────────── -->
      <VNavigationDrawer
        v-model="isRoleDrawerOpen"
        temporary
        location="end"
        width="500"
      >
        <AppDrawerHeaderSection
          :title="isRoleEditMode ? 'Edit Role Preset' : 'Add Role Preset'"
          @cancel="isRoleDrawerOpen = false"
        />

        <VDivider />

        <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
          <VCardText>
            <VForm @submit.prevent="handleRoleDrawerSubmit">
              <VRow>
                <VCol cols="12">
                  <AppTextField
                    v-model="roleForm.name"
                    label="Role Name"
                    placeholder="e.g. Dispatcher, Accountant"
                  />
                </VCol>
                <VCol cols="12">
                  <h6 class="text-h6 mb-3">
                    Role Level
                  </h6>
                  <VRadioGroup
                    :model-value="roleForm.is_administrative ? 'management' : 'operational'"
                    @update:model-value="roleForm.is_administrative = $event === 'management'"
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
                      :prepend-icon="isRoleAdvancedMode ? 'tabler-layout-grid' : 'tabler-adjustments'"
                      @click="isRoleAdvancedMode = !isRoleAdvancedMode"
                    >
                      {{ isRoleAdvancedMode ? 'Simple view' : 'Advanced' }}
                    </VBtn>
                  </div>

                  <!-- ═══ SIMPLE MODE: Capability bundles ═══ -->
                  <template v-if="!isRoleAdvancedMode">
                    <!-- Core capabilities -->
                    <template v-if="roleCoreModules.length">
                      <div class="d-flex align-center gap-2 mb-3">
                        <VIcon
                          icon="tabler-building"
                          size="20"
                          color="primary"
                        />
                        <span class="text-body-1 font-weight-medium">Core &mdash; Team &amp; Company</span>
                      </div>

                      <template
                        v-for="mod in roleCoreModules"
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
                            v-for="cap in mod.bundles"
                            :key="cap.key"
                            class="d-flex align-center"
                          >
                            <VCheckbox
                              :model-value="getRoleBundleState(cap) === 'checked'"
                              hide-details
                              density="compact"
                              @update:model-value="toggleRoleBundle(cap)"
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
                              v-if="cap.is_admin"
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
                    <template v-if="roleBusinessModules.length">
                      <VDivider
                        v-if="roleCoreModules.length"
                        class="mb-3"
                      />

                      <template
                        v-for="mod in roleBusinessModules"
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
                            v-for="cap in mod.bundles"
                            :key="cap.key"
                            class="d-flex align-center"
                          >
                            <VCheckbox
                              :model-value="getRoleBundleState(cap) === 'checked'"
                              hide-details
                              density="compact"
                              @update:model-value="toggleRoleBundle(cap)"
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
                              v-if="cap.is_admin"
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

                  <!-- ═══ ADVANCED MODE: Individual permissions ═══ -->
                  <template v-else>
                    <!-- Core section -->
                    <template v-if="hasCorePermGroups">
                      <div class="d-flex align-center gap-2 mb-3">
                        <VIcon
                          icon="tabler-building"
                          size="20"
                          color="primary"
                        />
                        <span class="text-body-1 font-weight-medium">Core &mdash; Team &amp; Company</span>
                      </div>

                      <template
                        v-for="group in rolePermissionGroups.filter(g => g.isCore)"
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
                            :key="perm.key"
                            class="d-flex align-center"
                          >
                            <VCheckbox
                              :model-value="isRolePermChecked(perm.key)"
                              hide-details
                              density="compact"
                              @update:model-value="toggleRolePerm(perm.key)"
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
                    <template v-if="hasModulePermGroups">
                      <VDivider
                        v-if="hasCorePermGroups"
                        class="mb-3"
                      />

                      <template
                        v-for="group in rolePermissionGroups.filter(g => !g.isCore)"
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
                            :key="perm.key"
                            class="d-flex align-center"
                          >
                            <VCheckbox
                              :model-value="isRolePermChecked(perm.key)"
                              hide-details
                              density="compact"
                              @update:model-value="toggleRolePerm(perm.key)"
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
                    :loading="roleDrawerLoading"
                  >
                    {{ isRoleEditMode ? 'Update' : 'Create' }}
                  </VBtn>
                  <VBtn
                    variant="tonal"
                    color="secondary"
                    @click="isRoleDrawerOpen = false"
                  >
                    Cancel
                  </VBtn>
                </VCol>
              </VRow>
            </VForm>
          </VCardText>
        </div>
      </VNavigationDrawer>

      <!-- ─── Delete Confirmation Dialog ──────────────── -->
      <VDialog
        v-model="isDeleteDialogOpen"
        max-width="400"
      >
        <VCard>
          <VCardTitle>Confirm Delete</VCardTitle>
          <VCardText>
            Are you sure you want to delete the job domain
            <strong>{{ jobdomain?.label }}</strong>?
            This action cannot be undone.
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="tonal"
              @click="isDeleteDialogOpen = false"
            >
              Cancel
            </VBtn>
            <VBtn
              color="error"
              @click="handleDelete"
            >
              Delete
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>

