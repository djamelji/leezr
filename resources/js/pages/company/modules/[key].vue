<script setup>
definePage({ meta: { surface: 'structure', module: 'core.modules' } })

import { defineAsyncComponent } from 'vue'
import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'
import { resolveSettingsPanel } from '@/core/registries/settingsPanelRegistry'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const moduleStore = useModuleStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const canManage = computed(() => auth.roleLevel === 'management')

// Module info from catalog
const mod = computed(() =>
  moduleStore.modules.find(m => m.key === route.params.key),
)

// Settings panels from capabilities (dynamic, zero per-module branching)
const settingsPanels = computed(() => {
  const panels = mod.value?.capabilities?.settings_panels
  if (!panels?.length) return []

  return panels
    .slice()
    .sort((a, b) => (a.sortOrder ?? 0) - (b.sortOrder ?? 0))
    .map(panel => {
      const loader = resolveSettingsPanel(panel.component)

      return {
        ...panel,
        resolved: loader ? defineAsyncComponent(loader) : null,
      }
    })
    .filter(p => p.resolved)
})

// Settings (JSON)
const settings = ref({})
const settingsJson = ref('')
const jsonError = ref('')

// ADR-168b: mandatory fields info
const mandatoryFields = ref([])
const incompleteProfilesCount = ref(0)

onMounted(async () => {
  try {
    // Ensure modules are loaded
    if (!moduleStore.modules.length) {
      await moduleStore.fetchModules()
    }

    if (!mod.value) {
      toast(t('companyModules.moduleNotFound'), 'error')
      router.push({ name: 'company-modules' })

      return
    }

    // ADR-163: Only included/active modules can be viewed
    const state = mod.value.display_state
    if (state !== 'included' && state !== 'active') {
      toast(t('companyModules.moduleNotActive'), 'error')
      router.push({ name: 'company-modules' })

      return
    }

    const data = await moduleStore.fetchModuleSettings(route.params.key)

    settings.value = data.settings || {}
    settingsJson.value = JSON.stringify(settings.value, null, 2)
    mandatoryFields.value = data.mandatory_fields || []
    incompleteProfilesCount.value = data.incomplete_profiles_count || 0
  }
  catch {
    toast(t('companyModules.failedToLoadSettings'), 'error')
    router.push({ name: 'company-modules' })
  }
  finally {
    isLoading.value = false
  }
})

const validateJson = () => {
  jsonError.value = ''

  try {
    JSON.parse(settingsJson.value || '{}')

    return true
  }
  catch (e) {
    jsonError.value = `Invalid JSON: ${e.message}`

    return false
  }
}

const saveSettings = async () => {
  if (!validateJson()) return

  isSaving.value = true

  try {
    const parsed = JSON.parse(settingsJson.value || '{}')
    const data = await moduleStore.updateModuleSettings(route.params.key, parsed)

    settings.value = data.settings
    settingsJson.value = JSON.stringify(data.settings, null, 2)
    jsonError.value = ''
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companyModules.failedToSaveSettings'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetSettings = () => {
  settingsJson.value = JSON.stringify(settings.value, null, 2)
  jsonError.value = ''
}

const categoryChipColor = computed(() =>
  mod.value?.type === 'core' ? 'primary' : 'info',
)

// ADR-163: Display state chip
const displayStateChip = computed(() => {
  if (!mod.value) return { text: '', color: 'secondary' }
  const map = {
    included: { text: t('companyModules.stateIncluded'), color: 'primary' },
    active: { text: t('companyModules.stateActive'), color: 'success' },
  }

  return map[mod.value.display_state] || { text: mod.value.display_state, color: 'secondary' }
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

    <template v-else-if="mod">
      <!-- Header -->
      <VCard class="mb-4">
        <VCardText class="d-flex align-center gap-4">
          <VBtn
            icon
            variant="text"
            size="small"
            :to="{ name: 'company-modules' }"
          >
            <VIcon icon="tabler-arrow-left" />
          </VBtn>

          <VAvatar
            size="48"
            :color="mod.type === 'core' ? 'primary' : 'info'"
            variant="tonal"
          >
            <VIcon :icon="mod.icon_name || 'tabler-puzzle'" />
          </VAvatar>

          <div>
            <h5 class="text-h5">
              {{ mod.name }}
            </h5>
            <div class="d-flex align-center gap-2 mt-1">
              <VChip
                :color="displayStateChip.color"
                size="x-small"
              >
                {{ displayStateChip.text }}
              </VChip>
              <VChip
                :color="categoryChipColor"
                size="x-small"
                variant="outlined"
              >
                {{ mod.type === 'core' ? t('companyModules.coreChip') : t('companyModules.addon') }}
              </VChip>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Overview -->
      <VCard class="mb-4">
        <VCardTitle>
          <VIcon
            icon="tabler-info-circle"
            class="me-2"
          />
          {{ t('companyModules.overview') }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-1">
            {{ mod.description }}
          </p>
        </VCardText>
      </VCard>

      <!-- ADR-168b: mandatory fields info -->
      <VCard
        v-if="mandatoryFields.length"
        class="mb-4"
      >
        <VCardTitle>
          <VIcon
            icon="tabler-list-check"
            class="me-2"
          />
          {{ t('companyModules.mandatoryFields') }}
        </VCardTitle>
        <VCardSubtitle>{{ t('companyModules.mandatoryFieldsHint') }}</VCardSubtitle>
        <VCardText>
          <div class="d-flex flex-wrap gap-2 mb-4">
            <VChip
              v-for="field in mandatoryFields"
              :key="field.code"
              color="error"
              variant="tonal"
              size="small"
            >
              {{ field.label }}
            </VChip>
          </div>

          <VAlert
            v-if="incompleteProfilesCount > 0"
            type="warning"
            variant="tonal"
          >
            {{ t('companyModules.incompleteProfilesAlert', { count: incompleteProfilesCount }) }}
          </VAlert>
        </VCardText>
      </VCard>

      <!-- Capability-driven settings panels -->
      <component
        :is="panel.resolved"
        v-for="panel in settingsPanels"
        :key="panel.key"
        class="mb-4"
      />

      <!-- No configurable settings -->
      <VCard
        v-if="!settingsPanels.length"
        class="mb-4"
      >
        <VCardText class="text-center pa-8 text-medium-emphasis">
          <VIcon
            icon="tabler-settings-off"
            size="48"
            class="mb-4 d-block mx-auto"
          />
          <p class="text-body-1">
            {{ t('companyModules.noSettings') }}
          </p>
        </VCardText>
      </VCard>

      <!-- Advanced Settings (JSON) — collapsed, permission-gated -->
      <VExpansionPanels
        v-if="canManage"
        class="mb-4"
      >
        <VExpansionPanel>
          <VExpansionPanelTitle>
            <VIcon
              icon="tabler-code"
              class="me-2"
            />
            {{ t('companyModules.advancedSettings') }}
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('companyModules.advancedSettingsHint') }}
            </p>
            <VForm @submit.prevent="saveSettings">
              <AppTextarea
                v-model="settingsJson"
                :label="t('companyModules.configurationJson')"
                rows="10"
                :error-messages="jsonError ? [jsonError] : []"
                style="font-family: monospace;"
                @input="jsonError = ''"
              />

              <div class="d-flex gap-2 mt-4">
                <VBtn
                  type="submit"
                  :loading="isSaving"
                >
                  {{ t('companyModules.saveSettings') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="resetSettings"
                >
                  {{ t('common.reset') }}
                </VBtn>
              </div>
            </VForm>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </template>
  </div>
</template>
