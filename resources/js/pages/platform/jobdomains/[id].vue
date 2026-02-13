<script setup>
import { usePlatformStore } from '@/core/stores/platform'
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
const platformStore = usePlatformStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)
const activeTab = ref('overview')

// ─── Jobdomain state ───────────────────────────────
const jobdomain = ref(null)
const fieldDefinitions = ref([])

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
    const data = await platformStore.deleteJobdomain(jobdomain.value.id)

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
    const data = await platformStore.updateJobdomain(jobdomain.value.id, {
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
const allModules = computed(() => platformStore.modules)

const isModuleSelected = moduleKey => {
  return (jobdomain.value?.default_modules || []).includes(moduleKey)
}

const toggleModule = async (moduleKey, enabled) => {
  if (!jobdomain.value) return

  const current = [...(jobdomain.value.default_modules || [])]
  const updated = enabled
    ? [...current, moduleKey]
    : current.filter(k => k !== moduleKey)

  try {
    const data = await platformStore.updateJobdomain(jobdomain.value.id, {
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
    const data = await platformStore.updateJobdomain(jobdomain.value.id, {
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

// ─── Load data ──────────────────────────────────────
onMounted(async () => {
  try {
    const [jdData] = await Promise.all([
      platformStore.fetchJobdomain(route.params.id),
      platformStore.fetchModules(),
    ])

    jobdomain.value = jdData.jobdomain
    fieldDefinitions.value = jdData.field_definitions || []
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
              Default Modules
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

            <VTable
              v-if="allModules.length"
              class="text-no-wrap mt-2"
            >
              <thead>
                <tr>
                  <th>Module</th>
                  <th>Description</th>
                  <th style="width: 100px;">
                    Default
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="mod in allModules"
                  :key="mod.key"
                >
                  <td class="font-weight-medium">
                    {{ mod.name }}
                  </td>
                  <td class="text-medium-emphasis">
                    {{ mod.description }}
                  </td>
                  <td>
                    <VSwitch
                      :model-value="isModuleSelected(mod.key)"
                      density="compact"
                      hide-details
                      @update:model-value="toggleModule(mod.key, $event)"
                    />
                  </td>
                </tr>
              </tbody>
            </VTable>

            <VCardText
              v-else
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
      </VWindow>

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
