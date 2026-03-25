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

const { t } = useI18n()
const { toast } = useAppToast()

const { formatFileSize, fileFormatIcon, fileFormatLabel } = useDocumentHelpers()

const uploadingCode = ref(null)
const downloadingCode = ref(null)
const isViewerOpen = ref(false)
const viewerDocument = ref(null)

const handleUpload = async (code, file) => {
  if (!file) return
  uploadingCode.value = code

  try {
    const formData = new FormData()

    formData.append('file', file)

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
                  {{ t(`documents.type.${doc.code}`, doc.label) }}
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
                      :accept="doc.accepted_types.map(t => `.${t}`).join(',')"
                      @change="handleUpload(doc.code, $event.target.files[0]); $event.target.value = ''"
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

  <!-- Document Viewer -->
  <DocumentViewerDialog
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type } : null"
    :download-url="viewerDownloadUrl"
    :review-status="viewerDocument?.request_status"
    :review-note="viewerDocument?.request_review_note"
    @download="handleViewerDownload"
  />
</template>
