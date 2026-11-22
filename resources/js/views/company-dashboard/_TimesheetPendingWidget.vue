<script setup>
// ADR-745 → ADR-856 — « Feuilles de temps à valider » (workforce.timesheet_pending, workforce.timesheet_approve).
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const has = computed(() => d.value.pending !== undefined)
const pending = computed(() => d.value.pending ?? 0)
const to = { name: 'company-workforce-time', params: { tab: 'timesheets' } }
</script>

<template>
  <KpiShell
    icon="tabler-clipboard-check"
    :color="pending > 0 ? 'warning' : 'success'"
    :label="t('workforce.widgets.timesheetLabel')"
    :value="has ? pending : null"
    :caption="has ? (pending > 0 ? t('l4.kpi.timesheetsWaiting') : t('workforce.widgets.timesheetUpToDate')) : ''"
    :empty-hint="t('l4.kpi.noTimesheet')"
    :loading="loading"
    :error="!!d.error"
    :to="to"
    :link-label="t('workforce.widgets.actionValidate')"
  >
    <template #action>
      <VBtn
        v-if="pending > 0"
        :to="to"
        variant="flat"
        size="x-small"
        class="lzr-cta"
      >
        {{ t('workforce.widgets.actionValidate') }}
      </VBtn>
    </template>
  </KpiShell>
</template>
