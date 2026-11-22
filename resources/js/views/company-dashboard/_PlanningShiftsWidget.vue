<script setup>
// ADR-745 → ADR-856 — « Planning de la semaine » (planning.shifts, workforce.planning_view). Real data : total / unassigned.
import { computed } from 'vue'
import KpiShell from './_KpiShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const has = computed(() => d.value.total !== undefined)
const total = computed(() => d.value.total ?? 0)
const unassigned = computed(() => d.value.unassigned ?? 0)

const caption = computed(() => total.value === 0
  ? t('workforce.widgets.planningNone')
  : unassigned.value > 0 ? t('workforce.widgets.planningUnassignedLine', { n: unassigned.value }) : t('workforce.widgets.planningAllAssigned'))

const breakdown = computed(() => [
  { label: t('l4.kpi.assigned'), value: Math.max(0, total.value - unassigned.value), tone: 'success' },
  { label: t('l4.kpi.unassigned'), value: unassigned.value, tone: unassigned.value ? 'warning' : '' },
])

const to = { name: 'company-workforce-planning' }
</script>

<template>
  <KpiShell
    icon="tabler-calendar-stats"
    :color="unassigned > 0 ? 'warning' : 'primary'"
    :label="t('workforce.widgets.planningTitle')"
    :value="has ? total : null"
    :caption="has ? caption : ''"
    :empty-hint="t('l4.kpi.noPlanning')"
    :breakdown="total ? breakdown : []"
    :loading="loading"
    :error="!!d.error"
    :to="to"
    :link-label="t('l4.kpi.seePlanning')"
  >
    <template #action>
      <VBtn
        v-if="has && total === 0"
        :to="to"
        variant="flat"
        size="x-small"
        class="lzr-cta"
      >
        {{ t('workforce.widgets.actionPlan') }}
      </VBtn>
    </template>
  </KpiShell>
</template>
