<script setup>
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import CompanyDocumentActivationCatalog from './CompanyDocumentActivationCatalog.vue'
import CompanyDocumentsVault from './CompanyDocumentsVault.vue'
import CreateCustomDocumentDialog from './CreateCustomDocumentDialog.vue'

const props = defineProps({
  documentActivations: {
    type: Object,
    default: () => ({ company_user_documents: [], company_documents: [] }),
  },
  companyDocuments: {
    type: Array,
    default: () => [],
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['refreshActivations', 'refreshDocuments'])

const { t } = useI18n()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const settingsStore = useCompanySettingsStore()

const isCreateDialogVisible = ref(false)

const handleCreateCustom = async payload => {
  try {
    await settingsStore.createCustomDocumentType(payload)
    toast(t('documents.customTypeCreated'), 'success')
    isCreateDialogVisible.value = false
    emit('refreshActivations')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
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
    await settingsStore.archiveCustomDocumentType(code)
    toast(t('documents.customTypeArchived'), 'success')
    emit('refreshActivations')
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
    emit('refreshActivations')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const activeMemberCount = computed(() =>
  (props.documentActivations.company_user_documents || []).filter(d => d.enabled).length,
)

const activeCompanyCount = computed(() =>
  (props.documentActivations.company_documents || []).filter(d => d.enabled).length,
)

const uploadedCount = computed(() =>
  props.companyDocuments.filter(d => d.upload).length,
)

const hasVaultDocuments = computed(() => props.companyDocuments.length > 0)
</script>

<template>
  <VCard class="mt-6">
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
      <VCardTitle>{{ t('documents.documentsSection') }}</VCardTitle>
      <VCardSubtitle>{{ t('documents.documentsSectionDescription') }}</VCardSubtitle>
    </VCardItem>
    <VCardText>
      <!-- Summary chips -->
      <div class="d-flex flex-wrap gap-2 mb-6">
        <VChip
          variant="tonal"
          color="warning"
        >
          <VIcon
            icon="tabler-user"
            size="14"
            start
          />
          {{ t('documents.summaryActiveMember', { count: activeMemberCount }) }}
        </VChip>
        <VChip
          variant="tonal"
          color="primary"
        >
          <VIcon
            icon="tabler-building"
            size="14"
            start
          />
          {{ t('documents.summaryActiveCompany', { count: activeCompanyCount }) }}
        </VChip>
        <VChip
          v-if="hasVaultDocuments"
          variant="tonal"
          color="success"
        >
          <VIcon
            icon="tabler-upload"
            size="14"
            start
          />
          {{ t('documents.summaryUploaded', { count: uploadedCount }) }}
        </VChip>
      </div>

      <!-- Document Catalog -->
      <CompanyDocumentActivationCatalog
        :company-user-documents="documentActivations.company_user_documents"
        :company-documents="documentActivations.company_documents"
        :can-edit="canEdit"
        @refresh="emit('refreshActivations')"
        @create-custom="isCreateDialogVisible = true"
        @archive-custom="handleArchiveCustom"
        @delete-custom="handleDeleteCustom"
      />

      <!-- Company Vault -->
      <template v-if="hasVaultDocuments">
        <VDivider class="my-6" />
        <CompanyDocumentsVault
          :documents="companyDocuments"
          :can-edit="canEdit"
          @refresh="emit('refreshDocuments')"
        />
      </template>
    </VCardText>
  </VCard>

  <!-- Create Custom Document Type Dialog -->
  <CreateCustomDocumentDialog
    v-model:is-dialog-visible="isCreateDialogVisible"
    @created="handleCreateCustom"
  />

  <ConfirmDialogComponent />
</template>
