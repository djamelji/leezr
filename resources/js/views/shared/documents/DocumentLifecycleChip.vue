<script setup>
/**
 * ADR-388: Displays document lifecycle_status (missing, valid, expiring_soon, expired).
 * Companion to DocumentStatusChip (request workflow) — shows document state, not workflow.
 */
const props = defineProps({
  status: {
    type: String,
    default: null,
    validator: v => v === null || ['missing', 'valid', 'expiring_soon', 'expired'].includes(v),
  },
  expiresAt: {
    type: String,
    default: null,
  },
})

const { t, d } = useI18n()

const config = {
  missing: { color: 'secondary', icon: 'tabler-file-off' },
  valid: { color: 'success', icon: 'tabler-circle-check' },
  expiring_soon: { color: 'warning', icon: 'tabler-clock-exclamation' },
  expired: { color: 'error', icon: 'tabler-alert-triangle' },
}

const expiresLabel = computed(() => {
  if (!props.expiresAt) return null
  const date = new Date(props.expiresAt)

  return date.toLocaleDateString()
})
</script>

<template>
  <VTooltip
    v-if="status && expiresLabel && (status === 'expiring_soon' || status === 'expired')"
    location="top"
  >
    <template #activator="{ props: tooltipProps }">
      <VChip
        v-bind="tooltipProps"
        size="small"
        variant="tonal"
        :color="config[status]?.color"
      >
        <VIcon
          :icon="config[status]?.icon"
          size="14"
          start
        />
        {{ t(`documents.lifecycle.${status}`) }}
      </VChip>
    </template>
    {{ t('documents.lifecycle.expiresOn', { date: expiresLabel }) }}
  </VTooltip>

  <VChip
    v-else-if="status"
    size="small"
    variant="tonal"
    :color="config[status]?.color"
  >
    <VIcon
      :icon="config[status]?.icon"
      size="14"
      start
    />
    {{ t(`documents.lifecycle.${status}`) }}
  </VChip>
</template>
