<script setup>
// ADR-745 — panneau composite « Flotte & livraisons ». Données réelles
// (chauffeurs actifs, livraisons du jour réalisées/prévues, prochaine livraison).
import { computed } from 'vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const drivers = computed(() => d.value.drivers_active ?? 0)
const completed = computed(() => d.value.completed_today ?? 0)
const scheduled = computed(() => d.value.scheduled_today ?? 0)
const pct = computed(() => scheduled.value > 0 ? Math.round((completed.value / scheduled.value) * 100) : 0)
const next = computed(() => d.value.next || null)
const nextTime = computed(() => {
  if (!next.value?.scheduled_at) return ''
  try { return new Date(next.value.scheduled_at).toLocaleString('fr-FR', { weekday: 'short', hour: '2-digit', minute: '2-digit' }) }
  catch { return '' }
})
</script>

<template>
  <div class="widget-root d-flex flex-column pa-5">
    <div class="d-flex align-center justify-space-between gap-2 mb-2 flex-shrink-0">
      <div class="text-body-1 font-weight-medium text-high-emphasis">{{ t('shipments.widgets.fleet') }}</div>
      <VAvatar color="primary" variant="tonal" size="40" rounded><VIcon icon="tabler-steering-wheel" size="22" /></VAvatar>
    </div>

    <VSkeletonLoader v-if="loading" type="text" class="bg-transparent" />
    <VAlert v-else-if="d.error" type="error" variant="tonal" density="compact" :text="t('workforce.widgets.loadError')" />

    <div v-else class="flex-grow-1 d-flex flex-column gap-3">
      <!-- Chauffeurs actifs -->
      <div class="d-flex align-center gap-3">
        <VAvatar size="34" variant="tonal" color="info" rounded><VIcon icon="tabler-users" size="18" /></VAvatar>
        <div>
          <div class="text-h6 font-weight-medium text-high-emphasis">{{ drivers }}</div>
          <div class="text-caption text-medium-emphasis">{{ t('shipments.widgets.driversActive') }}</div>
        </div>
      </div>

      <!-- Livraisons du jour (réalisées / prévues) -->
      <div>
        <div class="d-flex align-center justify-space-between mb-1">
          <span class="text-body-2 text-medium-emphasis">{{ t('shipments.widgets.deliveriesToday') }}</span>
          <span class="text-body-2 font-weight-medium text-high-emphasis">{{ completed }} / {{ scheduled }}</span>
        </div>
        <VProgressLinear :model-value="pct" color="success" height="6" rounded bg-color="rgba(var(--v-theme-on-surface),0.08)" :aria-label="t('shipments.widgets.deliveriesToday')" />
      </div>

      <VDivider />

      <!-- Prochaine livraison -->
      <div>
        <div class="text-caption text-medium-emphasis mb-1">{{ t('shipments.widgets.nextDelivery') }}</div>
        <template v-if="next">
          <div class="d-flex align-center gap-2">
            <VIcon icon="tabler-map-pin" color="primary" size="18" />
            <span class="text-body-2 font-weight-medium text-high-emphasis text-truncate">{{ next.reference || '—' }}</span>
          </div>
          <div class="text-caption text-medium-emphasis text-truncate mt-1">
            {{ next.destination || '—' }}<span v-if="nextTime"> · {{ nextTime }}</span>
          </div>
        </template>
        <div v-else class="d-flex align-center gap-2 text-medium-emphasis">
          <VIcon icon="tabler-calendar-off" size="18" />
          <span class="text-body-2">{{ t('shipments.widgets.noNextDelivery') }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>.widget-root { block-size: 100%; }</style>
