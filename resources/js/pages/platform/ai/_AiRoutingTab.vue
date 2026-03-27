<script setup>
import { usePlatformAiStore } from '@/modules/platform-admin/ai/ai.store'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = usePlatformAiStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)

const routing = ref({
  vision: '',
  completion: '',
  text_extraction: '',
})

const capabilities = [
  {
    key: 'vision',
    label: 'platformAi.capability.vision',
    icon: 'tabler-eye',
    description: 'platformAi.capabilityDesc.vision',
  },
  {
    key: 'completion',
    label: 'platformAi.capability.completion',
    icon: 'tabler-message-chatbot',
    description: 'platformAi.capabilityDesc.completion',
  },
  {
    key: 'text_extraction',
    label: 'platformAi.capability.textExtraction',
    icon: 'tabler-text-recognition',
    description: 'platformAi.capabilityDesc.textExtraction',
  },
]

const providerOptions = computed(() => {
  const opts = [{ title: t('common.none'), value: '' }]

  for (const p of store.availableProviders) {
    if (p.is_active) {
      opts.push({ title: p.name, value: p.key })
    }
  }

  return opts
})

const load = async () => {
  isLoading.value = true
  try {
    await store.fetchRouting()
    routing.value = {
      vision: store.routing?.vision || '',
      completion: store.routing?.completion || '',
      text_extraction: store.routing?.text_extraction || '',
    }
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
    await store.updateRouting(routing.value)
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
        icon="tabler-route"
      />
      {{ t('platformAi.routing.title') }}
    </VCardTitle>

    <VCardText>
      <p class="text-body-2 text-medium-emphasis mb-6">
        {{ t('platformAi.routing.description') }}
      </p>
    </VCardText>

    <VCardText v-if="!isLoading">
      <VList class="card-list">
        <VListItem
          v-for="cap in capabilities"
          :key="cap.key"
          class="mb-4"
        >
          <template #prepend>
            <VAvatar
              size="40"
              variant="tonal"
              color="primary"
              rounded
            >
              <VIcon
                :icon="cap.icon"
                size="22"
              />
            </VAvatar>
          </template>

          <VListItemTitle class="font-weight-medium">
            {{ t(cap.label) }}
          </VListItemTitle>
          <VListItemSubtitle>
            {{ t(cap.description) }}
          </VListItemSubtitle>

          <template #append>
            <div style="min-inline-size: 200px;">
              <AppSelect
                v-model="routing[cap.key]"
                :items="providerOptions"
                :label="t('platformAi.routing.provider')"
                density="compact"
              />
            </div>
          </template>
        </VListItem>
      </VList>
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
