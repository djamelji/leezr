<script setup>
import { $api } from '@/utils/api'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'

const props = defineProps({
  isDialogVisible: {
    type: Boolean,
    required: true,
  },
  document: {
    type: Object,
    default: null,
  },
  downloadUrl: {
    type: String,
    default: '',
  },
  canReview: {
    type: Boolean,
    default: false,
  },
  reviewStatus: {
    type: String,
    default: null,
    validator: v => v === null || ['requested', 'submitted', 'approved', 'rejected'].includes(v),
  },
  reviewNote: {
    type: String,
    default: null,
  },
})

const emit = defineEmits([
  'update:isDialogVisible',
  'download',
  'approve',
  'reject',
])

const { t, te } = useI18n()
const { formatFileSize, viewerKind } = useDocumentHelpers()

// ADR-413: AI analysis helpers
const aiConfidencePercent = computed(() =>
  Math.round((props.document?.ai_analysis?.confidence ?? 0) * 100),
)

const aiConfidenceColor = computed(() => {
  const c = aiConfidencePercent.value
  if (c >= 70) return 'success'
  if (c >= 40) return 'warning'

  return 'error'
})

const insightAlertType = severity => {
  const map = { info: 'info', warning: 'warning', error: 'error', success: 'success' }

  return map[severity] || 'info'
}

const loading = ref(false)
const previewError = ref(false)
const objectUrl = ref(null)

let fetchGeneration = 0

const kind = computed(() => viewerKind(props.document?.mime_type))

const cleanup = () => {
  if (objectUrl.value) {
    URL.revokeObjectURL(objectUrl.value)
    objectUrl.value = null
  }
  previewError.value = false
}

watch([() => props.isDialogVisible, () => props.document], async ([visible, doc]) => {
  cleanup()
  if (!visible || !doc || !props.downloadUrl)
    return

  const myGeneration = ++fetchGeneration

  loading.value = true
  try {
    const blob = await $api(props.downloadUrl, { responseType: 'blob' })
    if (myGeneration !== fetchGeneration)
      return

    objectUrl.value = URL.createObjectURL(blob)
  }
  catch {
    if (myGeneration !== fetchGeneration)
      return

    previewError.value = true
  }
  finally {
    if (myGeneration === fetchGeneration)
      loading.value = false
  }
})

onBeforeUnmount(() => {
  cleanup()
})

const stampConfig = computed(() => {
  if (props.reviewStatus === 'approved')
    return { label: t('documents.approved'), color: 'rgb(var(--v-theme-success))' }
  if (props.reviewStatus === 'rejected')
    return { label: t('documents.rejected'), color: 'rgb(var(--v-theme-error))' }

  return null
})

const onClose = () => {
  emit('update:isDialogVisible', false)
}

const handleDownload = () => {
  emit('download')
}
</script>

