<script setup>
// ADR-856 (Leezr 5.0 L4) — « deliveries.next » (shipments.view_own). Real data : { data: { reference, destination,
// scheduled_at } }. Info family : the next stop, its time and destination ; empty = useful message, not « — ».
import { computed } from 'vue'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'company' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const next = computed(() => d.value.data || {})
const has = computed(() => !!next.value.reference)
const time = computed(() => (next.value.scheduled_at ? new Date(next.value.scheduled_at).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }) : ''))
const state = computed(() => (props.loading && !props.data ? 'loading' : d.value.error ? 'error' : !has.value ? 'empty' : 'ready'))
</script>

<template>
  <AppWidgetShell
    bare
    :title="t('shipments.widgets.nextDelivery')"
    icon="tabler-map-pin"
    color="info"
    :link="t('l4.kpi.seeMyDeliveries')"
    :to="{ name: 'company-my-deliveries' }"
    :state="state"
    empty-icon="tabler-route"
    :empty-title="t('shipments.widgets.noUpcoming')"
  >
    <template #default="{ mode }">
      <div class="dn">
        <div class="d-flex align-center gap-2 flex-wrap">
          <span class="dn-ref">{{ next.reference }}</span>
          <VChip
            v-if="time"
            size="small"
            color="info"
            variant="tonal"
            label
            prepend-icon="tabler-clock"
          >
            {{ time }}
          </VChip>
        </div>
        <div
          v-if="next.destination && mode !== 'micro'"
          class="dn-dest"
        >
          <VIcon
            icon="tabler-map-pin"
            size="16"
          />{{ next.destination }}
        </div>
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.dn { display: flex; flex-direction: column; gap: .375rem; justify-content: center; flex: 1 1 auto; }
.dn-ref { font-size: 1.375rem; font-weight: 700; letter-spacing: -.02em; }
.dn-dest { display: flex; gap: .375rem; align-items: flex-start; font-size: .875rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
</style>
