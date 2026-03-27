<script setup>
/**
 * ADR-413: Shared chip showing AI analysis confidence.
 * Used in document tables across 4 consumer pages.
 */
const props = defineProps({
  analysis: {
    type: Object,
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
  <VChip
    v-if="hasAnalysis"
    size="x-small"
    variant="tonal"
    :color="chipColor"
  >
    <VIcon
      start
      size="14"
      icon="tabler-sparkles"
    />
    {{ t('documents.aiChipLabel', { confidence }) }}
  </VChip>
</template>
