<script setup>
import RecentListWidgetShell from './_RecentListWidgetShell.vue'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const { t } = useI18n()
</script>

<template>
  <RecentListWidgetShell
    v-bind="props"
    icon="tabler-receipt-refund"
    :title="t('platformBilling.widgets.lastRefunds')"
    :rows="data?.items || []"
    date-field="date"
    :to="{ name: 'platform-billing-tab', params: { tab: 'credit-notes' } }"
    :link-label="t('l4.kpi.seeCreditNotes')"
    color="error"
  />
</template>
