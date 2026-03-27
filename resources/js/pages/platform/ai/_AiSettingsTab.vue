<script setup>
import { usePlatformAiStore } from '@/modules/platform-admin/ai/ai.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformAiStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const form = ref({
  driver: 'null',
  timeout: 60,
})

const driverOptions = [
  { title: 'Null (disabled)', value: 'null' },
  { title: 'Ollama (self-hosted)', value: 'ollama' },
  { title: 'OpenAI (API)', value: 'openai' },
  { title: 'Anthropic (API)', value: 'anthropic' },
]

const defaults = ref({})

const load = async () => {
  isLoading.value = true
  try {
    await store.fetchConfig()
    form.value.driver = store.config?.driver || 'null'
    form.value.timeout = store.config?.timeout || 60
    defaults.value = store.configDefaults || {}
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const save = async () => {
  isSaving.value = true
  try {
    await store.updateConfig(form.value)
    toast(t('platformAi.configSaved'), 'success')
  }
  catch {
    toast(t('common.error'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <VCard :loading="isLoading">
    <VCardTitle class="d-flex align-center pa-4">
      <VIcon
        start
        icon="tabler-settings"
      />
      {{ t('platformAi.tabs.settings') }}
    </VCardTitle>

    <VCardText v-if="!isLoading">
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <AppSelect
            v-model="form.driver"
            :items="driverOptions"
            :label="t('platformAi.settings.driver')"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <AppTextField
            v-model.number="form.timeout"
            type="number"
            :label="t('platformAi.settings.timeout')"
            :hint="t('platformAi.settings.timeoutHint')"
            persistent-hint
            :min="5"
            :max="300"
          />
        </VCol>
      </VRow>

      <VAlert
        type="info"
        variant="tonal"
        density="compact"
        class="mt-4"
      >
        {{ t('platformAi.settings.featureGatingHint') }}
      </VAlert>
    </VCardText>

    <VDivider />

    <VCardActions class="pa-4">
      <VSpacer />
      <VBtn
        color="primary"
        :loading="isSaving"
        @click="save"
      >
        {{ t('common.save') }}
      </VBtn>
    </VCardActions>
  </VCard>
</template>
