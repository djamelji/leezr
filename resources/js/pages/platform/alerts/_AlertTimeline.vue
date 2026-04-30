<script setup>
const props = defineProps({
  timeline: { type: Array, default: () => [] },
})

const { t } = useI18n()

const eventIcon = event => {
  const icons = {
    created: 'tabler-alert-triangle',
    acknowledged: 'tabler-eye-check',
    escalated: 'tabler-arrow-up',
    resolved: 'tabler-circle-check',
  }

  return icons[event] || 'tabler-circle'
}

const eventColor = event => {
  const colors = {
    created: 'error',
    acknowledged: 'info',
    escalated: 'warning',
    resolved: 'success',
  }

  return colors[event] || 'secondary'
}

const formatDate = iso => {
  if (!iso) return '--'
  try {
    return new Date(iso).toLocaleString()
  }
  catch {
    return iso
  }
}
</script>

<template>
  <div>
    <div
      v-if="!timeline.length"
      class="text-body-2 text-medium-emphasis text-center pa-4"
    >
      {{ t('incidents.noTimeline') }}
    </div>

    <VTimeline
      v-else
      density="compact"
      align="start"
      side="end"
    >
      <VTimelineItem
        v-for="(item, i) in timeline"
        :key="i"
        :dot-color="eventColor(item.event)"
        size="small"
      >
        <template #icon>
          <VIcon
            :icon="eventIcon(item.event)"
            size="16"
          />
        </template>
        <div>
          <div class="text-body-1 font-weight-medium">
            {{ t(`incidents.event.${item.event}`) }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ formatDate(item.at) }}
          </div>
          <div
            v-if="item.count"
            class="text-body-2 text-warning"
          >
            {{ t('incidents.escalationCount', { count: item.count }) }}
          </div>
        </div>
      </VTimelineItem>
    </VTimeline>
  </div>
</template>
