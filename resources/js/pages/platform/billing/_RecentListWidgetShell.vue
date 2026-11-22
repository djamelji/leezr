<script setup>
// ADR-856 (Leezr 5.0 L4) — list family shell of the billing « last … » widgets, on the shared AppWidgetShell (L0).
// Same props as before (+ to / linkLabel / emptyText). Rows follow the REAL height (familyModes.listRows), two
// columns from 760 px ; an error is never shown as « no data » ; amounts are ledger CENTS (ADR-841), unchanged.
import { formatMoney } from '@/utils/money'
import { ledgerAmountDisplay } from '@/utils/ledgerAmount'
import AppWidgetShell from '@/components/app/AppWidgetShell.vue'
import { listRows } from '@/core/surface-engine/familyModes'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({}) },
  icon: { type: String, required: true },
  title: { type: String, required: true },
  rows: { type: Array, default: () => [] },
  dateField: { type: String, default: 'date' },
  color: { type: String, default: 'primary' },
  to: { type: [String, Object], default: null },
  linkLabel: { type: String, default: '' },
  emptyText: { type: String, default: '' },
})

const { t, locale } = useI18n()
const state = computed(() => (props.loading && !props.data ? 'loading' : props.data?.error ? 'error' : !props.rows.length ? 'empty' : 'ready'))
const date = iso => (iso ? new Date(iso).toLocaleDateString(locale.value, { day: 'numeric', month: 'short' }) : '')
const amount = r => ledgerAmountDisplay(r.amount, r.currency, formatMoney)
const initials = s => (s || '?').replace(/\[[^\]]*\]\s*/g, '').split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase()
</script>

<template>
  <AppWidgetShell
    bare
    :title="title"
    :subtitle="rows.length ? t('l4.list.count', { n: rows.length }) : ''"
    :icon="icon"
    :color="color"
    :link="linkLabel"
    :to="to"
    :state="state"
    :empty-icon="icon"
    :empty-title="emptyText || t('l4.list.empty')"
  >
    <template #default="{ w, h, mode }">
      <div
        class="rl"
        :class="{ 'rl--cols': w >= 760 }"
      >
        <div
          v-for="(r, i) in rows.slice(0, listRows(h, 52, 72) * (w >= 760 ? 2 : 1))"
          :key="i"
          class="rl-row"
        >
          <VAvatar
            v-if="mode !== 'micro'"
            :color="color"
            variant="tonal"
            size="32"
          >
            <span class="text-caption font-weight-bold">{{ initials(r.company_name) }}</span>
          </VAvatar>
          <div class="min-w-0 flex-grow-1">
            <div class="text-body-2 font-weight-medium text-truncate">
              {{ r.company_name || '—' }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ date(r[dateField]) }}
            </div>
          </div>
          <span class="rl-amount">{{ amount(r) }}</span>
        </div>
      </div>
      <div
        v-if="rows.length > listRows(h, 52, 72) * (w >= 760 ? 2 : 1)"
        class="text-caption text-medium-emphasis mt-1"
      >
        {{ t('cockpit.more', { n: rows.length - listRows(h, 52, 72) * (w >= 760 ? 2 : 1) }) }}
      </div>
    </template>
  </AppWidgetShell>
</template>

<style scoped>
.rl { display: grid; gap: .125rem; }
.rl--cols { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 1.5rem; }
.rl-row { display: flex; align-items: center; gap: .75rem; padding-block: .375rem; border-block-end: 1px dashed rgba(var(--v-border-color), .14); }
.rl-amount { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
