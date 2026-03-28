<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'
import DocumentMandatoryChip from '@/views/shared/documents/DocumentMandatoryChip.vue'
import DocumentStatusChip from '@/views/shared/documents/DocumentStatusChip.vue'
import DocumentLifecycleChip from '@/views/shared/documents/DocumentLifecycleChip.vue'
import DocumentAiChip from '@/views/shared/documents/DocumentAiChip.vue'
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

const emit = defineEmits(['createCustomType'])

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

// ─── Upload confirmation dialog (ADR-406 S4) ────────────
const isUploadDialogOpen = ref(false)
const pendingUploadDoc = ref(null)
const pendingUploadFiles = ref([])
const uploadExpiresAt = ref(null)

const openUploadDialog = (doc, files) => {
  if (!files?.length) return
  pendingUploadDoc.value = doc
  pendingUploadFiles.value = [...files]
  uploadExpiresAt.value = doc.upload?.expires_at ? doc.upload.expires_at.substring(0, 10) : null
  isUploadDialogOpen.value = true
}

const cancelUploadDialog = () => {
  isUploadDialogOpen.value = false
  pendingUploadDoc.value = null
  pendingUploadFiles.value = []
  uploadExpiresAt.value = null
}

const confirmUpload = () => {
  if (pendingUploadDoc.value && pendingUploadFiles.value.length) {
    handleDocUpload(pendingUploadDoc.value.code, pendingUploadFiles.value, uploadExpiresAt.value)
  }
  isUploadDialogOpen.value = false
}

const handleDocUpload = async (code, files, expiresAt = null) => {
  if (!files?.length) return
  uploadingCode.value = code
  try {
    const formData = new FormData()

    files.forEach(f => formData.append('files[]', f))
    if (expiresAt) formData.append('expires_at', expiresAt)

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
    pendingUploadDoc.value = null
    pendingUploadFiles.value = []
    uploadExpiresAt.value = null
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

// ADR-426: Apply AI suggestions
const memberViewerRef = ref(null)

const handleApplySuggestions = async fields => {
  const doc = viewerDocument.value
  if (!doc?.code) return

  try {
    await $api(`/company/members/${props.memberId}/documents/${doc.code}/apply-suggestions`, {
      method: 'POST',
      body: { fields },
    })

    memberViewerRef.value?.markApplied(fields)

    if (fields.length === 1) {
      toast(t('documents.suggestionApplied'), 'success')
    }
    else {
      toast(t('documents.suggestionsApplied'), 'success')
    }
  }
  catch {
    toast(t('common.error'), 'error')
  }
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
    <!-- ADR-192: Request Document + ADR-388: Create custom type -->
    <VCardItem v-if="canEdit && !documentsLoading">
      <template #append>
        <div class="d-flex gap-2">
          <VBtn
            variant="tonal"
            color="secondary"
            size="small"
            prepend-icon="tabler-file-certificate"
            @click="emit('createCustomType')"
          >
            {{ t('documents.createCustomFromMember') }}
          </VBtn>
          <VMenu
            v-if="requestableDocTypes.length"
            v-model="requestMenuVisible"
          >
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
        </div>
      </template>
    </VCardItem>

    <VCardText>
      <div
        v-if="documentsLoading"
        class="text-center pa-6"
      >
        <VProgressCircular indeterminate />
      </div>

      <!-- ADR-178/423: Actionable empty state -->
      <div
        v-else-if="memberDocuments.length === 0"
        class="text-center pa-8"
      >
        <VIcon
          icon="tabler-file-certificate"
          :size="64"
          color="disabled"
          class="mb-4"
        />
        <h6 class="text-h6 mb-2">
          {{ t('companyDocuments.emptyState.memberDocsTitle') }}
        </h6>
        <p class="text-body-2 text-medium-emphasis">
          {{ t('companyDocuments.emptyState.memberDocsSubtitle') }}
        </p>
      </div>

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
              <div class="d-flex align-center gap-2 flex-wrap">
                <DocumentLifecycleChip
                  :status="doc.lifecycle_status"
                  :expires-at="doc.upload?.expires_at"
                />
                <DocumentStatusChip :status="doc.request_status" />
                <DocumentAiChip
                  v-if="doc.upload"
                  :analysis="doc.upload.ai_analysis"
                />
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
                  multiple
                  :accept="doc.accepted_types.map(t => `.${t}`).join(',')"
                  @change="openUploadDialog(doc, $event.target.files); $event.target.value = ''"
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

  <!-- Upload confirmation dialog (ADR-406 S4) -->
  <VDialog
    v-model="isUploadDialogOpen"
    max-width="500"
  >
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-upload" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('documents.confirmUpload') }}</VCardTitle>
        <VCardSubtitle>{{ t(`documents.type.${pendingUploadDoc?.code}`, pendingUploadDoc?.label) }}</VCardSubtitle>
      </VCardItem>
      <VCardText>
        <div v-if="pendingUploadFiles.length" class="mb-4">
          <div
            v-for="(f, idx) in pendingUploadFiles"
            :key="idx"
            class="d-flex align-center gap-2 mb-1"
          >
            <VIcon icon="tabler-file" size="20" />
            <span class="text-body-1">{{ f.name }}</span>
            <span class="text-body-2 text-disabled">{{ formatFileSize(f.size) }}</span>
            <VBtn
              icon
              variant="text"
              size="x-small"
              color="error"
              @click="pendingUploadFiles.splice(idx, 1); if (!pendingUploadFiles.length) cancelUploadDialog()"
            >
              <VIcon icon="tabler-x" size="14" />
            </VBtn>
          </div>
          <div class="text-body-2 text-disabled mt-2">
            {{ t('documents.totalFiles', { count: pendingUploadFiles.length }) }}
          </div>
        </div>
        <VAlert
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          {{ t('documents.autoMergeHint') }}
        </VAlert>
        <template v-if="pendingUploadDoc?.requires_expiration">
          <AppDateTimePicker
            v-model="uploadExpiresAt"
            :label="t('documents.expirationDate')"
            :hint="t('documents.expirationDateHint')"
            persistent-hint
            :rules="[v => !!v || t('documents.expirationDate') + ' ' + t('common.required')]"
            clearable
          />
          <VAlert
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('documents.requiresExpirationHint') }}
          </VAlert>
        </template>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          variant="tonal"
          @click="cancelUploadDialog"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :disabled="!pendingUploadFiles.length || (pendingUploadDoc?.requires_expiration && !uploadExpiresAt)"
          :loading="uploadingCode === pendingUploadDoc?.code"
          @click="confirmUpload"
        >
          {{ t('documents.upload') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <!-- Document Viewer -->
  <DocumentViewerDialog
    ref="memberViewerRef"
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type, ocr_text: viewerDocument.upload.ocr_text, ai_analysis: viewerDocument.upload.ai_analysis, ai_insights: viewerDocument.upload.ai_insights, ai_suggestions: viewerDocument.upload.ai_suggestions, ai_status: viewerDocument.upload.ai_status } : null"
    :download-url="viewerDownloadUrl"
    :can-review="!!(viewerDocument?.can_review && canEdit)"
    :review-status="viewerDocument?.request_status"
    :review-note="viewerDocument?.request_review_note"
    @approve="handleViewerApprove"
    @reject="handleViewerReject"
    @download="handleViewerDownload"
    @apply-suggestions="handleApplySuggestions"
  />

  <ConfirmDialogComponent />
</template>
