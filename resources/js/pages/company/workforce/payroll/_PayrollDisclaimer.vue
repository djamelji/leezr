<script setup>
// PRODUCT-UX-2 / LOT C1 — Honest capability disclaimer.
// The payroll engine computes real gross→net figures but on explicitly
// non-exhaustive, indicative scales (WorkforcePayrollRuleSeeder marks them
// "⚠️ INDICATIF — NON EXHAUSTIF"). Leezr transmits NO declaration to the
// social bodies (DSN gateway defaults to null + submit kill-switch).
// This banner must never let a user believe amounts are certified payroll
// or that a declaration has been officially filed.
defineProps({
  // Compact variant for the period list header (shorter text).
  dense: { type: Boolean, default: false },
})
</script>

<template>
  <VAlert
    type="info"
    variant="tonal"
    density="comfortable"
    class="mb-6"
    border="start"
  >
    <template #prepend>
      <VIcon icon="tabler-info-circle" />
    </template>
    <div class="text-body-2">
      <strong>{{ $t('payroll.disclaimer.title') }}</strong>
      <span v-if="!dense"> — {{ $t('payroll.disclaimer.body') }}</span>
    </div>
  </VAlert>
</template>
