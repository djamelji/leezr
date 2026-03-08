<script setup>
import { usePlatformFieldsStore } from '@/modules/platform-admin/fields/fields.store'
import { usePlatformMarketsStore } from '@/modules/platform-admin/markets/markets.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.fields',
    permission: 'manage_field_definitions',
  },
})

const { t } = useI18n()
const fieldsStore = usePlatformFieldsStore()
const marketsStore = usePlatformMarketsStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const scopeFilter = ref('')

// ─── Definitions state ──────────────────────────────
const isDefDrawerOpen = ref(false)
const isDefEditMode = ref(false)
const editingDef = ref(null)
const defForm = ref({ code: '', scope: 'company', label: '', type: 'string', validation_rules: null, options: null, default_order: 0, translations: {} })
const defLoading = ref(false)

// ─── Languages for translations ──────────────────────
const activeLanguages = computed(() =>
  marketsStore.languages.filter(l => l.is_active).map(l => ({ code: l.code, name: l.name })),
)

const drawerLocale = ref('en')

// ─── Activations state (platform_user scope) ────────
const activeTab = ref('definitions')

const scopeOptions = computed(() => [
  { title: t('platformFields.allScopes'), value: '' },
  { title: t('platformFields.scopePlatformUser'), value: 'platform_user' },
  { title: t('platformFields.scopeCompany'), value: 'company' },
  { title: t('platformFields.scopeCompanyUser'), value: 'company_user' },
])

const typeOptions = computed(() => [
  { title: t('platformFields.typeString'), value: 'string' },
  { title: t('platformFields.typeNumber'), value: 'number' },
  { title: t('platformFields.typeBoolean'), value: 'boolean' },
  { title: t('platformFields.typeDate'), value: 'date' },
  { title: t('platformFields.typeSelect'), value: 'select' },
  { title: t('platformFields.typeJson'), value: 'json' },
])

const defHeaders = computed(() => [
  { title: t('common.code'), key: 'code', width: '160px' },
  { title: t('common.label'), key: 'label' },
  { title: t('common.scope'), key: 'scope', width: '140px' },
  { title: t('common.type'), key: 'type', width: '100px' },
  { title: t('platformFields.order'), key: 'default_order', width: '80px' },
  { title: '', key: 'is_system', width: '60px', sortable: false },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '100px', sortable: false },
])

const filteredDefinitions = computed(() => {
  if (!scopeFilter.value)
    return fieldsStore.fieldDefinitions

  return fieldsStore.fieldDefinitions.filter(d => d.scope === scopeFilter.value)
})

const platformUserDefs = computed(() => {
  return fieldsStore.fieldDefinitions.filter(d => d.scope === 'platform_user')
})

const activationMap = computed(() => {
  const map = {}
  for (const a of fieldsStore.fieldActivations) {
    map[a.field_definition_id] = a
  }

  return map
})

const activeCount = computed(() => {
  return fieldsStore.fieldActivations.filter(a => a.enabled).length
})

onMounted(async () => {
  try {
    await Promise.all([
      fieldsStore.fetchFieldDefinitions(),
      fieldsStore.fetchFieldActivations(),
      marketsStore.fetchLanguages(),
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
  defForm.value = { code: '', scope: 'company', label: '', type: 'string', validation_rules: null, options: null, default_order: 0, translations: {} }
  drawerLocale.value = 'en'
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
    translations: def.translations ? { ...def.translations } : {},
  }
  drawerLocale.value = 'en'
  isDefDrawerOpen.value = true
}

const handleDefSubmit = async () => {
  defLoading.value = true

  // Canonical label = translations.en or form label
  const translations = { ...defForm.value.translations }
  const canonicalLabel = translations.en || defForm.value.label

  try {
    if (isDefEditMode.value) {
      const data = await fieldsStore.updateFieldDefinition(editingDef.value.id, {
        label: canonicalLabel,
        translations,
        validation_rules: defForm.value.validation_rules,
        options: defForm.value.options,
        default_order: Number(defForm.value.default_order) || 0,
      })

      toast(data.message, 'success')
    }
    else {
      const data = await fieldsStore.createFieldDefinition({
        code: defForm.value.code,
        scope: defForm.value.scope,
        label: canonicalLabel,
        translations,
        type: defForm.value.type,
        default_order: Number(defForm.value.default_order) || 0,
      })

      toast(data.message, 'success')
    }
    isDefDrawerOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('common.operationFailed'), 'error')
  }
  finally {
    defLoading.value = false
  }
}

const deleteDef = async def => {
  if (def.is_system) return
  if (!confirm(t('platformFields.confirmDeleteDef', { code: def.code }))) return

  try {
    const data = await fieldsStore.deleteFieldDefinition(def.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformFields.failedToDelete'), 'error')
  }
}

// ─── Activations (platform_user) ────────────────────
const toggleActivation = async (def, enabled) => {
  const existing = activationMap.value[def.id]

  try {
    await fieldsStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled,
      required_override: existing?.required_override || false,
      order: existing?.order || def.default_order || 0,
    })
  }
  catch (error) {
    toast(error?.data?.message || t('platformFields.failedToUpdateActivation'), 'error')
  }
}

const toggleRequired = async (def, required) => {
  const existing = activationMap.value[def.id]

  try {
    await fieldsStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled: existing?.enabled ?? true,
      required_override: required,
      order: existing?.order || def.default_order || 0,
    })
  }
  catch (error) {
    toast(error?.data?.message || t('platformFields.failedToUpdateActivation'), 'error')
  }
}

