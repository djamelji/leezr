<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'
import DocumentConstraintsInline from '@/views/shared/documents/DocumentConstraintsInline.vue'
import DocumentViewerDialog from '@/views/shared/documents/DocumentViewerDialog.vue'

const props = defineProps({
  documents: {
    type: Array,
    required: true,
  },
  canEdit: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['refresh'])

const { t } = useI18n()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const { formatFileSize } = useDocumentHelpers()

const uploadingCode = ref(null)
const downloadingCode = ref(null)
const isViewerOpen = ref(false)
const viewerDocument = ref(null)

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
    handleUpload(pendingUploadDoc.value.code, pendingUploadFiles.value, uploadExpiresAt.value)
  }
  isUploadDialogOpen.value = false
}

const handleUpload = async (code, files, expiresAt = null) => {
  if (!files?.length) return
  uploadingCode.value = code

  try {
    const formData = new FormData()

    files.forEach(f => formData.append('files[]', f))
    if (expiresAt) formData.append('expires_at', expiresAt)

    const data = await $api(`/company/documents/${code}`, {
      method: 'POST',
      body: formData,
    })

    const key = data?.document?.file_name && data?.message?.includes('replaced')
      ? 'documents.replaced'
      : 'documents.uploaded'

    toast(t(key), 'success')
    emit('refresh')
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

const handleDownload = async (code, fileName) => {
  downloadingCode.value = code
  try {
    const blob = await $api(`/company/documents/${code}/download`, {
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
  finally {
    downloadingCode.value = null
  }
}

const openViewer = doc => {
  viewerDocument.value = doc
  isViewerOpen.value = true
}

const viewerDownloadUrl = computed(() => {
  if (!viewerDocument.value?.upload)
    return ''

  return `/company/documents/${viewerDocument.value.code}/download`
})

const handleViewerDownload = () => {
  if (viewerDocument.value?.upload)
    handleDownload(viewerDocument.value.code, viewerDocument.value.upload.file_name)
}

const handleDelete = async code => {
  const ok = await confirm({
    question: t('documents.confirmDeleteDoc'),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('documents.docDeleted'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })

  if (!ok) return

  try {
    await $api(`/company/documents/${code}`, { method: 'DELETE' })
    toast(t('documents.docDeleted'), 'success')
    emit('refresh')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}
</script>

<template>
  <h6 class="text-h6 mb-3">
    {{ t('documents.vault') }}
  </h6>
  <VTable class="text-no-wrap">
    <thead>
      <tr>
        <th>{{ t('documents.title') }}</th>
        <th style="width: 100px;">
          {{ t('common.status') }}
        </th>
        <th style="width: 200px;" />
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="doc in props.documents"
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
          <template v-if="doc.upload">
            <VChip
              color="success"
              size="small"
              variant="tonal"
            >
              <VIcon
                icon="tabler-check"
                size="14"
                start
              />
              {{ doc.upload.file_name }}
            </VChip>
            <div class="text-body-2 text-disabled">
              {{ formatFileSize(doc.upload.file_size_bytes) }}
            </div>
          </template>
          <span
            v-else
            class="text-disabled"
          >
            {{ t('documents.noFileUploaded') }}
          </span>
        </td>
        <td>
          <div class="d-flex gap-1 justify-end">
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
              :loading="downloadingCode === doc.code"
              @click="handleDownload(doc.code, doc.upload.file_name)"
            >
              <VIcon icon="tabler-download" />
            </VBtn>
            <VBtn
              v-if="doc.upload && canEdit"
              icon
              variant="text"
              size="small"
              color="error"
              :title="t('common.delete')"
              @click="handleDelete(doc.code)"
            >
              <VIcon icon="tabler-trash" />
            </VBtn>
          </div>
        </td>
      </tr>
    </tbody>
  </VTable>

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
        <div
          v-if="pendingUploadFiles.length"
          class="mb-4"
        >
          <div
            v-for="(f, idx) in pendingUploadFiles"
            :key="idx"
            class="d-flex align-center gap-2 mb-1"
          >
            <VIcon
              icon="tabler-file"
              size="20"
            />
            <span class="text-body-1">{{ f.name }}</span>
            <span class="text-body-2 text-disabled">{{ formatFileSize(f.size) }}</span>
            <VBtn
              icon
              variant="text"
              size="x-small"
              color="error"
              @click="pendingUploadFiles.splice(idx, 1); if (!pendingUploadFiles.length) cancelUploadDialog()"
            >
              <VIcon
                icon="tabler-x"
                size="14"
              />
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

  <ConfirmDialogComponent />

  <!-- Document Viewer -->
  <DocumentViewerDialog
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type, ocr_text: viewerDocument.upload.ocr_text, ai_analysis: viewerDocument.upload.ai_analysis, ai_insights: viewerDocument.upload.ai_insights } : null"
    :download-url="viewerDownloadUrl"
    @download="handleViewerDownload"
  />
</template>
