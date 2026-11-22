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
    icon="tabler-credit-card"
    :title="t('platformBilling.widgets.lastPayments')"
    :rows="data?.items || []"
    date-field="date"
    :to="{ name: 'platform-billing-tab', params: { tab: 'payments' } }"
    :link-label="t('l4.kpi.seePayments')"
    color="primary"
  />
</template>