const updateOrder = async (def, order) => {
  const existing = activationMap.value[def.id]

  try {
    await fieldsStore.upsertFieldActivation({
      field_definition_id: def.id,
      enabled: existing?.enabled ?? true,
      required_override: existing?.required_override || false,
      order: Number(order) || 0,
    })
  }
  catch (error) {
    toast(error?.data?.message || t('platformFields.failedToUpdateOrder'), 'error')
  }
}

const hasIncompleteTranslations = def => {
  if (!activeLanguages.value.length) return false
  const tr = def.translations || {}

  return activeLanguages.value.some(lang => !tr[lang.code])
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
        {{ t('platformFields.fieldDefinitions') }}
      </VTab>
      <VTab value="activations">
        <VIcon
          icon="tabler-toggle-right"
          class="me-1"
        />
        {{ t('platformFields.platformUserActivations') }}
        <VChip
          size="x-small"
          class="ms-2"
          :color="activeCount >= 50 ? 'error' : 'default'"
        >
          {{ t('platformFields.activeCount', { count: activeCount }) }}
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
            {{ t('platformFields.fieldDefinitions') }}
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
              {{ t('platformFields.addDefinition') }}
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

            <template #item.label="{ item }">
              {{ item.label }}
              <VIcon
                v-if="hasIncompleteTranslations(item)"
                icon="tabler-language"
                size="16"
                color="warning"
                class="ms-1"
              />
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
                {{ t('platformFields.noDefinitions') }}
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
            {{ t('platformFields.platformUserActivations') }}
            <VSpacer />
            <VChip
              :color="activeCount >= 50 ? 'error' : 'success'"
              size="small"
            >
              {{ t('platformFields.activeCount', { count: activeCount }) }}
            </VChip>
          </VCardTitle>

          <VCardSubtitle>
            {{ t('platformFields.enableFieldsSubtitle') }}
          </VCardSubtitle>

          <VTable class="text-no-wrap">
            <thead>
              <tr>
                <th>{{ t('common.code') }}</th>
                <th>{{ t('common.label') }}</th>
                <th>{{ t('common.type') }}</th>
                <th style="width: 80px;">
                  {{ t('platformFields.enabled') }}
                </th>
                <th style="width: 80px;">
                  {{ t('platformFields.required') }}
                </th>
                <th style="width: 100px;">
                  {{ t('platformFields.order') }}
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
            {{ t('platformFields.noActivations') }}
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
        :title="isDefEditMode ? t('platformFields.editDefinition') : t('platformFields.newDefinition')"
        @cancel="isDefDrawerOpen = false"
      />

      <VDivider />

      <VCardText>
        <VForm @submit.prevent="handleDefSubmit">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="defForm.code"
                :label="t('common.code')"
                placeholder="field_code"
                :hint="t('platformFields.codeHint')"
                :disabled="isDefEditMode"
              />
            </VCol>

            <VCol cols="6">
              <AppSelect
                v-model="defForm.scope"
                :items="scopeOptions.filter(o => o.value)"
                :label="t('common.scope')"
                :disabled="isDefEditMode"
              />
            </VCol>

            <VCol cols="6">
              <AppSelect
                v-model="defForm.type"
                :items="typeOptions"
                :label="t('common.type')"
                :disabled="isDefEditMode"
              />
            </VCol>

            <VCol
              v-if="activeLanguages.length > 1"
              cols="12"
            >
              <VBtnToggle
                v-model="drawerLocale"
                mandatory
                density="compact"
                color="primary"
                class="mb-2"
              >
                <VBtn
                  v-for="lang in activeLanguages"
                  :key="lang.code"
                  :value="lang.code"
                  size="small"
                >
                  {{ lang.code.toUpperCase() }}
                </VBtn>
              </VBtnToggle>
            </VCol>

            <VCol
              v-for="lang in activeLanguages"
              :key="lang.code"
              v-show="drawerLocale === lang.code"
              cols="12"
            >
              <AppTextField
                v-model="defForm.translations[lang.code]"
                :label="`${t('common.label')} (${lang.code.toUpperCase()})`"
                :placeholder="t('platformFields.fieldLabelPlaceholder')"
              />
            </VCol>

            <VCol
              v-if="!activeLanguages.length"
              cols="12"
            >
              <AppTextField
                v-model="defForm.label"
                :label="t('common.label')"
                :placeholder="t('platformFields.fieldLabelPlaceholder')"
              />
            </VCol>

            <VCol cols="12">
              <AppTextField
                v-model="defForm.default_order"
                :label="t('platformFields.defaultOrder')"
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
                {{ isDefEditMode ? t('common.update') : t('common.create') }}
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                @click="isDefDrawerOpen = false"
              >
                {{ t('common.cancel') }}
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VNavigationDrawer>
  </div>
</template>
