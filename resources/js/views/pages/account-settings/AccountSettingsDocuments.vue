<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'
import DocumentStatusChip from '@/views/shared/documents/DocumentStatusChip.vue'
import DocumentLifecycleChip from '@/views/shared/documents/DocumentLifecycleChip.vue'
import DocumentViewerDialog from '@/views/shared/documents/DocumentViewerDialog.vue'

const props = defineProps({
  documents: {
    type: Array,
    required: true,
  },
})

const emit = defineEmits(['refresh'])

const { t, te } = useI18n()
const { toast } = useAppToast()

const { formatFileSize, fileFormatIcon, fileFormatLabel } = useDocumentHelpers()

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

    const data = await $api(`/profile/documents/${code}`, {
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
    const blob = await $api(`/profile/documents/${code}/download`, {
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

  return `/profile/documents/${viewerDocument.value.code}/download`
})

const handleViewerDownload = () => {
  if (viewerDocument.value?.upload)
    handleDownload(viewerDocument.value.code, viewerDocument.value.upload.file_name)
}
</script>

<template>
  <VRow>
    <VCol cols="12">
      <VCard :title="t('documents.myDocuments')">
        <VCardText class="pt-0">
          <p class="text-body-2 text-disabled mb-4">
            {{ t('documents.myDocumentsDescription') }}
          </p>

          <VTable class="text-no-wrap">
            <thead>
              <tr>
                <th>{{ t('documents.title') }}</th>
                <th style="width: 100px;">
                  {{ t('common.status') }}
                </th>
                <th>{{ t('documents.required') }}</th>
                <th style="width: 200px;" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="doc in props.documents"
                :key="doc.code"
              >
                <td class="font-weight-medium">
                  {{ te(`documents.type.${doc.code}`) ? t(`documents.type.${doc.code}`) : doc.label }}
                  <div class="text-body-2 text-disabled">
                    {{ t('documents.maxSize', { size: doc.max_file_size_mb }) }} · {{ t('documents.acceptedTypes', { types: doc.accepted_types.join(', ') }) }}
                  </div>
                </td>
                <td>
                  <div class="d-flex align-center gap-2 flex-wrap">
                    <DocumentLifecycleChip
                      :status="doc.lifecycle_status"
                      :expires-at="doc.upload?.expires_at"
                    />
                    <DocumentStatusChip
                      v-if="doc.request_status"
                      :status="doc.request_status"
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
                      v-if="!doc.upload && !doc.request_status"
                      class="text-disabled"
                    >
                      {{ t('documents.noFileUploaded') }}
                    </span>
                  </div>
                </td>
                <td>
                  <VChip
                    :color="doc.required ? 'error' : 'default'"
                    size="x-small"
                    variant="tonal"
                  >
                    {{ doc.required ? t('documents.required') : t('documents.optional') }}
                  </VChip>
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
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>

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
        <VCardSubtitle>{{ te(`documents.type.${pendingUploadDoc?.code}`) ? t(`documents.type.${pendingUploadDoc?.code}`) : pendingUploadDoc?.label }}</VCardSubtitle>
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

  <!-- Document Viewer -->
  <DocumentViewerDialog
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type, ocr_text: viewerDocument.upload.ocr_text, ai_analysis: viewerDocument.upload.ai_analysis, ai_insights: viewerDocument.upload.ai_insights } : null"
    :download-url="viewerDownloadUrl"
    :review-status="viewerDocument?.request_status"
    :review-note="viewerDocument?.request_review_note"
    @download="handleViewerDownload"
  />
</template>
