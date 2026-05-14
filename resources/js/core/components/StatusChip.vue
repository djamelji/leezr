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
  employee: {
    active: 'success',
    inactive: 'secondary',
    on_leave: 'warning',
    suspended: 'error',
    terminated: 'error',
  },
  contract: {
    draft: 'secondary',
    active: 'success',
    suspended: 'warning',
    terminated: 'error',
  },
  leave: {
    draft: 'secondary',
    pending: 'warning',
    approved: 'success',
    consumed: 'info',
    rejected: 'error',
    cancelled: 'secondary',
  },
  shift: {
    draft: 'secondary',
    published: 'info',
    completed: 'success',
    cancelled: 'error',
  },
  timeEntry: {
    idle: 'secondary',
    working: 'success',
    on_break: 'warning',
    completed: 'info',
  },
  timesheet: {
    draft: 'secondary',
    submitted: 'info',
    approved: 'success',
    rejected: 'error',
    locked: 'primary',
  },
  payrollRun: {
    draft: 'secondary',
    computed: 'info',
    validated: 'success',
    exported: 'primary',
  },
  dsn: {
    draft: 'secondary',
    validated: 'info',
    exported: 'warning',
    submitted: 'primary',
    accepted: 'success',
    rejected: 'error',
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
