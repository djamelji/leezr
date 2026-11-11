<script setup>
defineProps({
  status: {
    type: String,
    default: null,
    validator: v => v === null || ['requested', 'submitted', 'in_review', 'approved', 'rejected', 'to_replace'].includes(v),
  },
})

const { t } = useI18n()

// ADR-759 (M2) : états enrichis du cycle de demande.
const statusConfig = {
  requested: { color: 'warning', icon: 'tabler-clock' },
  submitted: { color: 'info', icon: 'tabler-upload' },
  in_review: { color: 'info', icon: 'tabler-eye-search' },
  approved: { color: 'success', icon: 'tabler-check' },
  rejected: { color: 'error', icon: 'tabler-x' },
  to_replace: { color: 'warning', icon: 'tabler-refresh' },
}
</script>

<template>
  <span
    v-if="!status"
    class="text-disabled"
  >{{ t('documents.inactive') }}</span>
  <VChip
    v-else
    size="small"
    variant="tonal"
    :color="statusConfig[status]?.color"
  >
    <VIcon
      :icon="statusConfig[status]?.icon"
      size="14"
      start
    />
    {{ t(`documents.${status}`) }}
  </VChip>
</template>
