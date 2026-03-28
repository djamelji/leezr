<script setup>
/**
 * ADR-428: Enhanced AI chip with ai_status lifecycle support.
 * Shows processing spinner, failed badge, or confidence chip.
 */
const props = defineProps({
  analysis: {
    type: Object,
    default: null,
  },
  aiStatus: {
    type: String,
    default: null,
  },
})

const { t } = useI18n()

const hasAnalysis = computed(() =>
  props.analysis && props.analysis.source && props.analysis.source !== 'none',
)

const confidence = computed(() =>
  Math.round((props.analysis?.confidence ?? 0) * 100),
)

const chipColor = computed(() => {
  const c = confidence.value
  if (c >= 70) return 'success'
  if (c >= 40) return 'warning'
  return 'error'
})
</script>

<template>
  <!-- Processing: animated spinner -->
  <VChip
    v-if="aiStatus === 'processing' || aiStatus === 'pending'"
    size="x-small"
    variant="tonal"
    color="info"
  >
    <VProgressCircular indeterminate size="12" width="2" color="info" class="me-1" />
    {{ t('documents.aiStatus.processing') }}
  </VChip>

  <!-- Failed -->
  <VChip
    v-else-if="aiStatus === 'failed'"
    size="x-small"
    variant="tonal"
    color="error"
    prepend-icon="tabler-alert-triangle"
  >
    {{ t('documents.aiStatus.failed') }}
  </VChip>

  <!-- Completed with analysis -->
  <VChip
    v-else-if="hasAnalysis"
    size="x-small"
    variant="tonal"
    :color="chipColor"
  >
    <VIcon start size="14" icon="tabler-sparkles" />
    {{ t('documents.aiChipLabel', { confidence }) }}
  </VChip>
</template>
