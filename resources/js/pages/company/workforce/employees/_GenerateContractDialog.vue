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
const previewUrl = ref('')   // URL blob du PDF d'aperçu (même pipeline que la génération)
const previewing = ref(false)
const generating = ref(false)

const templateItems = computed(() => templates.value
  .filter(t => t.status === 'published')
  .map(t => ({ title: `${t.name} (${t.source === 'company' ? t('contractTpl.source.company') : t('contractTpl.source.platform')})`, value: t.id, raw: t })))

const revokePreview = () => { if (previewUrl.value) { URL.revokeObjectURL(previewUrl.value); previewUrl.value = '' } }

watch(isOpen, async v => {
  if (v) {
    revokePreview()
    templateId.value = null
    loading.value = true
    try { templates.value = await store.fetchTemplates() } finally { loading.value = false }
  } else { revokePreview() }
})

const selectedTemplate = computed(() => templates.value.find(t => t.id === templateId.value))

// Aperçu PDF FIDÈLE (même pipeline que la génération, sans émission).
const runPreview = async () => {
  if (!templateId.value) return
  previewing.value = true
  try {
    // Signale d'abord les variables manquantes (léger).
    const info = await store.preview({ templateId: templateId.value, employeeId: props.employeeId })
    if (info.missing_variables?.length)
      toast(t('generateContract.missing', { vars: info.missing_variables.join(', ') }), 'warning')
    const blob = await store.previewPdf({ templateId: templateId.value, employeeId: props.employeeId })

    revokePreview()
    previewUrl.value = URL.createObjectURL(blob)
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
          prepend-icon="tabler-file-type-pdf"
          :loading="previewing"
          class="mb-3"
          @click="runPreview"
        >
          {{ t('generateContract.preview') }}
        </VBtn>

        <!-- Aperçu PDF FIDÈLE (même pipeline que la génération) — jamais un document émis ni signé -->
        <div v-if="previewUrl">
          <div class="text-caption text-medium-emphasis mb-2">
            {{ t('generateContract.previewBadge') }}
          </div>
          <iframe
            :src="previewUrl"
            title="apercu-contrat"
            style="inline-size: 100%; block-size: 360px; border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));"
          />
        </div>
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
