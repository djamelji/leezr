<script setup>
definePage({ meta: { surface: 'structure' } })

import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'

const route = useRoute()
const router = useRouter()
const moduleStore = useModuleStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

// Module info from catalog
const mod = computed(() =>
  moduleStore.modules.find(m => m.key === route.params.key),
)

// Settings
const settings = ref({})
const settingsJson = ref('')
const jsonError = ref('')

onMounted(async () => {
  try {
    // Ensure modules are loaded
    if (!moduleStore.modules.length) {
      await moduleStore.fetchModules()
    }

    if (!mod.value) {
      toast('Module not found.', 'error')
      router.push({ name: 'company-modules' })

      return
    }

    if (!mod.value.is_active) {
      toast('Module is not active.', 'error')
      router.push({ name: 'company-modules' })

      return
    }

    const data = await moduleStore.fetchModuleSettings(route.params.key)

    settings.value = data.settings || {}
    settingsJson.value = JSON.stringify(settings.value, null, 2)
  }
  catch {
    toast('Failed to load module settings.', 'error')
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
    toast(error?.data?.message || 'Failed to save settings.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

const resetSettings = () => {
  settingsJson.value = JSON.stringify(settings.value, null, 2)
  jsonError.value = ''
}

const entitlementLabel = () => {
  if (!mod.value) return ''
  if (mod.value.type === 'core') return 'Core — always active'
  if (mod.value.entitlement_source === 'jobdomain') return 'Included via job domain'
  if (!mod.value.is_entitled) {
    if (mod.value.entitlement_reason === 'plan_required') return `Requires ${mod.value.min_plan} plan`

    return 'Not entitled'
  }

  return 'Entitled'
}
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
            <VIcon icon="tabler-puzzle" />
          </VAvatar>

          <div>
            <h5 class="text-h5">
              {{ mod.name }}
            </h5>
            <div class="d-flex align-center gap-2 mt-1">
              <VChip
                :color="mod.is_active ? 'success' : 'error'"
                size="x-small"
              >
                {{ mod.is_active ? 'Active' : 'Inactive' }}
              </VChip>
              <VChip
                :color="mod.type === 'core' ? 'primary' : 'info'"
                size="x-small"
              >
                {{ mod.type === 'core' ? 'Core' : 'Addon' }}
              </VChip>
              <span class="text-body-2 text-medium-emphasis">{{ entitlementLabel() }}</span>
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
          Overview
        </VCardTitle>
        <VCardText>
          <p class="text-body-1">
            {{ mod.description }}
          </p>
        </VCardText>
      </VCard>

      <!-- Settings -->
      <VCard>
        <VCardTitle>
          <VIcon
            icon="tabler-settings"
            class="me-2"
          />
          Settings
        </VCardTitle>
        <VCardSubtitle>
          Module configuration (JSON). Changes are saved per-company.
        </VCardSubtitle>
        <VCardText>
          <VForm @submit.prevent="saveSettings">
            <AppTextarea
              v-model="settingsJson"
              label="Configuration (JSON)"
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
                Save Settings
              </VBtn>
              <VBtn
                variant="tonal"
                color="secondary"
                @click="resetSettings"
              >
                Reset
              </VBtn>
            </div>
          </VForm>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
