<script setup>
import { formatMoney } from '@/utils/money'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const { t } = useI18n()

const density = computed(() => props.viewport?.density || 'L')
const pm = computed(() => props.viewport?.presentationMode)
const kpiScale = computed(() => props.viewport?.kpiScale || 1)

const avatarSize = computed(() => density.value === 'S' ? 28 : density.value === 'M' ? 34 : 40)
const iconSize = computed(() => density.value === 'S' ? 16 : density.value === 'M' ? 20 : 22)

const formattedAmount = computed(() => {
  if (!props.data) return '—'
  const cur = props.data.currency
  if (!cur || cur === 'MULTI') return (props.data.revenue || 0).toFixed(2)

  return formatMoney(Math.round((props.data.revenue || 0) * 100), { currency: cur })
})

const kpiFontStyle = computed(() => {
  if (!pm.value) return {}

  return { fontSize: `clamp(22px, ${28 * kpiScale.value}px, 56px)` }
})
</script>

<template>
  <div
    class="widget-root"
    :class="[`density-${density}`]"
  >
    <!-- ═══ WIL: presentationMode-driven rendering (ADR-201) ═══ -->

    <!-- micro: KPI number only, centered, scaled -->
    <template v-if="pm === 'micro'">
      <div class="widget-body widget-body--centered">
        <VSkeletonLoader
          v-if="loading"
          type="text"
        />
        <div
          v-else-if="!data"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>
        <div
          v-else
          class="widget-kpi font-weight-bold text-success"
          :style="kpiFontStyle"
        >
          {{ formattedAmount }}
        </div>
      </div>
    </template>

    <!-- compact: KPI + short label -->
    <template v-else-if="pm === 'compact'">
      <div class="widget-body widget-body--centered">
        <VSkeletonLoader
          v-if="loading"
          type="text"
        />
        <div
          v-else-if="!data"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>
        <template v-else>
          <div
            class="widget-kpi font-weight-bold text-success"
            :style="kpiFontStyle"
          >
            {{ formattedAmount }}
          </div>
          <div class="widget-subtext text-medium-emphasis">
            {{ t('platformBilling.widgets.revenueMtd') }}
          </div>
        </template>
      </div>
    </template>

    <!-- balanced: header (icon+title) + KPI + subtitle -->
    <template v-else-if="pm === 'balanced'">
      <div class="d-flex align-center gap-2 overflow-hidden widget-header">
        <VAvatar
          color="success"
          variant="tonal"
          :size="avatarSize"
          rounded
        >
          <VIcon
            icon="tabler-trending-up"
            :size="iconSize"
          />
        </VAvatar>
        <span class="widget-title text-medium-emphasis">
          {{ t('platformBilling.widgets.revenueMtd') }}
        </span>
      </div>

      <div class="widget-body">
        <VSkeletonLoader
          v-if="loading"
          type="text"
        />
        <div
          v-else-if="!data"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>
        <template v-else>
          <div
            class="widget-kpi font-weight-bold text-success"
            :style="kpiFontStyle"
          >
            {{ formattedAmount }}
          </div>
          <div class="widget-subtext text-medium-emphasis">
            {{ t('platformBilling.widgets.revenueMtdDesc') }}
          </div>
        </template>
      </div>
    </template>

    <!-- hero: header + hero KPI + subtitle, horizontal when wide (CQ) -->
    <template v-else-if="pm === 'hero'">
      <div class="d-flex align-center gap-2 overflow-hidden widget-header">
        <VAvatar
          color="success"
          variant="tonal"
          :size="avatarSize"
          rounded
        >
          <VIcon
            icon="tabler-trending-up"
            :size="iconSize"
          />
        </VAvatar>
        <span class="widget-title text-medium-emphasis">
          {{ t('platformBilling.widgets.revenueMtd') }}
        </span>
      </div>

      <div class="widget-body widget-inner">
        <VSkeletonLoader
          v-if="loading"
          type="text"
        />
        <div
          v-else-if="!data"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>
        <template v-else>
          <div
            class="widget-kpi widget-kpi--hero font-weight-bold text-success"
            :style="kpiFontStyle"
          >
            {{ formattedAmount }}
          </div>
          <div class="widget-subtext text-medium-emphasis">
            {{ t('platformBilling.widgets.revenueMtdDesc') }}
          </div>
        </template>
      </div>
    </template>

    <!-- ═══ Fallback: density-only (pm is null — flag OFF or unknown) ═══ -->
    <template v-else>
      <div
        v-if="density !== 'S'"
        class="d-flex align-center gap-2 overflow-hidden widget-header"
      >
        <VAvatar
          color="success"
          variant="tonal"
          :size="avatarSize"
          rounded
        >
          <VIcon
            icon="tabler-trending-up"
            :size="iconSize"
          />
        </VAvatar>
        <span class="widget-title text-medium-emphasis">
          {{ t('platformBilling.widgets.revenueMtd') }}
        </span>
      </div>

      <div class="widget-body">
        <VSkeletonLoader
          v-if="loading"
          type="text"
        />
        <div
          v-else-if="!data"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>
        <template v-else>
          <div class="widget-kpi font-weight-bold text-success">
            {{ formattedAmount }}
          </div>
          <div
            v-if="density !== 'S'"
            class="widget-subtext text-medium-emphasis"
          >
            {{ t('platformBilling.widgets.revenueMtdDesc') }}
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<style scoped>
.widget-root {
  height: 100%;
  display: flex;
  flex-direction: column;
}

/* ── Spacing (density — height-based, unchanged) ── */

.density-S { padding: 12px; }
.density-M { padding: 16px; }
.density-L { padding: 20px; }

.widget-header { flex: 0 0 auto; }
.density-S .widget-header { margin-bottom: 6px; }
.density-M .widget-header { margin-bottom: 8px; }
.density-L .widget-header { margin-bottom: 12px; }

.widget-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.widget-body {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
  min-height: 0;
}

.widget-body--centered {
  align-items: center;
  text-align: center;
}

/* ── Typography fallback (density — when WIL OFF) ── */

.density-S .widget-title { font-size: 14px; }
.density-M .widget-title { font-size: 16px; }
.density-L .widget-title { font-size: 18px; }

.density-S .widget-kpi { font-size: 20px; }
.density-M .widget-kpi { font-size: 28px; }
.density-L .widget-kpi { font-size: 34px; }

.density-S .widget-subtext { font-size: 12px; }
.density-M .widget-subtext { font-size: 13px; }
.density-L .widget-subtext { font-size: 14px; }

/* ── WIL: container query layout (hero mode — horizontal when wide) ── */

.widget-inner {
  flex-direction: column;
}

@container dashboard-tile (min-width: 400px) {
  .widget-inner {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

/* ── WIL: KPI CQ typography (always available via container queries) ── */

.widget-kpi--hero {
  font-size: clamp(20px, 6cqw, 56px);
}
</style>
