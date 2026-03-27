<script setup>
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'
import { usePlatformAiStore } from '@/modules/platform-admin/ai/ai.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  isDrawerOpen: {
    type: Boolean,
    required: true,
  },
  provider: {
    type: Object,
    default: () => null,
  },
})

const emit = defineEmits([
  'update:isDrawerOpen',
  'saved',
])

const { t } = useI18n()
const store = usePlatformAiStore()
const { toast } = useAppToast()

const isSaving = ref(false)
const credentials = ref({})

// Initialize form when provider changes
watch(() => props.provider, provider => {
  if (provider) {
    const creds = {}
    for (const field of provider.credential_fields || []) {
      creds[field.key] = provider.credentials_masked?.[field.key] || ''
    }
    credentials.value = creds
  }
}, { immediate: true })

const closeDrawer = () => {
  emit('update:isDrawerOpen', false)
}

const onSubmit = async () => {
  if (!props.provider) return
  isSaving.value = true
  try {
    await store.updateProviderCredentials(props.provider.provider_key, credentials.value)
    toast(t('platformAi.credentialsSaved'), 'success')
    emit('saved')
    closeDrawer()
  }
  catch {
    toast(t('common.error'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const handleDrawerModelValueUpdate = val => {
  emit('update:isDrawerOpen', val)
}
</script>

<template>
  <VNavigationDrawer
    data-allow-mismatch
    temporary
    :width="400"
    location="end"
    class="scrollable-content"
    :model-value="props.isDrawerOpen"
    @update:model-value="handleDrawerModelValueUpdate"
  >
    <AppDrawerHeaderSection
      :title="provider?.name ? t('platformAi.configureProvider', { name: provider.name }) : t('platformAi.configureProvider', { name: '' })"
      @cancel="closeDrawer"
    />

    <VDivider />

    <PerfectScrollbar :options="{ wheelPropagation: false }">
      <VCard flat>
        <VCardText>
          <VForm @submit.prevent="onSubmit">
            <VRow>
              <VCol
                v-for="field in (provider?.credential_fields || [])"
                :key="field.key"
                cols="12"
              >
                <AppTextField
                  v-model="credentials[field.key]"
                  :label="field.label"
                  :placeholder="field.placeholder"
                  :type="field.type === 'password' ? 'password' : field.type === 'number' ? 'number' : 'text'"
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  type="submit"
                  :loading="isSaving"
                  class="me-3"
                >
                  {{ t('common.save') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="closeDrawer"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>
