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
  'retry-ai',
  'apply-suggestions',
])

const { t, te } = useI18n()
const { formatFileSize, viewerKind } = useDocumentHelpers()

// ─── AI computed helpers ────────────────────────────────
const aiAnalysis = computed(() => props.document?.ai_analysis)
const hasAiResult = computed(() => aiAnalysis.value && aiAnalysis.value.source !== 'none')
const aiStatus = computed(() => props.document?.ai_status)

const aiConfidencePercent = computed(() =>
  Math.round((aiAnalysis.value?.confidence ?? 0) * 100),
)

const aiConfidenceColor = computed(() => {
  const c = aiConfidencePercent.value
  if (c >= 70) return 'success'
  if (c >= 40) return 'warning'
  return 'error'
})

const aiFields = computed(() => {
  const fields = aiAnalysis.value?.fields || {}
  return Object.entries(fields).filter(([k, v]) => v != null && k !== 'corrected_text' && k !== 'raw_text')
})

const aiFieldsCount = computed(() => aiFields.value.length)
const aiAnomaliesCount = computed(() => (props.document?.ai_insights || []).filter(i => i.severity === 'warning' || i.severity === 'error').length)
const aiDetectedType = computed(() => aiAnalysis.value?.detected_type)
const aiSummary = computed(() => aiAnalysis.value?.fields?.corrected_text ? null : (aiAnalysis.value?.summary || null))

const insightAlertType = severity => ({ info: 'info', warning: 'warning', error: 'error', success: 'success' })[severity] || 'info'

// ─── Preview loading ────────────────────────────────────
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
  if (!visible || !doc || !props.downloadUrl) return

  const myGeneration = ++fetchGeneration
  loading.value = true
  try {
    const blob = await $api(props.downloadUrl, { responseType: 'blob' })
    if (myGeneration !== fetchGeneration) return
    objectUrl.value = URL.createObjectURL(blob)
  }
  catch {
    if (myGeneration !== fetchGeneration) return
    previewError.value = true
  }
  finally {
    if (myGeneration === fetchGeneration) loading.value = false
  }
})

onBeforeUnmount(() => { cleanup() })

const stampConfig = computed(() => {
  if (props.reviewStatus === 'approved') return { label: t('documents.approved'), color: 'rgb(var(--v-theme-success))' }
  if (props.reviewStatus === 'rejected') return { label: t('documents.rejected'), color: 'rgb(var(--v-theme-error))' }
  return null
})

const onClose = () => { emit('update:isDialogVisible', false) }
const handleDownload = () => { emit('download') }

// ─── AI Suggestions ─────────────────────────────────────
const aiSuggestions = computed(() => props.document?.ai_suggestions || [])
const appliedFields = ref(new Set())
const applyingFields = ref(new Set())

watch([() => props.isDialogVisible, () => props.document], () => {
  appliedFields.value = new Set()
  applyingFields.value = new Set()
})

const confidenceColor = confidence => {
  const pct = Math.round(confidence * 100)
  if (pct >= 70) return 'success'
  if (pct >= 40) return 'warning'
  return 'error'
}

const handleApplySuggestion = field => {
  applyingFields.value.add(field)
  emit('apply-suggestions', [field])
}

const handleApplyAll = () => {
  const fields = aiSuggestions.value.filter(s => !appliedFields.value.has(s.field)).map(s => s.field)
  fields.forEach(f => applyingFields.value.add(f))
  emit('apply-suggestions', fields)
}

const markApplied = fields => {
  fields.forEach(f => {
    appliedFields.value.add(f)
    applyingFields.value.delete(f)
  })
}

defineExpose({ markApplied })
</script>

