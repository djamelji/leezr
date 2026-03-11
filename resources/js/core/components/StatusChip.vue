<script setup>
const props = defineProps({
  status: { type: String, required: true },
  size: { type: String, default: 'small' },
  domain: { type: String, default: 'subscription' },
})

const colorMaps = {
  subscription: {
    active: 'success',
    trialing: 'info',
    past_due: 'error',
    suspended: 'error',
    cancelled: 'secondary',
    pending: 'warning',
    pending_approval: 'warning',
  },
  invoice: {
    draft: 'secondary',
    open: 'info',
    overdue: 'error',
    paid: 'success',
    voided: 'warning',
    uncollectible: 'error',
  },
  payment: {
    succeeded: 'success',
    failed: 'error',
    pending: 'warning',
    refunded: 'info',
  },
  scheduledDebit: {
    pending: 'warning',
    processing: 'info',
    collected: 'success',
    failed: 'error',
    cancelled: 'secondary',
  },
}

const chipColor = computed(() => {
  const map = colorMaps[props.domain] || colorMaps.subscription

  return map[props.status] || 'secondary'
})
</script>

<template>
  <VChip
    :color="chipColor"
    :size
    variant="tonal"
  >
    <slot>{{ status }}</slot>
  </VChip>
</template>
