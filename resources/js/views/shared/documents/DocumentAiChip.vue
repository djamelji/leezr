<script setup>
/**
 * ADR-428: Enhanced AI chip with ai_status lifecycle support.
 * ADR-431b: Timeout UX — shows "taking longer than expected" after 30s.
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
  uploadedAt: {
    type: Number,
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

// ADR-431b: Timeout UX — detect if AI is taking >30s
const isProcessing = computed(() => props.aiStatus === 'processing' || props.aiStatus === 'pending')
const elapsed = ref(0)
let tickTimer = null

watch(isProcessing, active => {
  if (active && props.uploadedAt) {
    elapsed.value = Date.now() - props.uploadedAt
    tickTimer = setInterval(() => {
      elapsed.value = Date.now() - props.uploadedAt
    }, 1000)
  }
  else {
    if (tickTimer) clearInterval(tickTimer)
    tickTimer = null
    elapsed.value = 0
  }
}, { immediate: true })

onBeforeUnmount(() => {
  if (tickTimer) clearInterval(tickTimer)
})

const isSlowProcessing = computed(() => isProcessing.value && elapsed.value > 30000)
</script>

<template>
  <Transition name="ai-chip" mode="out-in">
    <!-- Processing slow: >30s timeout message -->
    <VChip
      v-if="isSlowProcessing"
      key="slow"
      size="x-small"
      variant="tonal"
      color="warning"
    >
      <VProgressCircular indeterminate size="12" width="2" color="warning" class="me-1" />
      {{ t('documents.aiStatus.slow') }}
    </VChip>

    <!-- Processing: animated spinner -->
    <VChip
      v-else-if="isProcessing"
      key="processing"
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
      key="failed"
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
      key="completed"
      size="x-small"
      variant="tonal"
      :color="chipColor"
    >
      <VIcon start size="14" icon="tabler-sparkles" />
      {{ t('documents.aiChipLabel', { confidence }) }}
    </VChip>
  </Transition>
</template>

<style scoped>
.ai-chip-enter-active,
.ai-chip-leave-active {
  transition: opacity 0.4s ease, transform 0.4s ease;
}

.ai-chip-enter-from {
  opacity: 0;
  transform: scale(0.85);
}

.ai-chip-leave-to {
  opacity: 0;
  transform: scale(0.85);
}
</style>
