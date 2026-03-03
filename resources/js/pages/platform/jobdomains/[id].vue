<script setup>
import { usePlatformJobdomainsStore } from '@/modules/platform-admin/jobdomains/jobdomains.store'
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import DocumentScopeChip from '@/views/shared/documents/DocumentScopeChip.vue'
import DocumentMandatoryChip from '@/views/shared/documents/DocumentMandatoryChip.vue'
import DocumentConstraintsInline from '@/views/shared/documents/DocumentConstraintsInline.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.jobdomains',
    permission: 'manage_jobdomains',
  },
})

const { t } = useI18n()
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

// ADR-169: mandatory field codes from catalog (read-only)
const mandatoryFieldCodes = ref([])
const mandatoryByRole = ref({})

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
    toast(error?.data?.message || t('platformJobdomains.failedToDelete'), 'error')
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
    toast(error?.data?.message || t('common.operationFailed'), 'error')
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
    toast(error?.data?.message || t('platformJobdomains.failedToUpdateModules'), 'error')
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
    toast(error?.data?.message || t('platformJobdomains.failedToUpdateFields'), 'error')
  }
}

// ADR-169: mandatory status from catalog (read-only)
const isFieldMandatory = code => mandatoryFieldCodes.value.includes(code)

const isFieldMandatoryForRole = (code, roleKey) => {
  const jdMandatory = mandatoryFieldCodes.value.includes(code)
  const roleMandatory = (mandatoryByRole.value[roleKey] || []).includes(code)

  return jdMandatory || roleMandatory
}

const addField = async code => {
  // ADR-169: preset = code + order only (no required)
  const maxOrder = defaultFields.value.reduce((max, f) => Math.max(max, f.order ?? 0), -1)
  const updated = [...defaultFields.value, { code, order: maxOrder + 1 }]

  await savePresetFields(updated)
}

const removeField = async code => {
  const updated = defaultFields.value.filter(f => f.code !== code)

  await savePresetFields(updated)
}

const updateFieldOrder = async (code, order) => {
  const parsed = parseInt(order, 10)
  if (isNaN(parsed) || parsed < 0) return

  const updated = defaultFields.value.map(f => f.code === code ? { ...f, order: parsed } : f)

  await savePresetFields(updated)
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
    fields: def.fields || [],
  }))
})

// Role drawer state
const isRoleDrawerOpen = ref(false)
const isRoleEditMode = ref(false)
const editingRoleKey = ref(null)
const roleForm = ref({ name: '', is_administrative: false, bundles: [], permissions: [], fields: [] })
const roleDrawerLoading = ref(false)
const isRoleAdvancedMode = ref(false)

// Field config drawer state (separate from role drawer)
const isFieldDrawerOpen = ref(false)
const fieldDrawerRoleKey = ref(null)
const fieldDrawerRoleName = ref('')
const fieldDrawerFields = ref([])
const fieldDrawerLoading = ref(false)

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

// ADR-164: Field config helpers for role drawer
const companyUserFieldDefs = computed(() =>
  fieldDefinitions.value.filter(d => d.scope === 'company_user'),
)

const getRoleFieldEntry = code => {
  return roleForm.value.fields.find(f => f.code === code)
}

const isRoleFieldVisible = code => {
  const entry = getRoleFieldEntry(code)

  return entry ? (entry.visible !== false) : true
}

const isRoleFieldRequired = code => {
  const entry = getRoleFieldEntry(code)

  return entry?.required || false
}

const getRoleFieldOrder = code => {
  const entry = getRoleFieldEntry(code)

  return entry?.order ?? ''
}

const getRoleFieldGroup = code => {
  const entry = getRoleFieldEntry(code)

  return entry?.group || ''
}

const updateRoleFieldConfig = (code, prop, value) => {
  const idx = roleForm.value.fields.findIndex(f => f.code === code)

  if (idx === -1) {
    const entry = { code, scope: 'company_user', visible: true, required: false, order: 0 }

    entry[prop] = value
    roleForm.value.fields.push(entry)
  }
  else {
    roleForm.value.fields[idx][prop] = value
  }
}