<template>
  <!-- ADR-428: Drawer instead of dialog — AI always visible -->
  <VNavigationDrawer
    :model-value="props.isDialogVisible"
    temporary
    location="end"
    :width="$vuetify.display.smAndDown ? '100%' : 560"
    @update:model-value="onClose"
  >
    <div class="d-flex flex-column h-100">
      <!-- Header -->
      <div class="d-flex align-center pa-4 border-b">
        <VAvatar color="primary" variant="tonal" rounded class="me-3">
          <VIcon icon="tabler-file-text" />
        </VAvatar>
        <div class="flex-grow-1 overflow-hidden">
          <h6 class="text-h6 text-truncate">{{ props.document?.label }}</h6>
          <div v-if="props.document?.file_name" class="text-body-2 text-medium-emphasis text-truncate">
            {{ props.document.file_name }}
            <template v-if="props.document?.file_size_bytes">
              &middot; {{ formatFileSize(props.document.file_size_bytes) }}
            </template>
          </div>
        </div>
        <VBtn icon variant="text" size="small" @click="onClose">
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <!-- Scrollable content -->
      <div class="flex-grow-1 overflow-y-auto">
        <!-- Rejection note -->
        <VAlert
          v-if="reviewStatus === 'rejected' && reviewNote"
          type="error"
          variant="tonal"
          density="compact"
          class="mx-4 mt-3"
        >
          {{ reviewNote }}
        </VAlert>

        <!-- AI Processing Banner -->
        <VAlert
          v-if="aiStatus === 'processing'"
          type="info"
          variant="tonal"
          density="compact"
          class="mx-4 mt-3"
          icon="tabler-loader-2"
        >
          <div class="d-flex align-center gap-2">
            <VProgressCircular indeterminate size="16" width="2" color="info" />
            <span>{{ t('documents.aiStatus.processing') }}</span>
          </div>
        </VAlert>

        <!-- AI Pending Banner -->
        <VAlert
          v-else-if="aiStatus === 'pending'"
          type="warning"
          variant="tonal"
          density="compact"
          class="mx-4 mt-3"
          icon="tabler-clock"
        >
          {{ t('documents.aiStatus.pending') }}
        </VAlert>

        <!-- AI Failed Banner -->
        <div v-else-if="aiStatus === 'failed'" class="mx-4 mt-3">
          <VAlert
            type="error"
            variant="tonal"
            density="compact"
            icon="tabler-alert-triangle"
          >
            <div class="d-flex align-center justify-space-between">
              <span>{{ t('documents.aiStatus.failed') }}</span>
              <VBtn
                size="x-small"
                variant="tonal"
                color="primary"
                prepend-icon="tabler-refresh"
                @click="emit('retry-ai')"
              >
                {{ t('documents.retryAi') }}
              </VBtn>
            </div>
          </VAlert>
        </div>

        <!-- AI Summary Banner (ALWAYS VISIBLE when completed) -->
        <div v-else-if="hasAiResult" class="mx-4 mt-3">
          <VCard variant="tonal" :color="aiConfidenceColor" class="mb-0">
            <VCardText class="pa-3">
              <div class="d-flex align-center gap-2 mb-2">
                <VIcon icon="tabler-sparkles" :color="aiConfidenceColor" />
                <span class="text-body-1 font-weight-medium">{{ t('documents.aiAnalyzedBanner') }}</span>
              </div>
              <div class="d-flex flex-wrap gap-x-4 gap-y-1">
                <div v-if="aiDetectedType" class="d-flex align-center gap-1">
                  <VIcon icon="tabler-file-check" size="16" />
                  <span class="text-body-2">{{ t('documents.aiDetectedType') }}: <strong>{{ aiDetectedType }}</strong></span>
                </div>
                <div class="d-flex align-center gap-1">
                  <VIcon icon="tabler-percentage" size="16" />
                  <span class="text-body-2">{{ t('documents.aiConfidence') }}: <strong>{{ aiConfidencePercent }}%</strong></span>
                  <VProgressLinear
                    :model-value="aiConfidencePercent"
                    :color="aiConfidenceColor"
                    height="4"
                    rounded
                    style="width: 60px;"
                  />
                </div>
                <div v-if="aiFieldsCount" class="d-flex align-center gap-1">
                  <VIcon icon="tabler-list-check" size="16" />
                  <span class="text-body-2">{{ t('documents.aiFieldsExtracted', { count: aiFieldsCount }) }}</span>
                </div>
                <div v-if="aiAnomaliesCount" class="d-flex align-center gap-1">
                  <VIcon icon="tabler-alert-circle" size="16" color="warning" />
                  <span class="text-body-2 text-warning">{{ t('documents.aiAnomalies', { count: aiAnomaliesCount }) }}</span>
                </div>
              </div>
              <!-- Summary text -->
              <div v-if="aiSummary" class="text-body-2 text-medium-emphasis mt-2 font-italic">
                {{ aiSummary }}
              </div>
            </VCardText>
          </VCard>
        </div>

        <!-- AI Insights (ALWAYS VISIBLE) -->
        <div v-if="props.document?.ai_insights?.length" class="mx-4 mt-3">
          <VAlert
            v-for="(insight, idx) in props.document.ai_insights"
            :key="idx"
            :type="insightAlertType(insight.severity)"
            variant="tonal"
            density="compact"
            class="mb-2"
          >
            {{ te(insight.messageKey) ? t(insight.messageKey, insight.messageParams || {}) : insight.messageKey }}
          </VAlert>
        </div>

        <!-- AI Extracted Fields (ALWAYS VISIBLE — no expansion panel) -->
        <div v-if="aiFields.length" class="mx-4 mt-3">
          <div class="d-flex align-center gap-2 mb-2">
            <VIcon icon="tabler-list-details" size="18" />
            <span class="text-body-1 font-weight-medium">{{ t('documents.aiExtractedFields') }}</span>
            <VChip size="x-small" variant="tonal" :color="aiConfidenceColor">
              {{ t(`documents.aiSource.${aiAnalysis.source}`) }}
            </VChip>
          </div>
          <VTable density="compact">
            <tbody>
              <tr v-for="[key, value] in aiFields" :key="key">
                <td class="text-medium-emphasis" style="width: 140px;">
                  {{ te(`documents.aiFieldName.${key}`) ? t(`documents.aiFieldName.${key}`) : key }}
                </td>
                <td class="font-weight-medium">{{ value }}</td>
              </tr>
            </tbody>
          </VTable>
        </div>

        <!-- AI Suggestions (ALWAYS VISIBLE — no expansion panel) -->
        <div v-if="aiSuggestions.length" class="mx-4 mt-4">
          <div class="d-flex align-center justify-space-between mb-2">
            <div class="d-flex align-center gap-2">
              <VIcon icon="tabler-bulb" size="18" color="primary" />
              <span class="text-body-1 font-weight-medium">{{ t('documents.aiSuggestions') }}</span>
              <VChip size="x-small" variant="tonal" color="primary">
                {{ aiSuggestions.length }}
              </VChip>
            </div>
            <VBtn
              size="small"
              variant="elevated"
              color="primary"
              prepend-icon="tabler-checks"
              :disabled="aiSuggestions.every(s => appliedFields.has(s.field))"
              @click="handleApplyAll"
            >
              {{ t('documents.applyAllSuggestions') }}
            </VBtn>
          </div>
          <VList density="compact" class="border rounded">
            <VListItem
              v-for="suggestion in aiSuggestions"
              :key="suggestion.field"
              :class="{ 'text-disabled': appliedFields.has(suggestion.field) }"
            >
              <template #prepend>
                <VIcon
                  :icon="appliedFields.has(suggestion.field) ? 'tabler-circle-check-filled' : 'tabler-circle-dashed'"
                  :color="appliedFields.has(suggestion.field) ? 'success' : 'default'"
                  size="20"
                />
              </template>
              <VListItemTitle class="text-body-2">
                {{ te(`documents.aiFieldName.${suggestion.field}`) ? t(`documents.aiFieldName.${suggestion.field}`) : suggestion.field }}
              </VListItemTitle>
              <VListItemSubtitle class="font-weight-medium">
                {{ suggestion.value }}
              </VListItemSubtitle>
              <template #append>
                <div class="d-flex align-center gap-2">
                  <VChip size="x-small" variant="tonal" :color="confidenceColor(suggestion.confidence)">
                    {{ Math.round(suggestion.confidence * 100) }}%
                  </VChip>
                  <VBtn
                    v-if="!appliedFields.has(suggestion.field)"
                    size="x-small"
                    variant="tonal"
                    color="success"
                    :loading="applyingFields.has(suggestion.field)"
                    @click="handleApplySuggestion(suggestion.field)"
                  >
                    {{ t('documents.applySuggestion') }}
                  </VBtn>
                  <VChip v-else size="x-small" variant="tonal" color="success" prepend-icon="tabler-check">
                    {{ t('documents.suggestionApplied') }}
                  </VChip>
                </div>
              </template>
            </VListItem>
          </VList>
        </div>

        <!-- Document Preview -->
        <div class="mx-4 mt-4">
          <!-- Loading -->
          <div v-if="loading" class="d-flex align-center justify-center" style="min-height: 300px;">
            <div class="text-center">
              <VProgressCircular indeterminate color="primary" size="40" />
              <p class="text-body-2 text-disabled mt-3">{{ t('documents.loadingPreview') }}</p>
            </div>
          </div>

          <!-- Error -->
          <VAlert v-else-if="previewError" type="error" variant="tonal">
            {{ t('documents.failedToLoadPreview') }}
          </VAlert>

          <!-- PDF preview -->
          <div v-else-if="objectUrl && kind === 'pdf'" class="document-wrapper">
            <iframe :src="objectUrl" style="width: 100%; height: 50vh; border: none; border-radius: 8px;" />
            <svg v-if="stampConfig" class="document-stamp" viewBox="0 0 200 72" xmlns="http://www.w3.org/2000/svg">
              <rect x="3" y="3" width="194" height="66" rx="8" fill="none" :stroke="stampConfig.color" stroke-width="4" />
              <text x="100" y="46" text-anchor="middle" :fill="stampConfig.color" font-size="26" font-weight="bold" font-family="sans-serif" letter-spacing="3">{{ stampConfig.label.toUpperCase() }}</text>
            </svg>
          </div>

          <!-- Image preview -->
          <div v-else-if="objectUrl && kind === 'image'" class="document-wrapper document-wrapper--image">
            <img :src="objectUrl" :alt="props.document?.file_name" style="max-width: 100%; max-height: 50vh; display: block; object-fit: contain; border-radius: 8px;">
            <svg v-if="stampConfig" class="document-stamp" viewBox="0 0 200 72" xmlns="http://www.w3.org/2000/svg">
              <rect x="3" y="3" width="194" height="66" rx="8" fill="none" :stroke="stampConfig.color" stroke-width="4" />
              <text x="100" y="46" text-anchor="middle" :fill="stampConfig.color" font-size="26" font-weight="bold" font-family="sans-serif" letter-spacing="3">{{ stampConfig.label.toUpperCase() }}</text>
            </svg>
          </div>

          <!-- Unsupported -->
          <div v-else-if="objectUrl && kind === 'unsupported'" class="d-flex flex-column align-center justify-center pa-6" style="min-height: 200px;">
            <VIcon icon="tabler-file-off" size="48" color="disabled" />
            <p class="text-body-2 text-disabled mt-3">{{ t('documents.previewUnavailable') }}</p>
            <VBtn class="mt-2" size="small" variant="tonal" prepend-icon="tabler-download" @click="handleDownload">
              {{ t('documents.download') }}
            </VBtn>
          </div>
        </div>

        <!-- OCR text (collapsed — raw data) -->
        <VExpansionPanels v-if="props.document?.ocr_text" variant="accordion" class="mx-4 mt-3 mb-4">
          <VExpansionPanel>
            <VExpansionPanelTitle>
              <VIcon icon="tabler-scan" size="18" class="me-2" />
              {{ t('documents.ocrExtractedText') }}
            </VExpansionPanelTitle>
            <VExpansionPanelText>
              <pre class="text-body-2" style="white-space: pre-wrap; word-break: break-word; font-family: inherit;">{{ props.document.ocr_text }}</pre>
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>
      </div>

      <!-- Sticky actions footer -->
      <div class="border-t pa-3 d-flex align-center gap-2">
        <VBtn variant="tonal" size="small" prepend-icon="tabler-download" @click="handleDownload">
          {{ t('documents.download') }}
        </VBtn>
        <VSpacer />
        <template v-if="canReview">
          <VBtn color="success" size="small" prepend-icon="tabler-check" @click="emit('approve')">
            {{ t('documents.approve') }}
          </VBtn>
          <VBtn color="error" variant="tonal" size="small" prepend-icon="tabler-x" @click="emit('reject')">
            {{ t('documents.reject') }}
          </VBtn>
        </template>
      </div>
    </div>
  </VNavigationDrawer>
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
  width: 120px;
  height: auto;
  opacity: 0.8;
  pointer-events: none;
  transform: rotate(-12deg);
  z-index: 2;
  filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.15));
}
</style>
