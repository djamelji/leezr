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

const avatarSize = computed(() => density.value === 'S' ? 28 : density.value === 'M' ? 34 : 40)
const iconSize = computed(() => density.value === 'S' ? 16 : density.value === 'M' ? 20 : 22)

const ratioColor = computed(() => {
  if (!props.data) return 'primary'
  if (props.data.ratio > 10) return 'error'
  if (props.data.ratio > 5) return 'warning'

  return 'success'
})

const formattedRevenue = computed(() => {
  if (!props.data) return '—'
  if (props.data.currency === 'MULTI') return props.data.revenue.toFixed(2)

  return formatMoney(Math.round(props.data.revenue * 100), { currency: props.data.currency || 'EUR' })
})

const formattedRefunds = computed(() => {
  if (!props.data) return '—'
  if (props.data.currency === 'MULTI') return props.data.refunds.toFixed(2)

  return formatMoney(Math.round(props.data.refunds * 100), { currency: props.data.currency || 'EUR' })
})
</script>

<template>
  <div
    class="widget-root"
    :class="`density-${density}`"
  >
    <!-- Header — Vuexy analytics pattern -->
    <div class="d-flex justify-space-between align-center widget-header">
      <div class="d-flex align-center gap-2 overflow-hidden">
        <VAvatar
          :color="ratioColor"
          variant="tonal"
          :size="avatarSize"
          rounded
        >
          <VIcon
            icon="tabler-receipt-refund"
            :size="iconSize"
          />
        </VAvatar>
        <span class="widget-title text-medium-emphasis">
          {{ t('platformBilling.widgets.refundRatio') }}
        </span>
      </div>
      <div class="d-flex align-center gap-2 flex-shrink-0">
        <VChip
          v-if="data?.currency === 'MULTI'"
          size="x-small"
          variant="tonal"
          color="warning"
        >
          {{ t('platformDashboard.engine.multiCurrency') }}
        </VChip>
      </div>
    </div>

    <!-- Body — centered, no dead space -->
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
        <!-- KPI — always visible -->
        <div
          class="widget-kpi font-weight-bold"
          :class="`text-${ratioColor}`"
        >
          {{ data.ratio.toFixed(1) }}%
        </div>

        <!-- Progress bar — always visible -->
        <VProgressLinear
          :model-value="Math.min(data.ratio, 100)"
          :color="ratioColor"
          rounded
          :height="density === 'S' ? 6 : 8"
        />

        <!-- M/L: revenue/refund breakdown -->
        <div
          v-if="density !== 'S'"
          class="widget-breakdown"
        >
          <div class="d-flex justify-space-between">
            <span class="widget-subtext text-disabled">{{ t('platformBilling.widgets.revenue') }}</span>
            <span class="widget-subtext font-weight-medium">{{ formattedRevenue }}</span>
          </div>
          <div class="d-flex justify-space-between">
            <span class="widget-subtext text-disabled">{{ t('platformBilling.widgets.refunds') }}</span>
            <span class="widget-subtext font-weight-medium text-error">{{ formattedRefunds }}</span>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.widget-root {
  height: 100%;
  display: flex;
  flex-direction: column;
}

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
  gap: 12px;
  min-height: 0;
}

.widget-breakdown {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* ── Typography scale — monotone S < M < L ── */

.density-S .widget-title { font-size: 14px; }
.density-M .widget-title { font-size: 16px; }
.density-L .widget-title { font-size: 18px; }

.density-S .widget-kpi { font-size: 20px; }
.density-M .widget-kpi { font-size: 28px; }
.density-L .widget-kpi { font-size: 34px; }

.density-S .widget-subtext { font-size: 12px; }
.density-M .widget-subtext { font-size: 13px; }
.density-L .widget-subtext { font-size: 14px; }
</style>
