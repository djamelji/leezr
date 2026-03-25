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

const emit = defineEmits(['openCreateDrawer'])

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
  auto_renew_enabled: false,
  renew_days_before: 30,
  auto_remind_enabled: false,
  remind_after_days: 7,
})
const isAutoSaving = ref(false)

onMounted(async () => {
  await store.fetchDocSettings()
  if (store.docSettings) {
    autoForm.value = {
      auto_renew_enabled: store.docSettings.auto_renew_enabled,
      renew_days_before: store.docSettings.renew_days_before,
      auto_remind_enabled: store.docSettings.auto_remind_enabled,
      remind_after_days: store.docSettings.remind_after_days,
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

  <ConfirmDialogComponent />
</template>
