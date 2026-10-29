<script setup>
// W-CONTRACT-1 (ADR-647) — génération d'un contrat depuis la fiche collaborateur : choix d'un modèle
// compatible → aperçu (sans émission) → génération + archivage. Réutilise GenerateDocumentUseCase.
import { useContractStore } from '@/modules/company/workforce/contract.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  employeeId: { type: [Number, String], required: true },
})
const emit = defineEmits(['update:modelValue', 'generated'])

const { t } = useI18n()
const { toast } = useAppToast()
const store = useContractStore()

const isOpen = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })

const templates = ref([])
const templateId = ref(null)
const loading = ref(false)
const previewHtml = ref('')
const previewing = ref(false)
const generating = ref(false)

const templateItems = computed(() => templates.value
  .filter(t => t.status === 'published')
  .map(t => ({ title: `${t.name} (${t.source === 'company' ? t('contractTpl.source.company') : t('contractTpl.source.platform')})`, value: t.id, raw: t })))

watch(isOpen, async v => {
  if (v) {
    previewHtml.value = ''
    templateId.value = null
    loading.value = true
    try { templates.value = await store.fetchTemplates() } finally { loading.value = false }
  }
})

const selectedTemplate = computed(() => templates.value.find(t => t.id === templateId.value))

const runPreview = async () => {
  if (!templateId.value) return
  previewing.value = true
  try {
    const res = await store.preview({ templateId: templateId.value, employeeId: props.employeeId })
    previewHtml.value = res.html
    if (res.missing_variables?.length)
      toast(t('generateContract.missing', { vars: res.missing_variables.join(', ') }), 'warning')
  } catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { previewing.value = false }
}

const runGenerate = async () => {
  if (!selectedTemplate.value) return
  generating.value = true
  try {
    await store.generate({ templateCode: selectedTemplate.value.code, employeeId: props.employeeId })
    toast(t('generateContract.generated'), 'success')
    emit('generated')
    isOpen.value = false
  } catch (e) { toast(e.response?.data?.message || t('common.error'), 'error') }
  finally { generating.value = false }
}
</script>

<template>
  <VDialog
    v-model="isOpen"
    max-width="720"
    scrollable
  >
    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('generateContract.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('generateContract.hint') }}</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <AppSelect
          v-model="templateId"
          :items="templateItems"
          :loading="loading"
          :label="t('generateContract.chooseTemplate')"
          class="mb-3"
          @update:model-value="previewHtml = ''"
        />

        <VAlert
          v-if="selectedTemplate && selectedTemplate.legal_status !== 'validated'"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-3"
        >
          {{ t('generateContract.notValidated') }}
        </VAlert>

        <VBtn
          v-if="templateId"
          variant="tonal"
          size="small"
          prepend-icon="tabler-eye"
          :loading="previewing"
          class="mb-3"
          @click="runPreview"
        >
          {{ t('generateContract.preview') }}
        </VBtn>

        <!-- Aperçu (jamais un document émis ni signé) -->
        <VCard
          v-if="previewHtml"
          variant="outlined"
          class="pa-4 contract-preview"
          style="max-block-size: 320px; overflow-y: auto;"
        >
          <div class="text-caption text-medium-emphasis mb-2">
            {{ t('generateContract.previewBadge') }}
          </div>
          <!-- eslint-disable-next-line vue/no-v-html -->
          <div v-html="previewHtml" />
        </VCard>
      </VCardText>
      <VDivider />
      <VCardText class="d-flex justify-end gap-3">
        <VBtn variant="outlined" color="secondary" @click="isOpen = false">
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          prepend-icon="tabler-file-download"
          :loading="generating"
          :disabled="!templateId"
          @click="runGenerate"
        >
          {{ t('generateContract.generate') }}
        </VBtn>
      </VCardText>
    </VCard>
  </VDialog>
</template>