<template>
  <VDialog
    :width="$vuetify.display.smAndDown ? 'auto' : 900"
    :model-value="props.isDialogVisible"
    @update:model-value="onClose"
  >
    <DialogCloseBtn @click="onClose" />

    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-file-text" />
          </VAvatar>
        </template>
        <VCardTitle>{{ props.document?.label }}</VCardTitle>
        <VCardSubtitle v-if="props.document?.file_name">
          {{ props.document.file_name }}
          <template v-if="props.document?.file_size_bytes">
            &middot; {{ formatFileSize(props.document.file_size_bytes) }}
          </template>
        </VCardSubtitle>
      </VCardItem>

      <!-- Rejection note -->
      <VAlert
        v-if="reviewStatus === 'rejected' && reviewNote"
        type="error"
        variant="tonal"
        density="compact"
        class="mx-4 mb-2"
      >
        {{ reviewNote }}
      </VAlert>

      <VCardText class="pa-0">
        <!-- Loading -->
        <div
          v-if="loading"
          class="d-flex align-center justify-center"
          style="min-height: 400px;"
        >
          <div class="text-center">
            <VProgressCircular
              indeterminate
              color="primary"
              size="48"
            />
            <p class="text-body-2 text-disabled mt-4">
              {{ t('documents.loadingPreview') }}
            </p>
          </div>
        </div>

        <!-- Error -->
        <div
          v-else-if="previewError"
          class="pa-6"
        >
          <VAlert
            type="error"
            variant="tonal"
          >
            {{ t('documents.failedToLoadPreview') }}
          </VAlert>
        </div>

        <!-- PDF preview -->
        <div
          v-else-if="objectUrl && kind === 'pdf'"
          class="d-flex justify-center pa-4"
        >
          <div class="document-wrapper">
            <iframe
              :src="objectUrl"
              style="width: 100%; height: 70vh; border: none; display: block;"
            />
            <svg
              v-if="stampConfig"
              class="document-stamp"
              viewBox="0 0 200 72"
              xmlns="http://www.w3.org/2000/svg"
            >
              <rect
                x="3"
                y="3"
                width="194"
                height="66"
                rx="8"
                fill="none"
                :stroke="stampConfig.color"
                stroke-width="4"
              />
              <text
                x="100"
                y="46"
                text-anchor="middle"
                :fill="stampConfig.color"
                font-size="26"
                font-weight="bold"
                font-family="sans-serif"
                letter-spacing="3"
              >{{ stampConfig.label.toUpperCase() }}</text>
            </svg>
          </div>
        </div>

        <!-- Image preview -->
        <div
          v-else-if="objectUrl && kind === 'image'"
          class="d-flex justify-center pa-4"
          style="max-height: 70vh; overflow: visible;"
        >
          <div class="document-wrapper document-wrapper--image">
            <img
              :src="objectUrl"
              :alt="props.document?.file_name"
              style="max-width: 100%; max-height: 65vh; display: block; object-fit: contain;"
            >
            <svg
              v-if="stampConfig"
              class="document-stamp"
              viewBox="0 0 200 72"
              xmlns="http://www.w3.org/2000/svg"
            >
              <rect
                x="3"
                y="3"
                width="194"
                height="66"
                rx="8"
                fill="none"
                :stroke="stampConfig.color"
                stroke-width="4"
              />
              <text
                x="100"
                y="46"
                text-anchor="middle"
                :fill="stampConfig.color"
                font-size="26"
                font-weight="bold"
                font-family="sans-serif"
                letter-spacing="3"
              >{{ stampConfig.label.toUpperCase() }}</text>
            </svg>
          </div>
        </div>

        <!-- Unsupported / fallback -->
        <div
          v-else-if="objectUrl && kind === 'unsupported'"
          class="d-flex flex-column align-center justify-center pa-8"
          style="min-height: 300px;"
        >
          <VIcon
            icon="tabler-file-off"
            size="64"
            color="disabled"
          />
          <h6 class="text-h6 mt-4">
            {{ t('documents.previewUnavailable') }}
          </h6>
          <p class="text-body-2 text-disabled mt-2">
            {{ t('documents.previewUnavailableDescription') }}
          </p>
          <VBtn
            class="mt-4"
            variant="tonal"
            @click="handleDownload"
          >
            <VIcon
              icon="tabler-download"
              size="18"
              start
            />
            {{ t('documents.download') }}
          </VBtn>
        </div>
      </VCardText>

      <!-- OCR extracted text (ADR-409) -->
      <VExpansionPanels
        v-if="props.document?.ocr_text"
        variant="accordion"
        class="mx-4 mb-2"
      >
        <VExpansionPanel>
          <VExpansionPanelTitle>
            <VIcon
              icon="tabler-scan"
              size="18"
              class="me-2"
            />
            {{ t('documents.ocrExtractedText') }}
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <pre class="text-body-2" style="white-space: pre-wrap; word-break: break-word; font-family: inherit;">{{ props.document.ocr_text }}</pre>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>

      <!-- AI Analysis panel (ADR-413) -->
      <VExpansionPanels
        v-if="props.document?.ai_analysis && props.document.ai_analysis.source !== 'none'"
        variant="accordion"
        class="mx-4 mb-2"
      >
        <VExpansionPanel>
          <VExpansionPanelTitle>
            <VIcon
              icon="tabler-sparkles"
              size="18"
              class="me-2"
            />
            {{ t('documents.aiAnalysis') }}
            <VSpacer />
            <VChip
              size="x-small"
              variant="tonal"
              :color="aiConfidenceColor"
              class="me-2"
            >
              {{ aiConfidencePercent }}%
            </VChip>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <!-- Confidence bar -->
            <div class="mb-3">
              <div class="text-caption text-medium-emphasis mb-1">
                {{ t('documents.aiConfidence') }}
              </div>
              <VProgressLinear
                :model-value="aiConfidencePercent"
                :color="aiConfidenceColor"
                height="8"
                rounded
              />
            </div>

            <!-- Source + detected type -->
            <div class="d-flex gap-2 flex-wrap mb-3">
              <VChip
                size="x-small"
                variant="tonal"
                color="info"
              >
                {{ t(`documents.aiSource.${props.document.ai_analysis.source}`) }}
              </VChip>
              <VChip
                v-if="props.document.ai_analysis.detected_type"
                size="x-small"
                variant="tonal"
              >
                {{ props.document.ai_analysis.detected_type }}
              </VChip>
            </div>

            <!-- Insights (ADR-413) -->
            <template v-if="props.document.ai_insights?.length">
              <VAlert
                v-for="(insight, idx) in props.document.ai_insights"
                :key="idx"
                :type="insightAlertType(insight.severity)"
                variant="tonal"
                density="compact"
                class="mb-2"
              >
                {{ t(insight.messageKey, insight.messageParams || {}) }}
              </VAlert>
            </template>

            <!-- Extracted fields -->
            <VTable
              v-if="Object.keys(props.document.ai_analysis.fields || {}).length"
              density="compact"
              class="mt-3"
            >
              <thead>
                <tr>
                  <th>{{ t('documents.aiFieldLabel') }}</th>
                  <th>{{ t('documents.aiFieldValue') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(value, key) in props.document.ai_analysis.fields"
                  :key="key"
                >
                  <td class="text-medium-emphasis">
                    {{ te(`documents.aiFieldName.${key}`) ? t(`documents.aiFieldName.${key}`) : key }}
                  </td>
                  <td>{{ value }}</td>
                </tr>
              </tbody>
            </VTable>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>

      <VDivider />

      <VCardActions class="pa-4">
        <VBtn
          variant="tonal"
          @click="handleDownload"
        >
          <VIcon
            icon="tabler-download"
            size="18"
            start
          />
          {{ t('documents.download') }}
        </VBtn>

        <VSpacer />

        <template v-if="canReview">
          <VBtn
            color="success"
            @click="emit('approve')"
          >
            <VIcon
              icon="tabler-check"
              size="18"
              start
            />
            {{ t('documents.approve') }}
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            @click="emit('reject')"
          >
            <VIcon
              icon="tabler-x"
              size="18"
              start
            />
            {{ t('documents.reject') }}
          </VBtn>
        </template>
      </VCardActions>
    </VCard>
  </VDialog>
</template>

<style scoped>
.document-wrapper {
  position: relative;
  display: inline-block;
  width: 100%;
}

.document-wrapper--image {
  width: auto;
}

.document-stamp {
  position: absolute;
  bottom: 40px;
  right: -28px;
  width: 140px;
  height: auto;
  opacity: 0.8;
  pointer-events: none;
  transform: rotate(-12deg);
  z-index: 2;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15));
}
</style>
