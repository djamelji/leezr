<script setup>
// Leezr Premium — carte timeline / flux d'activité (Phase 0, ADR-741).
// items: [{ title, subtitle, time, color, icon }]
import LzrWidgetFrame from './LzrWidgetFrame.vue'

defineProps({
  title: { type: String, default: 'Activité récente' },
  icon: { type: String, default: 'tabler-activity' },
  items: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  elevation: { type: String, default: 'raised' },
  to: { type: [String, Object], default: null },
})
</script>

<template>
  <LzrWidgetFrame :title="title" :icon="icon" :elevation="elevation" :loading="loading"
    :empty="!loading && !items.length" empty-text="Aucune activité" :to="to">
    <VTimeline side="end" align="start" truncate-line="both" density="compact" class="mt-2">
      <VTimelineItem v-for="(it, i) in items" :key="i" :dot-color="it.color || 'primary'" size="x-small">
        <template v-if="it.icon" #icon>
          <VIcon :icon="it.icon" size="12" />
        </template>
        <div class="d-flex justify-space-between align-start ga-2">
          <div class="min-w-0">
            <div class="text-body-2 font-weight-medium text-truncate">{{ it.title }}</div>
            <div v-if="it.subtitle" class="text-caption text-medium-emphasis">{{ it.subtitle }}</div>
          </div>
          <span v-if="it.time" class="text-caption text-medium-emphasis flex-shrink-0">{{ it.time }}</span>
        </div>
      </VTimelineItem>
    </VTimeline>
  </LzrWidgetFrame>
</template>