// ─── Field config drawer helpers ─────────────────────
const getFieldDrawerEntry = code => {
  return fieldDrawerFields.value.find(f => f.code === code)
}

const isFieldDrawerVisible = code => {
  const entry = getFieldDrawerEntry(code)

  return entry ? (entry.visible !== false) : true
}

const isFieldDrawerRequired = code => {
  const entry = getFieldDrawerEntry(code)

  return entry?.required || false
}

const getFieldDrawerOrder = code => {
  const entry = getFieldDrawerEntry(code)

  return entry?.order ?? ''
}

const getFieldDrawerGroup = code => {
  const entry = getFieldDrawerEntry(code)

  return entry?.group || ''
}

const updateFieldDrawerConfig = (code, prop, value) => {
  const idx = fieldDrawerFields.value.findIndex(f => f.code === code)

  if (idx === -1) {
    const entry = { code, scope: 'company_user', visible: true, required: false, order: 0 }

    entry[prop] = value
    fieldDrawerFields.value.push(entry)
  }
  else {
    fieldDrawerFields.value[idx][prop] = value
  }
}

// Group field definitions by their group in the role's field_config
const fieldDrawerGroups = computed(() => {
  const groups = {}
  const ungrouped = []

  for (const def of companyUserFieldDefs.value) {
    const entry = getFieldDrawerEntry(def.code)
    const group = entry?.group || null

    if (group) {
      if (!groups[group])
        groups[group] = []
      groups[group].push(def)
    }
    else {
      ungrouped.push(def)
    }
  }

  // Build ordered array: named groups first (sorted), then ungrouped
  const result = []
  for (const name of Object.keys(groups).sort()) {
    result.push({ name, defs: groups[name] })
  }
  if (ungrouped.length > 0)
    result.push({ name: null, defs: ungrouped })

  return result
})

const openFieldDrawer = role => {
  fieldDrawerRoleKey.value = role.key
  fieldDrawerRoleName.value = role.name
  fieldDrawerFields.value = JSON.parse(JSON.stringify(role.fields || []))
  isFieldDrawerOpen.value = true
}

const handleFieldDrawerSubmit = async () => {
  fieldDrawerLoading.value = true

  try {
    const current = { ...(jobdomain.value.default_roles || {}) }
    const role = current[fieldDrawerRoleKey.value]

    if (!role) return

    role.fields = fieldDrawerFields.value.length > 0 ? fieldDrawerFields.value : []
    current[fieldDrawerRoleKey.value] = role

    await saveDefaultRoles(current)
    isFieldDrawerOpen.value = false
  }
  finally {
    fieldDrawerLoading.value = false
  }
}

const openRoleCreateDrawer = () => {
  isRoleEditMode.value = false
  editingRoleKey.value = null
  roleForm.value = { name: '', is_administrative: false, bundles: [], permissions: [], fields: [] }
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
    fields: JSON.parse(JSON.stringify(role.fields || [])),
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
    toast(error?.data?.message || t('platformJobdomains.failedToUpdateRoles'), 'error')
  }
}

