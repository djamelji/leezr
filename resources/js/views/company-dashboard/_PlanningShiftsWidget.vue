<script setup>
// ADR-745 — KPI compact Planning (shifts de la semaine). Données réelles (total/unassigned).
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const total = computed(() => d.value.total ?? 0)
const unassigned = computed(() => d.value.unassigned ?? 0)
const caption = computed(() => total.value === 0
  ? t('workforce.widgets.planningNone')
  : unassigned.value > 0
    ? t('workforce.widgets.planningUnassignedLine', { n: unassigned.value })
    : t('workforce.widgets.planningAllAssigned'))
</script>

<template>
  <KpiShell
    icon="tabler-calendar-stats" :color="unassigned > 0 ? 'warning' : 'primary'"
    :label="t('workforce.widgets.planningTitle')"
    :value="total" :caption="caption"
    :loading="loading" :error="d.error"
  >
    <template #action>
      <VBtn v-if="total === 0" :to="{ name: 'company-workforce-planning' }" variant="flat" size="x-small" class="lzr-cta">
        {{ t('workforce.widgets.actionPlan') }}
      </VBtn>
    </template>
  </KpiShell>
</template>
