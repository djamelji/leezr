<script setup>
// W-SIGNATURE-1 correctif PDF (ADR-652) — visionneuse PDF intégrée, partagée.
// Récupère le PDF via l'util unifié (validation du contenu), l'affiche en iframe, propose le
// téléchargement, et libère l'Object URL à la fermeture (jamais avant la fin d'utilisation).
import { fetchPdfBlob, createViewerUrl, revokeObjectUrl, downloadBlob } from '@/utils/pdf'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  path: { type: String, default: null },
  title: { type: String, default: 'Document' },
  filename: { type: String, default: 'document.pdf' },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()

const loading = ref(false)
const error = ref(null)
const viewerUrl = ref(null)
const blob = ref(null)

const cleanup = () => {
  revokeObjectUrl(viewerUrl.value)
  viewerUrl.value = null
  blob.value = null
  error.value = null
}

const load = async () => {
  if (!props.path) return
  loading.value = true
  error.value = null
  cleanup()
  try {
    blob.value = await fetchPdfBlob(props.path)
    viewerUrl.value = createViewerUrl(blob.value)
  } catch (e) {
    error.value = e?.message || t('common.error')
  } finally { loading.value = false }
}

watch(() => props.modelValue, v => { if (v) load(); else cleanup() })

const close = () => emit('update:modelValue', false)
const save = () => { if (blob.value) downloadBlob(blob.value, props.filename) }
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="900"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <VCard class="d-flex flex-column" style="block-size: 90vh;">
      <VCardItem>
        <VCardTitle>{{ title }}</VCardTitle>
        <template #append>
          <div class="d-flex gap-2">
            <VBtn
              v-if="viewerUrl"
              variant="tonal"
              size="small"
              prepend-icon="tabler-download"
              @click="save"
            >
              {{ t('common.download') }}
            </VBtn>
            <VBtn
              icon
              variant="text"
              size="small"
              color="default"
              @click="close"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </div>
        </template>
      </VCardItem>
      <VDivider />
      <div class="flex-grow-1 d-flex" style="min-block-size: 0;">
        <div v-if="loading" class="w-100 d-flex align-center justify-center">
          <VProgressCircular indeterminate color="primary" />
        </div>
        <div v-else-if="error" class="w-100 pa-6 d-flex flex-column align-center justify-center">
          <VIcon icon="tabler-file-alert" size="48" color="error" class="mb-3" />
          <p class="text-body-1 mb-4 text-center">
            {{ error }}
          </p>
          <VBtn variant="tonal" prepend-icon="tabler-refresh" @click="load">
            {{ t('common.retry') }}
          </VBtn>
        </div>
        <iframe
          v-else-if="viewerUrl"
          :src="viewerUrl"
          title="PDF"
          style="inline-size: 100%; block-size: 100%; border: 0;"
        />
      </div>
    </VCard>
  </VDialog>
</template>