const handleRoleDrawerSubmit = async () => {
  if (!roleForm.value.name?.trim()) {
    toast(t('platformJobdomains.roleNameRequired'), 'error')

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

    // ADR-164: Include field overrides
    if (roleForm.value.fields.length > 0) {
      roleData.fields = roleForm.value.fields
    }

    if (isRoleEditMode.value) {
      current[editingRoleKey.value] = roleData
    }
    else {
      const key = generateRoleKey(roleForm.value.name)
      if (!key) {
        toast(t('platformJobdomains.failedToGenerateKey'), 'error')

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
  if (!confirm(t('platformJobdomains.confirmRemoveRole', { name: role.name })))
    return

  const current = { ...(jobdomain.value.default_roles || {}) }

  delete current[role.key]
  await saveDefaultRoles(current)
}

// ─── Document Presets (ADR-178/179) ─────────────────
const documentPresets = ref([])

// Source of truth = DB (jobdomain.default_documents), not the server-computed is_in_preset
const defaultDocuments = computed(() => jobdomain.value?.default_documents || [])
const presetDocCodes = computed(() => new Set(defaultDocuments.value.map(d => d.code)))

const presetDocuments = computed(() => {
  return documentPresets.value
    .filter(d => presetDocCodes.value.has(d.code))
    .map(d => ({
      ...d,
      preset_order: defaultDocuments.value.find(dd => dd.code === d.code)?.order ?? 0,
    }))
    .sort((a, b) => a.preset_order - b.preset_order)
})

const otherDocuments = computed(() =>
  documentPresets.value.filter(d => !presetDocCodes.value.has(d.code)),
)

const saveDocumentPresets = async newDocs => {
  try {
    const data = await jobdomainsStore.updateJobdomain(jobdomain.value.id, {
      default_documents: newDocs,
    })

    jobdomain.value = data.jobdomain
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformJobdomains.failedToUpdateDocuments'), 'error')
  }
}

const addDocument = async code => {
  const maxOrder = defaultDocuments.value.reduce((max, d) => Math.max(max, d.order ?? 0), -1)
  const newDocs = [...defaultDocuments.value, { code, order: maxOrder + 10 }]

  await saveDocumentPresets(newDocs)
}

const removeDocument = async code => {
  const newDocs = defaultDocuments.value.filter(d => d.code !== code)

  await saveDocumentPresets(newDocs)
}

const updateDocumentOrder = async (code, order) => {
  const parsed = parseInt(order, 10)
  if (isNaN(parsed) || parsed < 0) return

  const newDocs = defaultDocuments.value.map(d => d.code === code ? { ...d, order: parsed } : d)

  await saveDocumentPresets(newDocs)
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
    mandatoryFieldCodes.value = jdData.mandatory_field_codes || []
    mandatoryByRole.value = jdData.mandatory_by_role || {}
    documentPresets.value = jdData.document_presets || []
    resetOverviewForm()
  }
  catch {
    toast(t('platformJobdomains.notFound'), 'error')
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
                {{ t('platformJobdomains.companiesCount', { count: jobdomain.companies_count }, jobdomain.companies_count) }}
              </VChip>
              <VChip
                v-else
                color="secondary"
                variant="tonal"
                size="small"
              >
                {{ t('platformJobdomains.noCompanies') }}
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
          {{ t('platformJobdomains.overview') }}
        </VTab>
        <VTab value="modules">
          <VIcon
            icon="tabler-puzzle"
            class="me-1"
          />
          {{ t('platformJobdomains.defaultModules') }}
        </VTab>
        <VTab value="fields">
          <VIcon
            icon="tabler-forms"
            class="me-1"
          />
          {{ t('platformJobdomains.defaultFields') }}
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
          {{ t('platformJobdomains.defaultRoles') }}
          <VChip
            size="x-small"
            class="ms-2"
          >
            {{ defaultRoles.length }}
          </VChip>
        </VTab>
        <VTab value="documents">
          <VIcon
            icon="tabler-file-text"
            class="me-1"
          />
          {{ t('platformJobdomains.documentPresets') }}
          <VChip
            size="x-small"
            class="ms-2"
          >
            {{ presetDocuments.length }}
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
                      :label="t('platformJobdomains.codeLabel')"
                      disabled
                      :hint="t('platformJobdomains.codeHint')"
                      persistent-hint
                    />
                  </VCol>

                  <VCol
                    cols="12"
                    md="6"
                  >
                    <AppTextField
                      v-model="overviewForm.label"
                      :label="t('common.name')"
                    />
                  </VCol>

                  <VCol cols="12">
                    <AppTextarea
                      v-model="overviewForm.description"
                      :label="t('common.description')"
                      rows="3"
                    />
                  </VCol>

                  <VCol cols="12">
                    <VSwitch
                      v-model="overviewForm.allowCustomFields"
                      :label="t('platformJobdomains.allowCustomFields')"
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
                        {{ t('common.save') }}
                      </VBtn>
                      <VBtn
                        variant="tonal"
                        color="secondary"
                        @click="resetOverviewForm"
                      >
                        {{ t('common.reset') }}
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
                    {{ t('platformJobdomains.deleteJobDomain') }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ t('platformJobdomains.deleteWarning') }}
                  </div>
                </div>
                <VBtn
                  color="error"
                  variant="tonal"
                  :disabled="jobdomain.companies_count > 0"
                  @click="isDeleteDialogOpen = true"
                >
                  {{ t('common.delete') }}
                  <VTooltip
                    v-if="jobdomain.companies_count > 0"
                    activator="parent"
                    location="top"
                  >
                    {{ t('platformJobdomains.cannotDelete', { count: jobdomain.companies_count }) }}
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
              {{ t('platformJobdomains.moduleConfiguration') }}
              <VSpacer />
              <VChip
                color="info"
                variant="tonal"
                size="small"
              >
                {{ t('platformJobdomains.presetOnly') }}
              </VChip>
            </VCardTitle>

            <VAlert
              type="info"
              variant="tonal"
              class="mx-4 mt-2"
            >
              {{ t('platformJobdomains.presetInfo') }}
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
                {{ t('platformJobdomains.coreModules') }}
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                {{ t('platformJobdomains.coreModulesInfo') }}
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
                        {{ t('platformModules.core') }}
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
                {{ t('platformJobdomains.includedByDefault') }}
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                {{ t('platformJobdomains.includedByDefaultInfo') }}
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
                        {{ t('platformJobdomains.included') }}
                      </VChip>
                      <VChip
                        v-if="mod.min_plan"
                        color="warning"
                        size="x-small"
                        variant="tonal"
                        class="ms-1"
                      >
                        {{ t('platformJobdomains.requiresPlan', { plan: planLabel(mod.min_plan) }) }}
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
                {{ t('platformJobdomains.availableModules') }}
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                {{ t('platformJobdomains.availableModulesInfo') }}
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
                        {{ t('platformJobdomains.marketplace') }}
                      </VChip>
                      <VChip
                        v-if="mod.min_plan"
                        color="warning"
                        size="x-small"
                        variant="tonal"
                        class="ms-1"
                      >
                        {{ t('platformJobdomains.requiresPlan', { plan: planLabel(mod.min_plan) }) }}
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
                {{ t('platformJobdomains.incompatibleModules') }}
              </VCardTitle>
              <VCardSubtitle class="text-body-2 mb-2">
                {{ t('platformJobdomains.incompatibleModulesInfo') }}
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
                        {{ t('platformJobdomains.notAvailable') }}
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
              {{ t('platformJobdomains.noModulesAvailable') }}
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
              {{ t('platformJobdomains.defaultFields') }}
              <VSpacer />
              <VChip
                color="info"
                variant="tonal"
                size="small"
              >
                {{ t('platformJobdomains.presetOnly') }}
              </VChip>
            </VCardTitle>

            <VAlert
              type="info"
              variant="tonal"
              class="mx-4 mt-2"
            >
              {{ t('platformJobdomains.fieldsPresetInfo') }}
            </VAlert>

            <!-- ADR-169: mandatory governance alert -->
            <VAlert
              v-if="mandatoryFieldCodes.length > 0"
              type="warning"
              variant="tonal"
              class="mx-4 mt-2"
            >
              {{ t('platformJobdomains.mandatoryGovernanceAlert', { count: mandatoryFieldCodes.length }) }}
            </VAlert>

            <!-- Section 1: Preset Fields -->
            <VCardTitle class="text-body-1 mt-2">
              {{ t('platformJobdomains.presetFields') }}
            </VCardTitle>

            <VTable
              v-if="presetFields.length"
              class="text-no-wrap"
            >
              <thead>
                <tr>
                  <th>{{ t('common.code') }}</th>
                  <th>{{ t('common.scope') }}</th>
                  <th style="width: 140px;">
                    {{ t('platformJobdomains.mandatoryStatus') }}
                  </th>
                  <th style="width: 100px;">
                    {{ t('platformFields.order') }}
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
                      {{ t('common.system') }}
                    </VChip>
                  </td>
                  <td>
                    <DocumentScopeChip :scope="field.scope" />
                  </td>
                  <td>
                    <!-- ADR-169: mandatory from catalog = read-only indicator -->
                    <VChip
                      v-if="isFieldMandatory(field.code)"
                      color="error"
                      size="x-small"
                      variant="tonal"
                    >
                      <VIcon
                        icon="tabler-lock"
                        size="14"
                        start
                      />
                      {{ t('platformJobdomains.mandatory') }}
                      <VTooltip
                        activator="parent"
                        location="top"
                      >
                        {{ t('platformJobdomains.mandatoryTooltip') }}
                      </VTooltip>
                    </VChip>
                    <span
                      v-else
                      class="text-disabled"
                    >—</span>
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
              {{ t('platformJobdomains.noFieldsInPreset') }}
            </VCardText>

            <VDivider class="my-2" />

            <!-- Section 2: Available Fields -->
            <VCardTitle class="text-body-1">
              {{ t('platformJobdomains.availableFields') }}
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
              {{ t('platformJobdomains.allFieldsInPreset') }}
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
              {{ t('platformJobdomains.defaultRoles') }}
              <VSpacer />
              <VBtn
                size="small"
                prepend-icon="tabler-plus"
                @click="openRoleCreateDrawer"
              >
                {{ t('platformJobdomains.addRolePreset') }}
              </VBtn>
            </VCardTitle>

            <VAlert
              type="info"
              variant="tonal"
              class="mx-4 mt-2"
            >
              {{ t('platformJobdomains.rolePresetsInfo') }}
            </VAlert>

            <VTable
              v-if="defaultRoles.length"
              class="text-no-wrap mt-2"
            >
              <thead>
                <tr>
                  <th>{{ t('common.name') }}</th>
                  <th style="width: 140px;">
                    {{ t('common.level') }}
                  </th>
                  <th style="width: 140px;">
                    {{ t('roles.capabilities') }}
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
                      {{ role.is_administrative ? t('common.management') : t('common.operational') }}
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
                        {{ role.bundles.length }} {{ t('platformJobdomains.capability', role.bundles.length) }}
                      </VChip>
                      <VChip
                        v-if="role.permissions.length > 0"
                        size="small"
                        color="secondary"
                        variant="tonal"
                      >
                        {{ t('platformJobdomains.customCount', { count: role.permissions.length }) }}
                      </VChip>
                      <VChip
                        v-if="role.fields.length > 0"
                        size="small"
                        color="success"
                        variant="tonal"
                      >
                        {{ t('platformJobdomains.fieldsCount', { count: role.fields.length }) }}
                      </VChip>
                      <VChip
                        v-if="role.bundles.length === 0 && role.permissions.length === 0 && role.fields.length === 0"
                        size="small"
                        color="default"
                        variant="tonal"
                      >
                        {{ t('platformJobdomains.none') }}
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
                        <VTooltip
                          activator="parent"
                          location="top"
                        >
                          {{ t('roles.capabilities') }}
                        </VTooltip>
                      </VBtn>
                      <VBtn
                        icon
                        variant="text"
                        size="small"
                        color="default"
                        @click="openFieldDrawer(role)"
                      >
                        <VIcon icon="tabler-forms" />
                        <VTooltip
                          activator="parent"
                          location="top"
                        >
                          {{ t('platformJobdomains.fieldOverrides') }}
                        </VTooltip>
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
              {{ t('platformJobdomains.noRolePresets') }}
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- ─── Tab 5: Document Presets (ADR-178) ─────── -->
        <VWindowItem value="documents">
          <VCard>
            <VCardItem>
              <VCardTitle class="d-flex align-center gap-2">
                {{ t('platformJobdomains.documentPresets') }}
                <VChip
                  color="info"
                  variant="tonal"
                  size="small"
                >
                  {{ t('documents.preset') }}
                </VChip>
              </VCardTitle>
            </VCardItem>
            <VCardText>
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-6"
              >
                {{ t('platformJobdomains.documentPresetsInfo') }}
              </VAlert>

              <!-- Activated by Default -->
              <template v-if="presetDocuments.length">
                <h6 class="text-h6 mb-3">
                  {{ t('platformJobdomains.activeDocumentPresets') }}
                </h6>
                <VTable class="text-no-wrap mb-6">
                  <thead>
                    <tr>
                      <th>{{ t('documents.title') }}</th>
                      <th style="width: 100px;">
                        {{ t('common.scope') }}
                      </th>
                      <th style="width: 100px;">
                        {{ t('documents.systemMandatory') }}
                      </th>
                      <th style="width: 100px;">
                        {{ t('platformJobdomains.applicableMarkets') }}
                      </th>
                      <th>{{ t('documents.constraints') }}</th>
                      <th style="width: 100px;">
                        {{ t('platformJobdomains.presetOrder') }}
                      </th>
                      <th style="width: 60px;" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="doc in presetDocuments"
                      :key="doc.code"
                    >
                      <td class="font-weight-medium">
                        {{ t(`documents.type.${doc.code}`, doc.label) }}
                      </td>
                      <td>
                        <DocumentScopeChip :scope="doc.scope" />
                      </td>
                      <td>
                        <DocumentMandatoryChip :mandatory="doc.mandatory_for_jobdomain" />
                      </td>
                      <td>
                        <span v-if="!doc.applicable_markets">{{ t('documents.allMarkets') }}</span>
                        <span v-else>{{ doc.applicable_markets.join(', ') }}</span>
                      </td>
                      <td>
                        <DocumentConstraintsInline
                          :max-file-size-mb="doc.max_file_size_mb"
                          :accepted-types="doc.accepted_types"
                        />
                      </td>
                      <td>
                        <AppTextField
                          :model-value="doc.preset_order"
                          type="number"
                          density="compact"
                          hide-details
                          style="max-inline-size: 80px;"
                          @change="updateDocumentOrder(doc.code, $event.target.value)"
                        />
                      </td>
                      <td>
                        <VBtn
                          icon
                          variant="text"
                          size="small"
                          color="error"
                          @click="removeDocument(doc.code)"
                        >
                          <VIcon icon="tabler-x" />
                        </VBtn>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </template>

              <!-- Available Document Types -->
              <template v-if="otherDocuments.length">
                <VDivider
                  v-if="presetDocuments.length"
                  class="my-4"
                />
                <h6 class="text-h6 mb-3">
                  {{ t('platformJobdomains.otherDocumentTypes') }}
                </h6>
                <VTable class="text-no-wrap">
                  <thead>
                    <tr>
                      <th>{{ t('documents.title') }}</th>
                      <th style="width: 100px;">
                        {{ t('common.scope') }}
                      </th>
                      <th style="width: 100px;">
                        {{ t('platformJobdomains.applicableMarkets') }}
                      </th>
                      <th>{{ t('documents.constraints') }}</th>
                      <th style="width: 60px;" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="doc in otherDocuments"
                      :key="doc.code"
                    >
                      <td>
                        {{ t(`documents.type.${doc.code}`, doc.label) }}
                      </td>
                      <td>
                        <DocumentScopeChip :scope="doc.scope" />
                      </td>
                      <td>
                        <span v-if="!doc.applicable_markets">{{ t('documents.allMarkets') }}</span>
                        <span v-else>{{ doc.applicable_markets.join(', ') }}</span>
                      </td>
                      <td>
                        <DocumentConstraintsInline
                          :max-file-size-mb="doc.max_file_size_mb"
                          :accepted-types="doc.accepted_types"
                        />
                      </td>
                      <td>
                        <VBtn
                          icon
                          variant="text"
                          size="small"
                          color="success"
                          @click="addDocument(doc.code)"
                        >
                          <VIcon icon="tabler-plus" />
                        </VBtn>
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </template>
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
          :title="isRoleEditMode ? t('platformJobdomains.editRolePreset') : t('platformJobdomains.addRolePresetDrawer')"
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
                    :label="t('roles.roleName')"
                    :placeholder="t('roles.roleNamePlaceholder')"
                  />
                </VCol>
                <VCol cols="12">
                  <h6 class="text-h6 mb-3">
                    {{ t('roles.roleLevel') }}
                  </h6>
                  <VRadioGroup
                    :model-value="roleForm.is_administrative ? 'management' : 'operational'"
                    @update:model-value="roleForm.is_administrative = $event === 'management'"
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
                      :prepend-icon="isRoleAdvancedMode ? 'tabler-layout-grid' : 'tabler-adjustments'"
                      @click="isRoleAdvancedMode = !isRoleAdvancedMode"
                    >
                      {{ isRoleAdvancedMode ? t('roles.simpleView') : t('roles.advanced') }}
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
                        <span class="text-body-1 font-weight-medium">{{ t('roles.coreTeamCompany') }}</span>
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
                              {{ t('common.sensitive') }}
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
                              {{ t('common.sensitive') }}
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
                        <span class="text-body-1 font-weight-medium">{{ t('roles.coreTeamCompany') }}</span>
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
                              {{ t('common.sensitive') }}
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
                              {{ t('common.sensitive') }}
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
                    {{ isRoleEditMode ? t('common.update') : t('common.create') }}
                  </VBtn>
                  <VBtn
                    variant="tonal"
                    color="secondary"
                    @click="isRoleDrawerOpen = false"
                  >
                    {{ t('common.cancel') }}
                  </VBtn>
                </VCol>
              </VRow>
            </VForm>
          </VCardText>
        </div>
      </VNavigationDrawer>

      <!-- ─── Field Config Drawer ──────────────────────── -->
      <VNavigationDrawer
        v-model="isFieldDrawerOpen"
        temporary
        location="end"
        width="560"
      >
        <AppDrawerHeaderSection
          :title="`${t('platformJobdomains.fieldOverrides')} — ${fieldDrawerRoleName}`"
          @cancel="isFieldDrawerOpen = false"
        />

        <VDivider />

        <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
          <VCardText>
            <VAlert
              type="info"
              variant="tonal"
              class="mb-4"
            >
              {{ t('platformJobdomains.fieldOverridesInfo') }}
            </VAlert>

            <template v-if="fieldDrawerGroups.length">
              <template
                v-for="group in fieldDrawerGroups"
                :key="group.name || '__ungrouped__'"
              >
                <div class="d-flex align-center gap-2 mt-4 mb-2">
                  <VIcon
                    :icon="group.name ? 'tabler-folder' : 'tabler-list'"
                    size="18"
                    color="primary"
                  />
                  <span class="text-body-1 font-weight-medium text-capitalize">
                    {{ group.name ? t(`fieldGroups.${group.name}`, group.name) : t('platformJobdomains.ungroupedFields') }}
                  </span>
                  <VChip
                    size="x-small"
                    variant="tonal"
                  >
                    {{ group.defs.length }}
                  </VChip>
                </div>

                <VTable
                  density="compact"
                  class="text-no-wrap"
                >
                  <thead>
                    <tr>
                      <th>{{ t('common.name') }}</th>
                      <th style="width: 70px;">
                        {{ t('roles.visible') }}
                      </th>
                      <th style="width: 70px;">
                        {{ t('members.required') }}
                      </th>
                      <th style="width: 70px;">
                        {{ t('roles.order') }}
                      </th>
                      <th style="width: 100px;">
                        {{ t('roles.group') }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="def in group.defs"
                      :key="def.code"
                    >
                      <td class="font-weight-medium">
                        {{ def.label }}
                        <!-- ADR-169: mandatory indicator from catalog -->
                        <VChip
                          v-if="isFieldMandatoryForRole(def.code, fieldDrawerRoleKey)"
                          color="error"
                          size="x-small"
                          variant="tonal"
                          class="ms-1"
                        >
                          <VIcon
                            icon="tabler-lock"
                            size="12"
                          />
                          <VTooltip
                            activator="parent"
                            location="top"
                          >
                            {{ t('platformJobdomains.mandatoryTooltip') }}
                          </VTooltip>
                        </VChip>
                      </td>
                      <td>
                        <VCheckbox
                          :model-value="isFieldDrawerVisible(def.code)"
                          density="compact"
                          hide-details
                          @update:model-value="updateFieldDrawerConfig(def.code, 'visible', $event)"
                        />
                      </td>
                      <td>
                        <VCheckbox
                          :model-value="isFieldMandatoryForRole(def.code, fieldDrawerRoleKey) || isFieldDrawerRequired(def.code)"
                          density="compact"
                          hide-details
                          :disabled="!isFieldDrawerVisible(def.code) || isFieldMandatoryForRole(def.code, fieldDrawerRoleKey)"
                          @update:model-value="updateFieldDrawerConfig(def.code, 'required', $event)"
                        />
                      </td>
                      <td>
                        <AppTextField
                          :model-value="getFieldDrawerOrder(def.code)"
                          type="number"
                          density="compact"
                          hide-details
                          style="max-inline-size: 60px;"
                          @update:model-value="updateFieldDrawerConfig(def.code, 'order', parseInt($event) || 0)"
                        />
                      </td>
                      <td>
                        <AppTextField
                          :model-value="getFieldDrawerGroup(def.code)"
                          density="compact"
                          hide-details
                          style="max-inline-size: 90px;"
                          :placeholder="t('roles.group')"
                          @update:model-value="updateFieldDrawerConfig(def.code, 'group', $event || null)"
                        />
                      </td>
                    </tr>
                  </tbody>
                </VTable>
              </template>
            </template>

            <div
              v-else
              class="text-center text-disabled pa-4"
            >
              {{ t('platformJobdomains.noFieldDefinitions') }}
            </div>

            <div class="d-flex gap-3 mt-6">
              <VBtn
                :loading="fieldDrawerLoading"
                @click="handleFieldDrawerSubmit"
              >
                {{ t('common.save') }}
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                @click="isFieldDrawerOpen = false"
              >
                {{ t('common.cancel') }}
              </VBtn>
            </div>
          </VCardText>
        </div>
      </VNavigationDrawer>

      <!-- ─── Delete Confirmation Dialog ──────────────── -->
      <VDialog
        v-model="isDeleteDialogOpen"
        max-width="400"
      >
        <VCard>
          <VCardTitle>{{ t('platformJobdomains.confirmDeleteTitle') }}</VCardTitle>
          <VCardText>
            {{ t('platformJobdomains.confirmDeleteMessage', { name: jobdomain?.label }) }}
          </VCardText>
          <VCardActions>
            <VSpacer />
            <VBtn
              variant="tonal"
              @click="isDeleteDialogOpen = false"
            >
              {{ t('common.cancel') }}
            </VBtn>
            <VBtn
              color="error"
              @click="handleDelete"
            >
              {{ t('common.delete') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VDialog>
    </template>
  </div>
</template>
