<script setup>
import { usePlatformStore } from '@/core/stores/platform'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_field_definitions',
  },
})

const platformStore = usePlatformStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const scopeFilter = ref('')

// ─── Definitions state ──────────────────────────────
const isDefDrawerOpen = ref(false)
const isDefEditMode = ref(false)
const editingDef = ref(null)
const defForm = ref({ code: '', scope: 'company', label: '', type: 'string', validation_rules: null, options: null, default_order: 0 })
const defLoading = ref(false)

// ─── Activations state (platform_user scope) ────────
const activeTab = ref('definitions')

const scopeOptions = [
  { title: 'All scopes', value: '' },
  { title: 'Platform User', value: 'platform_user' },
  { title: 'Company', value: 'company' },
  { title: 'Company User', value: 'company_user' },
]

const typeOptions = [
  { title: 'String', value: 'string' },
  { title: 'Number', value: 'number' },
  { title: 'Boolean', value: 'boolean' },
  { title: 'Date', value: 'date' },
  { title: 'Select', value: 'select' },
  { title: 'JSON', value: 'json' },
]

const defHeaders = [
  { title: 'Code', key: 'code', width: '160px' },
  { title: 'Label', key: 'label' },
  { title: 'Scope', key: 'scope', width: '140px' },
  { title: 'Type', key: 'type', width: '100px' },
  { title: 'Order', key: 'default_order', width: '80px' },
  { title: '', key: 'is_system', width: '60px', sortable: false },
  { title: 'Actions', key: 'actions', align: 'center', width: '100px', sortable: false },
]

const filteredDefinitions = computed(() => {
  if (!scopeFilter.value)
    return platformStore.fieldDefinitions

  return platformStore.fieldDefinitions.filter(d => d.scope === scopeFilter.value)
})

const platformUserDefs = computed(() => {
  return platformStore.fieldDefinitions.filter(d => d.scope === 'platform_user')
})

const activationMap = computed(() => {
  const map = {}
  for (const a of platformStore.fieldActivations) {
    map[a.field_definition_id] = a
  }

  return map
})

const activeCount = computed(() => {
  return platformStore.fieldActivations.filter(a => a.enabled).length
})

onMounted(async () => {
  try {
    await Promise.all([
      platformStore.fetchFieldDefinitions(),
      platformStore.fetchFieldActivations(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

// ─── Definitions CRUD ───────────────────────────────
const openCreateDefDrawer = () => {
  isDefEditMode.value = false
  editingDef.value = null
  defForm.value = { code: '', scope: 'company', label: '', type: 'string', validation_rules: null, options: null, default_order: 0 }
  isDefDrawerOpen.value = true
}

const openEditDefDrawer = def => {
  isDefEditMode.value = true
  editingDef.value = def
  defForm.value = {
    code: def.code,
    scope: def.scope,
    label: def.label,
    type: def.type,
    validation_rules: def.validation_rules,
    options: def.options,
    default_order: def.default_order,
  }
  isDefDrawerOpen.value = true
}

const handleDefSubmit = async () => {
  defLoading.value = true

  try {
    if (isDefEditMode.value) {
      const data = await platformStore.updateFieldDefinition(editingDef.value.id, {
        label: defForm.value.label,
        validation_rules: defForm.value.validation_rules,
        options: defForm.value.options,
        default_order: Number(defForm.value.default_order) || 0,
      })

      toast(data.message, 'success')
    }
    else {
      const data = await platformStore.createFieldDefinition({
        code: defForm.value.code,
        scope: defForm.value.scope,
        label: defForm.value.label,
        type: defForm.value.type,
        default_order: Number(defForm.value.default_order) || 0,
      })

      toast(data.message, 'success')
    }
    isDefDrawerOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || 'Operation failed.', 'error')
  }
  finally {
    defLoading.value = false
  }
}

const deleteDef = async def => {
  if (def.is_system) return
  if (!confirm(`Delete field definition "${def.code}"?`)) return

  try {
    const data = await platformStore.deleteFieldDefinition(def.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to delete.', 'error')
  }
}

// ─── Activations (platform_user) ────────────────────
const toggleActivation = async (def, enabled) => {
  const existing = activationMap.value[def.id]

  try {
    await platformStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled,
      required_override: existing?.required_override || false,
      order: existing?.order || def.default_order || 0,
    })
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update activation.', 'error')
  }
}

const toggleRequired = async (def, required) => {
  const existing = activationMap.value[def.id]

  try {
    await platformStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled: existing?.enabled ?? true,
      required_override: required,
      order: existing?.order || def.default_order || 0,
    })
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update.', 'error')
  }
}

const updateOrder = async (def, order) => {
  const existing = activationMap.value[def.id]

  try {
    await platformStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled: existing?.enabled ?? true,
      required_override: existing?.required_override || false,
      order: Number(order) || 0,
    })
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to update order.', 'error')
  }
}

