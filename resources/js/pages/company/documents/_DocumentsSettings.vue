<script setup>
import CompanyDocumentActivationCatalog from '@/views/pages/company-settings/CompanyDocumentActivationCatalog.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'
import { useAuthStore } from '@/core/stores/auth'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

const { t } = useI18n()
const store = useCompanyDocumentsStore()
const auth = useAuthStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const canConfigure = computed(() => auth.hasPermission('documents.configure'))

const companyUserDocuments = computed(
  () => store.documentActivations.company_user_documents || [],
)
const companyDocuments = computed(
  () => store.documentActivations.company_documents || [],
)

const activeMemberCount = computed(
  () => companyUserDocuments.value.filter(d => d.enabled).length,
)
const activeCompanyCount = computed(
  () => companyDocuments.value.filter(d => d.enabled).length,
)

const emit = defineEmits(['openCreateDrawer', 'editCustomType'])

const handleRefresh = async () => {
  await store.fetchDocumentActivations()
}

const handleArchiveCustom = async code => {
  const ok = await confirm({
    question: t('documents.confirmArchive'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('documents.customTypeArchived'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
    return

  try {
    await store.archiveCustomDocumentType(code)
    toast(t('documents.customTypeArchived'), 'success')
    await handleRefresh()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const handleDeleteCustom = async code => {
  const ok = await confirm({
    question: t('documents.confirmDelete'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('documents.customTypeDeleted'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
    return

  try {
    await store.deleteCustomDocumentType(code)
    toast(t('documents.customTypeDeleted'), 'success')
    await handleRefresh()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

// ─── Automation settings ─────────────────────────────────
const autoForm = ref({
  auto_renew_enabled: true,
  renew_days_before: 30,
  auto_remind_enabled: true,
  remind_after_days: 7,
})
const isAutoSaving = ref(false)

// ─── AI features settings (ADR-413) ────────────────────
const aiForm = ref({
  ai_analysis_enabled: true,
  ocr_enabled: true,
  auto_fill_expiry: true,
  notify_expiry_detected: true,
  notify_validation_errors: true,
  min_confidence_threshold: 60,
  auto_reject_type_mismatch: false,
})
const isAiSaving = ref(false)

onMounted(async () => {
  await store.fetchDocSettings()
  if (store.docSettings) {
    autoForm.value = {
      auto_renew_enabled: store.docSettings.auto_renew_enabled,
      renew_days_before: store.docSettings.renew_days_before,
      auto_remind_enabled: store.docSettings.auto_remind_enabled,
      remind_after_days: store.docSettings.remind_after_days,
    }
    if (store.docSettings.ai_features) {
      aiForm.value = { ...aiForm.value, ...store.docSettings.ai_features }
    }
  }
})

const saveAutoSettings = async () => {
  isAutoSaving.value = true
  try {
    await store.updateDocSettings(autoForm.value)
    toast(t('companyDocuments.automation.saved'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isAutoSaving.value = false
  }
}

const saveAiSettings = async () => {
  isAiSaving.value = true
  try {
    await store.updateDocSettings({ ai_features: aiForm.value })
    toast(t('documents.aiSettings.saved'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isAiSaving.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="info"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-file-settings" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('companyDocuments.settings.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('companyDocuments.settings.hint') }}</VCardSubtitle>
      <template #append>
        <div class="d-flex gap-2">
          <VChip
            variant="tonal"
            color="warning"
          >
            {{ t('documents.summaryActiveMember', { count: activeMemberCount }) }}
          </VChip>
          <VChip
            variant="tonal"
            color="primary"
          >
            {{ t('documents.summaryActiveCompany', { count: activeCompanyCount }) }}
          </VChip>
        </div>
      </template>
    </VCardItem>
    <VCardText>
      <CompanyDocumentActivationCatalog
        :company-user-documents="companyUserDocuments"
        :company-documents="companyDocuments"
        :can-edit="canConfigure"
        @refresh="handleRefresh"
        @create-custom="emit('openCreateDrawer')"
        @edit-custom="doc => emit('editCustomType', doc)"
        @archive-custom="handleArchiveCustom"
        @delete-custom="handleDeleteCustom"
      />
    </VCardText>
  </VCard>

  <!-- Automation Settings Card -->
  <VCard class="mt-6">
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="warning"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-robot" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('companyDocuments.automation.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('companyDocuments.automation.hint') }}</VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="autoForm.auto_renew_enabled"
              :disabled="!canConfigure"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('companyDocuments.automation.autoRenew') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('companyDocuments.automation.autoRenewHint') }}
              </div>
            </div>
          </div>
          <AppTextField
            v-if="autoForm.auto_renew_enabled"
            v-model.number="autoForm.renew_days_before"
            :label="t('companyDocuments.automation.renewDays')"
            type="number"
            :min="1"
            :max="365"
            :disabled="!canConfigure"
            class="ms-14"
          />
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="autoForm.auto_remind_enabled"
              :disabled="!canConfigure"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('companyDocuments.automation.autoRemind') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('companyDocuments.automation.autoRemindHint') }}
              </div>
            </div>
          </div>
          <AppTextField
            v-if="autoForm.auto_remind_enabled"
            v-model.number="autoForm.remind_after_days"
            :label="t('companyDocuments.automation.remindDays')"
            type="number"
            :min="1"
            :max="90"
            :disabled="!canConfigure"
            class="ms-14"
          />
        </VCol>
      </VRow>
    </VCardText>
    <VCardActions
      v-if="canConfigure"
      class="justify-end"
    >
      <VBtn
        color="primary"
        :loading="isAutoSaving"
        @click="saveAutoSettings"
      >
        {{ t('common.save') }}
      </VBtn>
    </VCardActions>
  </VCard>

  <!-- AI Features Settings Card (ADR-413) -->
  <VCard class="mt-6">
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-sparkles" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('documents.aiSettings.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('documents.aiSettings.hint') }}</VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <!-- AI Analysis toggle -->
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="aiForm.ai_analysis_enabled"
              :disabled="!canConfigure"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('documents.aiSettings.analysisEnabled') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('documents.aiSettings.analysisEnabledHint') }}
              </div>
            </div>
          </div>

          <!-- OCR toggle -->
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="aiForm.ocr_enabled"
              :disabled="!canConfigure || !aiForm.ai_analysis_enabled"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('documents.aiSettings.ocrEnabled') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('documents.aiSettings.ocrEnabledHint') }}
              </div>
            </div>
          </div>

          <!-- Auto-fill expiry -->
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="aiForm.auto_fill_expiry"
              :disabled="!canConfigure || !aiForm.ai_analysis_enabled"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('documents.aiSettings.autoFillExpiry') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('documents.aiSettings.autoFillExpiryHint') }}
              </div>
            </div>
          </div>
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <!-- Notify expiry -->
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="aiForm.notify_expiry_detected"
              :disabled="!canConfigure || !aiForm.ai_analysis_enabled"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('documents.aiSettings.notifyExpiry') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('documents.aiSettings.notifyExpiryHint') }}
              </div>
            </div>
          </div>

          <!-- Notify validation errors -->
          <div class="d-flex align-center gap-4 mb-4">
            <VSwitch
              v-model="aiForm.notify_validation_errors"
              :disabled="!canConfigure || !aiForm.ai_analysis_enabled"
            />
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('documents.aiSettings.notifyErrors') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('documents.aiSettings.notifyErrorsHint') }}
              </div>
            </div>
          </div>

          <!-- Confidence threshold slider -->
          <div class="mb-4">
            <div class="text-body-1 font-weight-medium mb-2">
              {{ t('documents.aiSettings.confidenceThreshold') }}
            </div>
            <VSlider
              v-model="aiForm.min_confidence_threshold"
              :min="10"
              :max="100"
              :step="5"
              :disabled="!canConfigure || !aiForm.ai_analysis_enabled"
              thumb-label
            >
              <template #thumb-label="{ modelValue }">
                {{ modelValue }}%
              </template>
            </VSlider>
            <div class="text-caption text-medium-emphasis">
              {{ t('documents.aiSettings.confidenceThresholdHint') }}
            </div>
          </div>
        </VCol>
      </VRow>

      <!-- Danger zone: auto-reject -->
      <VAlert
        type="warning"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ t('documents.aiSettings.autoRejectWarning') }}
      </VAlert>
      <div class="d-flex align-center gap-4">
        <VSwitch
          v-model="aiForm.auto_reject_type_mismatch"
          color="error"
          :disabled="!canConfigure || !aiForm.ai_analysis_enabled"
        />
        <div>
          <div class="text-body-1 font-weight-medium">
            {{ t('documents.aiSettings.autoRejectMismatch') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('documents.aiSettings.autoRejectMismatchHint') }}
          </div>
        </div>
      </div>
    </VCardText>
    <VCardActions
      v-if="canConfigure"
      class="justify-end"
    >
      <VBtn
        color="primary"
        :loading="isAiSaving"
        :disabled="!aiForm.ai_analysis_enabled && !isAiSaving"
        @click="saveAiSettings"
      >
        {{ t('common.save') }}
      </VBtn>
    </VCardActions>
  </VCard>

  <ConfirmDialogComponent />
</template>
