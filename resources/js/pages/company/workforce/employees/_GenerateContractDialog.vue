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
// ADR-783 (UX6.2) : identité employeur incomplète → message en ligne + lien vers le profil (rien n'est émis).
const identityError = ref(null)
const route = useRoute()
// ADR-785 : le lien profil garde la liste des champs (`missing`) et permet de revenir ici (`back`).
const fixLink = computed(() => identityError.value?.fix
  ? { ...identityError.value.fix, query: { ...(identityError.value.fix.query || {}), back: route.fullPath } }
  : null)

const templateItems = computed(() => templates.value
  .filter(t => t.status === 'published')
  .map(t => ({ title: `${t.name} (${t.source === 'company' ? t('contractTpl.source.company') : t('contractTpl.source.platform')})`, value: t.id, raw: t })))

const revokePreview = () => { if (previewUrl.value) { URL.revokeObjectURL(previewUrl.value); previewUrl.value = '' } }

watch(isOpen, async v => {
  if (v) {
    identityError.value = null
    revokePreview()
    templateId.value = null
    loading.value = true
    try {
      templates.value = await store.fetchTemplates()
      // W-CONTRACT-3 : présélection du modèle par DÉFAUT Company (publié), sinon rien.
      templateId.value = templates.value.find(t => t.is_default && t.status === 'published')?.id ?? null
    } finally { loading.value = false }
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
  } catch (e) { toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error') }
  finally { previewing.value = false }
}

const runGenerate = async () => {
  if (!selectedTemplate.value) return
  generating.value = true
  identityError.value = null
  try {
    await store.generate({ templateCode: selectedTemplate.value.code, employeeId: props.employeeId })
    toast(t('generateContract.generated'), 'success')
    emit('generated')
    isOpen.value = false
  } catch (e) {
    const body = e.data || e.response?._data || {}
    if (body.code === 'employer_identity_incomplete') identityError.value = body
    else toast(body.message || t('common.error'), 'error')
  }
  finally { generating.value = false }
}
</script>

<template>
  <!-- ADR-783 (UX6.2) : dialogue commun (titre, fermer, pied d'actions stable) -->
  <AppDialog
    v-model="isOpen"
    :title="t('generateContract.title')"
    :subtitle="t('generateContract.hint')"
    :max-width="720"
  >
    <VAlert
      v-if="identityError"
      type="warning"
      variant="tonal"
      class="mb-4"
      data-test="contract-identity-incomplete"
    >
      {{ t('generateContract.identityIncomplete') }}
      <div class="mt-2">
        <VBtn
          size="small"
          variant="flat"
          color="warning"
          :to="fixLink"
          @click="isOpen = false"
        >
          {{ t('generateContract.completeProfile') }}
        </VBtn>
      </div>
    </VAlert>

    <AppSelect
      v-model="templateId"
      :items="templateItems"
      :loading="loading"
      :label="t('generateContract.chooseTemplate')"
      class="mb-3"
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

    <template #actions>
      <VBtn
        variant="outlined"
        color="secondary"
        @click="isOpen = false"
      >
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
    </template>
  </AppDialog>
</template>
