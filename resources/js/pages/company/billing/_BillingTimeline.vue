<script setup>
import EmptyState from '@/core/components/EmptyState.vue'

const { t } = useI18n()

const events = ref([])
const loading = ref(true)

// Action → display metadata
const actionMeta = {
  'billing.invoice_marked_paid': { icon: 'tabler-check', color: 'success', label: 'billingTimeline.invoicePaid' },
  'billing.refund': { icon: 'tabler-receipt-refund', color: 'warning', label: 'billingTimeline.refund' },
  'billing.invoice_voided': { icon: 'tabler-file-x', color: 'secondary', label: 'billingTimeline.invoiceVoided' },
  'billing.invoice_written_off': { icon: 'tabler-file-off', color: 'secondary', label: 'billingTimeline.invoiceWrittenOff' },
  'billing.invoice_dunning_forced': { icon: 'tabler-alert-triangle', color: 'error', label: 'billingTimeline.dunningForced' },
  'billing.policy_updated': { icon: 'tabler-settings', color: 'info', label: 'billingTimeline.policyUpdated' },
  'billing.drift_detected': { icon: 'tabler-arrows-diff', color: 'warning', label: 'billingTimeline.driftDetected' },
  'billing.auto_repair_applied': { icon: 'tabler-tool', color: 'info', label: 'billingTimeline.autoRepair' },
  'billing.period_closed': { icon: 'tabler-calendar-check', color: 'primary', label: 'billingTimeline.periodClosed' },
  'webhook.payment_synced': { icon: 'tabler-credit-card', color: 'success', label: 'billingTimeline.paymentSynced' },
  'webhook.payment_failed': { icon: 'tabler-credit-card-off', color: 'error', label: 'billingTimeline.paymentFailed' },
  'webhook.refund_synced': { icon: 'tabler-receipt-refund', color: 'warning', label: 'billingTimeline.refundSynced' },
  'webhook.setup_intent_synced': { icon: 'tabler-credit-card-pay', color: 'success', label: 'billingTimeline.setupIntentSynced' },
  'webhook.dispute_created': { icon: 'tabler-gavel', color: 'error', label: 'billingTimeline.disputeCreated' },
  'plan.changed': { icon: 'tabler-switch-horizontal', color: 'primary', label: 'billingTimeline.planChanged' },
  'subscription.plan_change_requested': { icon: 'tabler-arrow-up-right', color: 'info', label: 'billingTimeline.planChangeRequested' },
  'subscription.plan_change_executed': { icon: 'tabler-check', color: 'success', label: 'billingTimeline.planChangeExecuted' },
  'subscription.plan_change_cancelled': { icon: 'tabler-x', color: 'secondary', label: 'billingTimeline.planChangeCancelled' },
  'addon.subscribed': { icon: 'tabler-puzzle', color: 'success', label: 'billingTimeline.addonSubscribed' },
  'addon.unsubscribed': { icon: 'tabler-puzzle-off', color: 'secondary', label: 'billingTimeline.addonUnsubscribed' },
}

const defaultMeta = { icon: 'tabler-point', color: 'primary', label: null }

const getMeta = action => actionMeta[action] || defaultMeta

const formatRelativeTime = dateStr => {
  const date = new Date(dateStr)
  const now = new Date()
  const diffMs = now - date
  const diffMin = Math.floor(diffMs / 60000)
  const diffH = Math.floor(diffMin / 60)
  const diffD = Math.floor(diffH / 24)

  if (diffMin < 1)
    return t('billingTimeline.justNow')
  if (diffMin < 60)
    return t('billingTimeline.minutesAgo', { count: diffMin })
  if (diffH < 24)
    return t('billingTimeline.hoursAgo', { count: diffH })
  if (diffD < 30)
    return t('billingTimeline.daysAgo', { count: diffD })

  return date.toLocaleDateString()
}

onMounted(async () => {
  try {
    const { data } = await useApi('/company/billing/timeline')

    events.value = data.value?.events || []
  }
  catch {
    events.value = []
  }
  finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <!-- Loading -->
    <div
      v-if="loading"
      class="d-flex justify-center pa-8"
    >
      <VProgressCircular indeterminate />
    </div>

    <!-- Empty state -->
    <EmptyState
      v-else-if="!events.length"
      icon="tabler-history"
      :title="t('billingTimeline.emptyTitle')"
      :description="t('billingTimeline.emptyDesc')"
    />

    <!-- Timeline (Vuexy CardAdvanceActivityTimeline pattern) -->
    <VCard v-else>
      <VCardItem>
        <template #prepend>
          <VIcon
            icon="tabler-list-details"
            size="24"
            color="high-emphasis"
            class="me-1"
          />
        </template>

        <VCardTitle>{{ t('billingTimeline.title') }}</VCardTitle>
      </VCardItem>

      <VCardText>
        <VTimeline
          side="end"
          align="start"
          line-inset="8"
          truncate-line="start"
          density="compact"
        >
          <VTimelineItem
            v-for="event in events"
            :key="event.id"
            :dot-color="getMeta(event.action).color"
            size="x-small"
          >
            <div class="d-flex justify-space-between align-center gap-2 flex-wrap mb-1">
              <div class="d-flex align-center gap-2">
                <VIcon
                  :icon="getMeta(event.action).icon"
                  :color="getMeta(event.action).color"
                  size="16"
                />
                <span class="app-timeline-title">
                  {{ getMeta(event.action).label ? t(getMeta(event.action).label) : event.action }}
                </span>
              </div>
              <span class="app-timeline-meta">{{ formatRelativeTime(event.created_at) }}</span>
            </div>

            <!-- Target info -->
            <div
              v-if="event.target_type"
              class="app-timeline-text mt-1"
            >
              {{ event.target_type }}<span v-if="event.target_id"> #{{ event.target_id }}</span>
            </div>
          </VTimelineItem>
        </VTimeline>
      </VCardText>
    </VCard>
  </div>
</template>
