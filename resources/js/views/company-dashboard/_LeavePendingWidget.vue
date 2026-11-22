<script setup>
// ADR-745 → ADR-856 — « Congés à traiter » (workforce.leave_pending).
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const has = computed(() => d.value.pending !== undefined)
const pending = computed(() => d.value.pending ?? 0)
const to = { name: 'company-workforce-leave' }
</script>

<template>
  <KpiShell
    icon="tabler-beach"
    :color="pending > 0 ? 'info' : 'success'"
    :label="t('workforce.widgets.leaveLabel')"
    :value="has ? pending : null"
    :caption="has ? (pending > 0 ? t('l4.kpi.leaveWaiting') : t('workforce.widgets.leaveNone')) : ''"
    :empty-hint="t('l4.kpi.noLeave')"
    :loading="loading"
    :error="!!d.error"
    :to="to"
    :link-label="t('workforce.widgets.actionApprove')"
  >
    <template #action>
      <VBtn
        v-if="pending > 0"
        :to="to"
        variant="flat"
        size="x-small"
        class="lzr-cta"
      >
        {{ t('workforce.widgets.actionApprove') }}
      </VBtn>
    </template>
  </KpiShell>
</template>
