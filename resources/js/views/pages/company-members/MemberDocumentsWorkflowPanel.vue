<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'
import DocumentMandatoryChip from '@/views/shared/documents/DocumentMandatoryChip.vue'
import DocumentStatusChip from '@/views/shared/documents/DocumentStatusChip.vue'
import DocumentConstraintsInline from '@/views/shared/documents/DocumentConstraintsInline.vue'
import DocumentViewerDialog from '@/views/shared/documents/DocumentViewerDialog.vue'

const props = defineProps({
  memberId: {
    type: [String, Number],
    required: true,
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
})

const { t } = useI18n()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const { formatFileSize, fileFormatIcon, fileFormatLabel } = useDocumentHelpers()

const memberDocuments = ref([])
const documentsLoading = ref(false)
const uploadingCode = ref(null)
const reviewingCode = ref(null)
const rejectDialogVisible = ref(false)
const rejectDialogCode = ref(null)
const rejectNote = ref('')
const isViewerOpen = ref(false)
const viewerDocument = ref(null)

// ADR-192: Document request
const requestMenuVisible = ref(false)
const requestingCode = ref(null)

// Requestable doc types = those activated but without an active request
const requestableDocTypes = computed(() => {
  return memberDocuments.value.filter(
    doc => !doc.request_status || doc.request_status === 'approved' || doc.request_status === 'rejected',
  )
})

const requestDocument = async code => {
  requestingCode.value = code
  try {
    await $api('/company/document-requests', {
      method: 'POST',
      body: { user_id: props.memberId, document_type_code: code },
    })
    toast(t('documents.requestSent'), 'success')
    requestMenuVisible.value = false
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    requestingCode.value = null
  }
}

const fetchDocuments = async () => {
  documentsLoading.value = true
  try {
    const data = await $api(`/company/members/${props.memberId}/documents`)

    memberDocuments.value = data.documents || []
  }
  catch { /* silently fail — tab may not be visible */ }
  finally {
    documentsLoading.value = false
  }
}

// Refetch when memberId changes (navigation between members)
watch(() => props.memberId, fetchDocuments, { immediate: true })

const handleDocUpload = async (code, file) => {
  if (!file) return
  uploadingCode.value = code
  try {
    const formData = new FormData()

    formData.append('file', file)
    await $api(`/company/members/${props.memberId}/documents/${code}`, {
      method: 'POST',
      body: formData,
    })
    toast(t('documents.uploaded'), 'success')
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('documents.failedToUpload'), 'error')
  }
  finally {
    uploadingCode.value = null
  }
}

