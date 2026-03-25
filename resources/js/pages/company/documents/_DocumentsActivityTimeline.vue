<script setup>
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'

const { t } = useI18n()
const store = useCompanyDocumentsStore()

const actionConfig = action => {
  switch (action) {
    case 'document.requested':
      return { color: 'info', icon: 'tabler-send', label: t('companyDocuments.activity.requested') }
    case 'document.batch_requested':
      return { color: 'primary', icon: 'tabler-send-2', label: t('companyDocuments.activity.batchRequested') }
    case 'document.request_cancelled':
      return { color: 'secondary', icon: 'tabler-x', label: t('companyDocuments.activity.cancelled') }
    default:
      return { color: 'info', icon: 'tabler-file', label: action }
  }
}

const formatTimeAgo = dateStr => {
  if (!dateStr) return ''
  const now = new Date()
  const date = new Date(dateStr)
  const diffMs = now - date
  const diffMin = Math.floor(diffMs / 60000)
  const diffHour = Math.floor(diffMs / 3600000)
  const diffDay = Math.floor(diffMs / 86400000)

  if (diffMin < 1) return t('companyDocuments.activity.justNow')
  if (diffMin < 60) return t('companyDocuments.activity.minutesAgo', { n: diffMin })
  if (diffHour < 24) return t('companyDocuments.activity.hoursAgo', { n: diffHour })

  return t('companyDocuments.activity.daysAgo', { n: diffDay })
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="success"
          variant="tonal"
          rounded
        >
          <VIcon icon="tabler-activity" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('companyDocuments.activity.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('companyDocuments.activity.hint') }}</VCardSubtitle>
    </VCardItem>
    <VCardText>
      <VTimeline
        v-if="store.activity.length > 0"
        side="end"
        align="start"
        line-inset="8"
        truncate-line="start"
        density="compact"
      >
        <VTimelineItem
          v-for="entry in store.activity"
          :key="entry.id"
          :dot-color="actionConfig(entry.action).color"
          size="x-small"
        >
          <div class="d-flex justify-space-between align-center gap-2 flex-wrap mb-1">
            <span class="app-timeline-title">
              {{ actionConfig(entry.action).label }}
            </span>
            <span class="app-timeline-meta">{{ formatTimeAgo(entry.created_at) }}</span>
          </div>
          <div
            v-if="entry.actor"
            class="app-timeline-text mt-1"
          >
            {{ entry.actor.name }}
          </div>
        </VTimelineItem>
      </VTimeline>

      <VAlert
        v-else
        type="info"
        variant="tonal"
      >
        {{ t('companyDocuments.activity.empty') }}
      </VAlert>
    </VCardText>
  </VCard>
</template>
