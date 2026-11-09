<script setup>
// ADR-745 — KPI compact « Feuilles à valider » + action contextuelle.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const pending = computed(() => d.value.pending ?? 0)
</script>

<template>
  <KpiShell
    icon="tabler-clipboard-check" :color="pending > 0 ? 'warning' : 'success'"
    :label="t('workforce.widgets.timesheetLabel')"
    :value="pending" :tone="pending > 0 ? '' : 'positive'"
    :caption="pending > 0 ? '' : t('workforce.widgets.timesheetUpToDate')"
    :loading="loading" :error="d.error"
  >
    <template #action>
      <VBtn v-if="pending > 0" :to="{ name: 'company-workforce-time', query: { tab: 'timesheets' } }" variant="flat" size="x-small" class="lzr-cta">
        {{ t('workforce.widgets.actionValidate') }}
      </VBtn>
    </template>
  </KpiShell>
</template>
