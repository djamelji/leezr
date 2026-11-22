<script setup>
// ADR-856 (Leezr 5.0 L4) — « Causes d'échec principales » (billing.top_failure_reasons, view_billing). Data unchanged :
// { reasons: [{ reason, count }] }. List family with proportional bars ; error ≠ empty.
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { listRows } from '@/core/surface-engine/familyModes'

const props = defineProps({ data: { type: Object, default: null }, loading: { type: Boolean, default: false }, scope: { type: String, default: 'global' }, viewport: { type: Object, default: () => ({}) } })
const { t } = useI18n()
const d = computed(() => props.data || {})
const reasons = computed(() => d.value.reasons || [])
const max = computed(() => Math.max(1, ...reasons.value.map(r => r.count)))
const state = computed(() => (props.loading && !props.data ? 'loading' : d.value.error ? 'error' : !reasons.value.length ? 'empty' : 'ready'))
</script>

<template>
  <AppWidgetShell
    bare
    :title="t('platformBilling.widgets.topFailureReasons')"
    icon="tabler-alert-octagon"
    color="error"
    :link="t('l4.kpi.seePayments')"
    :to="{ name: 'platform-billing-tab', params: { tab: 'payments' } }"
    :state="state"
    empty-icon="tabler-circle-check"
    :empty-title="t('l4.trend.noFailure')"
  >
    <template #default="{ h }">
      <div class="d-flex flex-column gap-3">
        <div
          v-for="(r, i) in reasons.slice(0, listRows(h, 44, 72))"
          :key="i"
        >
          <div class="d-flex justify-space-between text-body-2 mb-1 gap-2">
            <span class="text-truncate text-capitalize">{{ r.reason }}</span><strong>{{ r.count }}</strong>
          </div>
          <div class="fr-track">
            <div
              class="fr-fill lzr-grow"
              :style="{ inlineSize: `${(r.count / max) * 100}%`, animationDelay: `${i * 60}ms` }"
            />
          </div>
        </div>
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.fr-track { block-size: 6px; border-radius: 999px; background: rgba(var(--v-theme-error), .1); overflow: hidden; }
.fr-fill { block-size: 100%; border-radius: 999px; background: rgb(var(--v-theme-error)); }
</style>
