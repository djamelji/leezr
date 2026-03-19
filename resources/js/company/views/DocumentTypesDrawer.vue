<script setup>
import CompanyDocumentActivationCatalog from '@/views/pages/company-settings/CompanyDocumentActivationCatalog.vue'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'

const props = defineProps({
  isDrawerOpen: {
    type: Boolean,
    required: true,
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['update:isDrawerOpen', 'createCustom'])

const { t } = useI18n()
const settingsStore = useCompanySettingsStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()

const companyDocumentActivations = computed(() =>
  settingsStore.documentActivations?.company_documents || [],
)

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
    await settingsStore.archiveCustomDocumentType(code)
    toast(t('documents.customTypeArchived'), 'success')
    settingsStore.fetchDocumentActivations()
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
    await settingsStore.deleteCustomDocumentType(code)
    toast(t('documents.customTypeDeleted'), 'success')
    settingsStore.fetchDocumentActivations()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const handleClose = () => {
  emit('update:isDrawerOpen', false)
}
</script>

<template>
  <VNavigationDrawer
    temporary
    :width="560"
    location="end"
    class="scrollable-content"
    :model-value="props.isDrawerOpen"
    @update:model-value="handleClose"
  >
    <div class="d-flex align-center justify-space-between pa-4">
      <h6 class="text-h6">
        {{ t('companyProfile.companyDocumentTypes') }}
      </h6>
      <VBtn
        icon
        variant="text"
        size="small"
        @click="handleClose"
      >
        <VIcon icon="tabler-x" />
      </VBtn>
    </div>

    <VDivider />

    <div style="block-size: calc(100vh - 56px); overflow-y: auto;">
      <VCard flat>
        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ t('companyProfile.companyDocumentTypesHint') }}
          </p>

          <CompanyDocumentActivationCatalog
            :company-user-documents="[]"
            :company-documents="companyDocumentActivations"
            :can-edit="canEdit"
            hide-create-button
            @refresh="settingsStore.fetchDocumentActivations()"
            @create-custom="handleClose(); emit('createCustom')"
            @archive-custom="handleArchiveCustom"
            @delete-custom="handleDeleteCustom"
          />
        </VCardText>
      </VCard>
    </div>
  </VNavigationDrawer>

  <ConfirmDialogComponent />
</template>
