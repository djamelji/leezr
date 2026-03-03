<script setup>
defineProps({
  status: {
    type: String,
    default: null,
    validator: v => v === null || ['requested', 'submitted', 'approved', 'rejected'].includes(v),
  },
})

const { t } = useI18n()

const statusConfig = {
  requested: { color: 'warning', icon: 'tabler-clock' },
  submitted: { color: 'info', icon: 'tabler-upload' },
  approved: { color: 'success', icon: 'tabler-check' },
  rejected: { color: 'error', icon: 'tabler-x' },
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
