<script setup>
import { formatDate } from '@/utils/datetime'

const props = defineProps({
  logs: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
})

const emit = defineEmits(['refresh'])

const { t } = useI18n()

const actionCategory = action => {
  if (['payment_received', 'invoice_created', 'plan_changed', 'wallet_admin_credit'].includes(action))
    return 'billing'
  if (['module_enabled', 'module_disabled'].includes(action))
    return 'module'
  if (['company_suspended', 'company_reactivated'].includes(action))
    return 'suspension'

  return 'default'
}

const categoryColor = category => {
  const map = { billing: 'info', module: 'success', suspension: 'error', default: 'secondary' }

  return map[category] || 'secondary'
}

const severityColor = severity => {
  const map = { info: 'info', warning: 'warning', critical: 'error' }

  return map[severity] || 'secondary'
}

const actionIcon = action => {
  const map = {
    company_suspended: 'tabler-ban',
    company_reactivated: 'tabler-check',
    plan_changed: 'tabler-switch-horizontal',
    plan_change_executed: 'tabler-switch-horizontal',
    payment_received: 'tabler-cash',
    invoice_created: 'tabler-file-invoice',
    module_enabled: 'tabler-power',
    module_disabled: 'tabler-power-off',
    wallet_admin_credit: 'tabler-wallet',
    cancel_requested: 'tabler-calendar-off',
    cancel_executed: 'tabler-calendar-off',
  }

  return map[action] || 'tabler-activity'
}

const formatDiffValue = val => {
  if (val === null || val === undefined) return '—'
  if (typeof val === 'boolean') return val ? 'true' : 'false'
  if (typeof val === 'object') return JSON.stringify(val)

  return String(val)
}

const hasDiff = log => {
  return (log.diff_before && Object.keys(log.diff_before).length > 0)
    || (log.diff_after && Object.keys(log.diff_after).length > 0)
}

const diffKeys = log => {
  const keys = new Set()
  if (log.diff_before)
    Object.keys(log.diff_before).forEach(k => keys.add(k))
  if (log.diff_after)
    Object.keys(log.diff_after).forEach(k => keys.add(k))

  return [...keys]
}
</script>

<template>
  <VCard flat border>
    <VCardTitle class="d-flex align-center">
      <VIcon icon="tabler-history" class="me-2" />
      {{ t('platformCompanyDetail.activity.title') }}
      <VSpacer />
      <VBtn
        icon
        variant="text"
        size="small"
        @click="emit('refresh')"
      >
        <VIcon icon="tabler-refresh" size="20" />
        <VTooltip activator="parent">
          {{ t('common.refresh') }}
        </VTooltip>
      </VBtn>
    </VCardTitle>

    <div v-if="loading" class="text-center pa-8">
      <VProgressCircular indeterminate />
    </div>

    <VCardText v-else-if="logs.length">
      <VTimeline
        density="compact"
        align="start"
        truncate-line="both"
      >
        <VTimelineItem
          v-for="log in logs"
          :key="log.id"
          :dot-color="categoryColor(actionCategory(log.action))"
          size="x-small"
        >
          <div class="d-flex align-center gap-2 mb-1">
            <VIcon :icon="actionIcon(log.action)" size="18" :color="categoryColor(actionCategory(log.action))" />
            <span class="text-body-1 font-weight-medium">{{ log.action }}</span>
            <VChip
              size="x-small"
              :color="severityColor(log.severity)"
              variant="tonal"
            >
              {{ log.severity }}
            </VChip>
            <span class="text-caption text-disabled">{{ formatDate(log.created_at) }}</span>
          </div>

          <!-- Actor -->
          <div v-if="log.actor_type" class="text-caption text-disabled mb-1">
            {{ log.actor_type }}{{ log.actor_id ? ` #${log.actor_id}` : '' }}
          </div>

          <!-- Readable diff -->
          <div v-if="hasDiff(log)" class="mt-1">
            <div
              v-for="key in diffKeys(log)"
              :key="key"
              class="d-flex align-center gap-2 text-body-2 mb-1"
            >
              <code class="text-caption">{{ key }}</code>
              <span v-if="log.diff_before?.[key] !== undefined" class="text-error text-caption">
                {{ formatDiffValue(log.diff_before[key]) }}
              </span>
              <VIcon v-if="log.diff_before?.[key] !== undefined && log.diff_after?.[key] !== undefined" icon="tabler-arrow-right" size="14" />
              <span v-if="log.diff_after?.[key] !== undefined" class="text-success text-caption">
                {{ formatDiffValue(log.diff_after[key]) }}
              </span>
            </div>
          </div>
        </VTimelineItem>
      </VTimeline>
    </VCardText>

    <VCardText v-else>
      <span class="text-disabled">{{ t('platformCompanyDetail.activity.noActivity') }}</span>
    </VCardText>
  </VCard>
</template>
