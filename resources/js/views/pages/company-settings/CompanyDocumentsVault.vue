<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
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
const { formatFileSize } = useDocumentHelpers()

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

  <!-- Document Viewer -->
  <DocumentViewerDialog
    v-model:is-dialog-visible="isViewerOpen"
    :document="viewerDocument?.upload ? { code: viewerDocument.code, label: viewerDocument.label, file_name: viewerDocument.upload.file_name, file_size_bytes: viewerDocument.upload.file_size_bytes, mime_type: viewerDocument.upload.mime_type } : null"
    :download-url="viewerDownloadUrl"
    @download="handleViewerDownload"
  />
</template>