const handleDocDelete = async code => {
  const ok = await confirm({
    question: t('documents.deleteConfirm'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('documents.deleted'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok) return
  try {
    await $api(`/company/members/${props.memberId}/documents/${code}`, { method: 'DELETE' })
    toast(t('documents.deleted'), 'success')
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('documents.failedToDelete'), 'error')
  }
}

const handleReview = async (code, status, note = null) => {
  reviewingCode.value = code
  try {
    await $api(`/company/members/${props.memberId}/documents/${code}/review`, {
      method: 'PUT',
      body: { status, review_note: note },
    })
    toast(t('documents.reviewSaved'), 'success')
    await fetchDocuments()
  }
  catch (error) {
    toast(error?.data?.message || t('documents.reviewFailed'), 'error')
  }
  finally {
    reviewingCode.value = null
  }
}

const openRejectDialog = code => {
  rejectDialogCode.value = code
  rejectNote.value = ''
  rejectDialogVisible.value = true
}

const confirmReject = async () => {
  rejectDialogVisible.value = false
  if (rejectDialogCode.value) {
    await handleReview(rejectDialogCode.value, 'rejected', rejectNote.value || null)
    rejectDialogCode.value = null
    rejectNote.value = ''
  }
}

const openViewer = doc => {
  viewerDocument.value = doc
  isViewerOpen.value = true
}

const viewerDownloadUrl = computed(() => {
  if (!viewerDocument.value?.upload)
    return ''

  return `/company/members/${props.memberId}/documents/${viewerDocument.value.code}/download`
})

const handleViewerApprove = async () => {
  if (!viewerDocument.value)
    return

  isViewerOpen.value = false
  await handleReview(viewerDocument.value.code, 'approved')
}

const handleViewerReject = () => {
  if (!viewerDocument.value)
    return

  isViewerOpen.value = false
  openRejectDialog(viewerDocument.value.code)
}

const handleViewerDownload = async () => {
  if (!viewerDocument.value?.upload)
    return

  const code = viewerDocument.value.code
  const fileName = viewerDocument.value.upload.file_name

  try {
    const blob = await $api(`/company/members/${props.memberId}/documents/${code}/download`, {
      responseType: 'blob',
    })

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = fileName
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    toast(t('documents.failedToUpload'), 'error')
  }
}
</script>

<template>
  <VCard>
    <!-- ADR-192: Request Document action -->
    <VCardItem v-if="canEdit && requestableDocTypes.length && !documentsLoading">
      <template #append>
        <VMenu v-model="requestMenuVisible">
          <template #activator="{ props: menuProps }">
            <VBtn
              v-bind="menuProps"
              variant="tonal"
              color="primary"
              size="small"
              prepend-icon="tabler-file-plus"
            >
              {{ t('documents.requestDocument') }}
            </VBtn>
          </template>
          <VList density="compact">
            <VListItem
              v-for="doc in requestableDocTypes"
              :key="doc.code"
              :disabled="requestingCode === doc.code"
              @click="requestDocument(doc.code)"
            >
              <VListItemTitle>{{ t(`documents.type.${doc.code}`, doc.label) }}</VListItemTitle>
            </VListItem>
          </VList>
        </VMenu>
      </template>
    </VCardItem>

    <VCardText>
      <div
        v-if="documentsLoading"
        class="text-center pa-6"
      >
        <VProgressCircular indeterminate />
      </div>

      <!-- ADR-178: Actionable empty state -->
      <VAlert
        v-else-if="memberDocuments.length === 0"
        type="info"
        variant="tonal"
        class="ma-2"
      >
        <VAlertTitle>{{ t('documents.noDocumentsActivated') }}</VAlertTitle>
        {{ t('documents.noDocumentsActivatedHint') }}
      </VAlert>

      <VTable
        v-else
        class="text-no-wrap"
      >
        <thead>
          <tr>
            <th>{{ t('documents.title') }}</th>
            <th style="width: 120px;">
              {{ t('documents.systemMandatory') }}
            </th>
            <th>{{ t('common.status') }}</th>
            <th style="width: 200px;" />
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="doc in memberDocuments"
            :key="doc.code"
          >
            <td class="font-weight-medium">
              {{ t(`documents.type.${doc.code}`, doc.label) }}
              <DocumentConstraintsInline
                :max-file-size-mb="doc.max_file_size_mb"
                :accepted-types="doc.accepted_types"
              />
            </td>
            <td>
              <DocumentMandatoryChip
                :mandatory="doc.mandatory"
                :required="doc.required"
              />
            </td>
            <td>
              <div class="d-flex align-center gap-2">
                <DocumentStatusChip :status="doc.request_status" />
                <VChip
                  v-if="doc.upload"
                  size="small"
                  variant="tonal"
                  color="default"
                >
                  <VIcon
                    :icon="fileFormatIcon(doc.upload.mime_type)"
                    size="14"
                    start
                  />
                  {{ fileFormatLabel(doc.upload.mime_type) }}
                </VChip>
                <span
                  v-else-if="doc.request_status === 'requested'"
                  class="text-caption text-disabled"
                >
                  {{ t('documents.noSubmissionYet') }}
                </span>
                <span
                  v-else-if="!doc.request_status"
                  class="text-caption text-disabled"
                >
                  {{ t('documents.noFileUploaded') }}
                </span>
              </div>
            </td>
            <td>
              <div class="d-flex gap-1 justify-end">
                <!-- View document -->
                <VBtn
                  v-if="doc.upload"
                  icon
                  variant="text"
                  size="small"
                  color="primary"
                  @click="openViewer(doc)"
                >
                  <VIcon icon="tabler-eye" />
                </VBtn>

                <!-- Review actions -->
                <VBtn
                  v-if="doc.can_review && canEdit"
                  size="small"
                  variant="tonal"
                  color="success"
                  :loading="reviewingCode === doc.code"
                  @click="handleReview(doc.code, 'approved')"
                >
                  <VIcon
                    icon="tabler-check"
                    size="16"
                    start
                  />
                  {{ t('documents.approve') }}
                </VBtn>
                <VBtn
                  v-if="doc.can_review && canEdit"
                  size="small"
                  variant="tonal"
                  color="error"
                  :loading="reviewingCode === doc.code"
                  @click="openRejectDialog(doc.code)"
                >
                  <VIcon
                    icon="tabler-x"
                    size="16"
                    start
                  />
                  {{ t('documents.reject') }}
                </VBtn>

                <VBtn
                  v-if="canEdit"
                  size="small"
                  variant="tonal"
                  :loading="uploadingCode === doc.code"
                  @click="$refs[`fileInput_${doc.code}`]?.[0]?.click()"
                >
                  <VIcon
                    icon="tabler-upload"
                    size="16"
                    start
                  />
                  {{ t('documents.upload') }}
                </VBtn>
                <input
                  :ref="`fileInput_${doc.code}`"
                  type="file"
                  hidden
                  :accept="doc.accepted_types.map(t => `.${t}`).join(',')"
                  @change="handleDocUpload(doc.code, $event.target.files[0]); $event.target.value = ''"
                >
                <VBtn
                  v-if="doc.upload"
                  icon
                  variant="text"
                  size="small"
                  :href="`/api/company/members/${memberId}/documents/${doc.code}/download`"
                  target="_blank"
                >
                  <VIcon icon="tabler-download" />
                </VBtn>
                <VBtn
                  v-if="doc.upload && canEdit"
                  icon
                  variant="text"
                  size="small"
                  color="error"
                  @click="handleDocDelete(doc.code)"
                >
                  <VIcon icon="tabler-trash" />
                </VBtn>
              </div>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCardText>
  </VCard>

  <!-- Reject Dialog -->
  <VDialog
    v-model="rejectDialogVisible"
    max-width="500"
  >
    <VCard>
      <VCardTitle>{{ t('documents.reject') }}</VCardTitle>
      <VCardText>
        <AppTextField
          v-model="rejectNote"
          :label="t('documents.reviewNote')"
          :placeholder="t('documents.reviewNotePlaceholder')"
          type="textarea"
          rows="3"
        />
      </VCardText>
      <VCardActions>
        <VSpacer />
        <VBtn
          color="secondary"
          variant="tonal"
          @click="rejectDialogVisible = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="error"
          @click="confirmReject"
        >
          {{ t('documents.reject') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Document Viewer -->
  <DocumentViewerDialog
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type } : null"
    :download-url="viewerDownloadUrl"
    :can-review="!!(viewerDocument?.can_review && canEdit)"
    :review-status="viewerDocument?.request_status"
    :review-note="viewerDocument?.request_review_note"
    @approve="handleViewerApprove"
    @reject="handleViewerReject"
    @download="handleViewerDownload"
  />

  <ConfirmDialogComponent />
</template>