const scopeColor = scope => {
  const colors = {
    platform_user: 'info',
    company: 'primary',
    company_user: 'warning',
  }

  return colors[scope] || 'secondary'
}
</script>

<template>
  <div>
    <VTabs v-model="activeTab">
      <VTab value="definitions">
        <VIcon
          icon="tabler-list"
          class="me-1"
        />
        Field Definitions
      </VTab>
      <VTab value="activations">
        <VIcon
          icon="tabler-toggle-right"
          class="me-1"
        />
        Platform User Activations
        <VChip
          size="x-small"
          class="ms-2"
          :color="activeCount >= 50 ? 'error' : 'default'"
        >
          {{ activeCount }} / 50
        </VChip>
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-4"
    >
      <!-- ─── Tab: Definitions ─────────────────────────── -->
      <VWindowItem value="definitions">
        <VCard>
          <VCardTitle class="d-flex align-center">
            <VIcon
              icon="tabler-forms"
              class="me-2"
            />
            Field Definitions
            <VSpacer />
            <AppSelect
              v-model="scopeFilter"
              :items="scopeOptions"
              density="compact"
              style="max-inline-size: 200px;"
              class="me-3"
            />
            <VBtn
              size="small"
              prepend-icon="tabler-plus"
              @click="openCreateDefDrawer"
            >
              Add Definition
            </VBtn>
          </VCardTitle>

          <VDataTable
            :headers="defHeaders"
            :items="filteredDefinitions"
            :loading="isLoading"
            :items-per-page="-1"
            hide-default-footer
          >
            <template #item.code="{ item }">
              <code class="text-body-2">{{ item.code }}</code>
            </template>

            <template #item.scope="{ item }">
              <VChip
                :color="scopeColor(item.scope)"
                size="small"
              >
                {{ item.scope }}
              </VChip>
            </template>

            <template #item.type="{ item }">
              <span class="text-body-2 text-capitalize">{{ item.type }}</span>
            </template>

            <template #item.is_system="{ item }">
              <VIcon
                v-if="item.is_system"
                icon="tabler-lock"
                size="18"
                color="warning"
                class="cursor-default"
              />
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex gap-1 justify-center">
                <VBtn
                  icon
                  variant="text"
                  size="small"
                  @click="openEditDefDrawer(item)"
                >
                  <VIcon icon="tabler-pencil" />
                </VBtn>
                <VBtn
                  v-if="!item.is_system"
                  icon
                  variant="text"
                  size="small"
                  color="error"
                  @click="deleteDef(item)"
                >
                  <VIcon icon="tabler-trash" />
                </VBtn>
              </div>
            </template>

            <template #no-data>
              <div class="text-center pa-4 text-disabled">
                No field definitions found.
              </div>
            </template>
          </VDataTable>
        </VCard>
      </VWindowItem>

      <!-- ─── Tab: Platform User Activations ──────────── -->
      <VWindowItem value="activations">
        <VCard>
          <VCardTitle class="d-flex align-center">
            <VIcon
              icon="tabler-toggle-right"
              class="me-2"
            />
            Platform User Field Activations
            <VSpacer />
            <VChip
              :color="activeCount >= 50 ? 'error' : 'success'"
              size="small"
            >
              {{ activeCount }} / 50 active
            </VChip>
          </VCardTitle>

          <VCardSubtitle>
            Enable fields for all platform users. Changes apply globally.
          </VCardSubtitle>

          <VTable class="text-no-wrap">
            <thead>
              <tr>
                <th>Code</th>
                <th>Label</th>
                <th>Type</th>
                <th style="width: 80px;">
                  Enabled
                </th>
                <th style="width: 80px;">
                  Required
                </th>
                <th style="width: 100px;">
                  Order
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="def in platformUserDefs"
                :key="def.id"
              >
                <td>
                  <code class="text-body-2">{{ def.code }}</code>
                  <VIcon
                    v-if="def.is_system"
                    icon="tabler-lock"
                    size="14"
                    color="warning"
                    class="ms-1"
                  />
                </td>
                <td>{{ def.label }}</td>
                <td class="text-capitalize">
                  {{ def.type }}
                </td>
                <td>
                  <VSwitch
                    :model-value="!!activationMap[def.id]?.enabled"
                    density="compact"
                    hide-details
                    :disabled="activeCount >= 50 && !activationMap[def.id]?.enabled"
                    @update:model-value="toggleActivation(def, $event)"
                  />
                </td>
                <td>
                  <VCheckbox
                    :model-value="!!activationMap[def.id]?.required_override"
                    density="compact"
                    hide-details
                    :disabled="!activationMap[def.id]?.enabled"
                    @update:model-value="toggleRequired(def, $event)"
                  />
                </td>
                <td>
                  <AppTextField
                    :model-value="activationMap[def.id]?.order ?? def.default_order ?? 0"
                    type="number"
                    density="compact"
                    hide-details
                    style="max-inline-size: 80px;"
                    :disabled="!activationMap[def.id]?.enabled"
                    @change="updateOrder(def, $event.target.value)"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>

          <VCardText
            v-if="!platformUserDefs.length && !isLoading"
            class="text-center text-disabled"
          >
            No platform_user scope definitions found.
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>

    <!-- ─── Create/Edit Definition Drawer ────────────── -->
    <VNavigationDrawer
      v-model="isDefDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <AppDrawerHeaderSection
        :title="isDefEditMode ? 'Edit Field Definition' : 'New Field Definition'"
        @cancel="isDefDrawerOpen = false"
      />

      <VDivider />

      <VCardText>
        <VForm @submit.prevent="handleDefSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="defForm.code"
                label="Code"
                placeholder="field_code"
                hint="Lowercase, underscores only. Immutable after creation."
                :disabled="isDefEditMode"
              />
            </VCol>

            <VCol cols="6">
              <AppSelect
                v-model="defForm.scope"
                :items="scopeOptions.filter(o => o.value)"
                label="Scope"
                :disabled="isDefEditMode"
              />
            </VCol>

            <VCol cols="6">
              <AppSelect
                v-model="defForm.type"
                :items="typeOptions"
                label="Type"
                :disabled="isDefEditMode"
              />
            </VCol>

            <VCol cols="12">
              <AppTextField
                v-model="defForm.label"
                label="Label"
                placeholder="Field Label"
              />
            </VCol>

            <VCol cols="12">
              <AppTextField
                v-model="defForm.default_order"
                label="Default Order"
                type="number"
                placeholder="0"
              />
            </VCol>

            <VCol cols="12">
              <VBtn
                type="submit"
                class="me-3"
                :loading="defLoading"
              >
                {{ isDefEditMode ? 'Update' : 'Create' }}
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                @click="isDefDrawerOpen = false"
              >
                Cancel
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VNavigationDrawer>
  </div>
</template>
