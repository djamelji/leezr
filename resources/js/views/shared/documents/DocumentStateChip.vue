<script setup>
/**
 * ADR-810.1 — ONE state per piece, same label everywhere (person's file, My space).
 * `state` is the consolidated state computed by the server (DocumentComplianceStateService) ;
 * a valid piece whose request was approved reads « Approuvé ». No state is derived client-side
 * beyond that wording : the backend states are unchanged.
 */
const props = defineProps({
  state: { type: String, default: null },
  requestStatus: { type: String, default: null },
})

const { t } = useI18n()

const COLOR = { missing: 'secondary', requested: 'info', pending_validation: 'warning', rejected: 'error', valid: 'success', approved: 'success', expiring_soon: 'warning', expired: 'error' }

const key = computed(() => {
  const state = props.state || 'missing'

  return state === 'valid' && props.requestStatus === 'approved' ? 'approved' : state
})
</script>

<template>
  <VChip
    :color="COLOR[key] || 'secondary'"
    size="small"
    variant="tonal"
    label
  >
    {{ t(`documents.state.${key}`) }}
  </VChip>
</template>
