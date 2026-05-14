<script setup>
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()

const emit = defineEmits(['drafts-generated', 'official-generated'])
const props = defineProps({
  store: { type: Object, required: true },
})

// ── Draft generation ─────────────────────────────────────────
const draftRunId = ref('')
const draftLoading = ref(false)

const submitDraftGeneration = async () => {
  if (!draftRunId.value) return
  draftLoading.value = true
  try {
    await props.store.generatePayslipDrafts(draftRunId.value)
    draftRunId.value = ''
    emit('drafts-generated')
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  } finally {
    draftLoading.value = false
  }
}

// ── Official generation (with confirmation) ──────────────────
const officialRunId = ref('')
const officialLoading = ref(false)
const confirmDialog = ref(false)

const openOfficialConfirm = () => {
  if (!officialRunId.value) return
  confirmDialog.value = true
}

const executeOfficialGeneration = async () => {
  officialLoading.value = true
  try {
    await props.store.generateOfficialPayslips(officialRunId.value)
    confirmDialog.value = false
    officialRunId.value = ''
    emit('official-generated')
  } catch (e) {
    toast(e?.response?.data?.message || e.message, 'error')
  } finally {
    officialLoading.value = false
  }
}
</script>

<template>
  <VRow class="mb-6">
    <!-- Draft payslips -->
    <VCol
      cols="12"
      md="6"
    >
      <VCard>
        <VCardText>
          <div class="d-flex align-center gap-3 mb-4">
            <VAvatar
              color="info"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-file-pencil"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-h6">
                {{ $t('workforceDocuments.payslipActions.generateDrafts') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('workforceDocuments.payslipActions.generateDraftsDesc') }}
              </div>
            </div>
          </div>
          <div class="d-flex align-center gap-3">
            <AppTextField
              v-model="draftRunId"
              :label="$t('workforceDocuments.payslipActions.payrollRunId')"
              type="number"
              density="compact"
              class="flex-grow-1"
            />
            <VBtn
              v-can="'workforce_documents.generate'"
              color="info"
              :loading="draftLoading"
              :disabled="!draftRunId"
              @click="submitDraftGeneration"
            >
              {{ $t('workforceDocuments.generate') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>

    <!-- Official payslips -->
    <VCol
      cols="12"
      md="6"
    >
      <VCard>
        <VCardText>
          <div class="d-flex align-center gap-3 mb-4">
            <VAvatar
              color="success"
              variant="tonal"
              rounded
              size="44"
            >
              <VIcon
                icon="tabler-certificate"
                size="28"
              />
            </VAvatar>
            <div>
              <div class="text-h6">
                {{ $t('workforceDocuments.payslipActions.generateOfficial') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('workforceDocuments.payslipActions.generateOfficialDesc') }}
              </div>
            </div>
          </div>
          <div class="d-flex align-center gap-3">
            <AppTextField
              v-model="officialRunId"
              :label="$t('workforceDocuments.payslipActions.payrollRunId')"
              type="number"
              density="compact"
              class="flex-grow-1"
            />
            <VBtn
              v-can="'workforce_documents.generate'"
              color="success"
              :disabled="!officialRunId"
              @click="openOfficialConfirm"
            >
              {{ $t('workforceDocuments.generate') }}
            </VBtn>
          </div>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>

  <!-- Official Payslips Confirmation Dialog -->
  <VDialog
    v-model="confirmDialog"
    max-width="480"
  >
    <VCard>
      <VCardTitle class="text-h5 pa-4">
        {{ $t('workforceDocuments.confirm.officialTitle') }}
      </VCardTitle>
      <VCardText>
        {{ $t('workforceDocuments.confirm.officialText') }}
      </VCardText>
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn
          variant="outlined"
          @click="confirmDialog = false"
        >
          {{ $t('common.cancel') }}
        </VBtn>
        <VBtn
          color="success"
          :loading="officialLoading"
          @click="executeOfficialGeneration"
        >
          {{ $t('workforceDocuments.payslipActions.generateOfficial') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
