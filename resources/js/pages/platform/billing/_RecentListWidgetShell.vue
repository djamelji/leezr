<script setup>
import { formatMoney } from '@/utils/money'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
  icon: { type: String, required: true },
  title: { type: String, required: true },
  rows: { type: Array, default: () => [] },
  dateField: { type: String, default: 'date' },
  color: { type: String, default: 'primary' },
})

const { t, locale } = useI18n()

const density = computed(() => props.viewport?.density || 'L')
const pm = computed(() => props.viewport?.presentationMode)

const avatarSize = computed(() => density.value === 'S' ? 28 : density.value === 'M' ? 34 : 40)
const iconSize = computed(() => density.value === 'S' ? 16 : density.value === 'M' ? 20 : 22)

// Items to display — limited to 3 in balanced mode
const visibleRows = computed(() => {
  if (pm.value === 'balanced') return props.rows.slice(0, 3)

  return props.rows
})

function formatDate(iso) {
  if (!iso) return ''
  const d = new Date(iso)

  return d.toLocaleDateString(locale.value, { day: 'numeric', month: 'short' })
}

function formatAmount(amount, currency) {
  if (amount == null) return '—'
  if (!currency || currency === 'MULTI') return amount.toFixed(2)

  return formatMoney(Math.round(amount * 100), { currency })
}
</script>

<template>
  <div
    class="widget-root"
    :class="`density-${density}`"
  >
    <!-- ═══ WIL: presentationMode-driven rendering (ADR-201) ═══ -->

    <!-- compact: header + chip count only, no list -->
    <template v-if="pm === 'compact'">
      <div class="d-flex justify-space-between align-center widget-header">
        <div class="d-flex align-center gap-2 overflow-hidden">
          <VAvatar
            :color="color"
            variant="tonal"
            :size="avatarSize"
            rounded
          >
            <VIcon
              :icon="icon"
              :size="iconSize"
            />
          </VAvatar>
          <span class="widget-title text-medium-emphasis">
            {{ title }}
          </span>
        </div>
        <VChip
          v-if="rows.length"
          size="x-small"
          variant="tonal"
          :color="color"
        >
          {{ rows.length }}
        </VChip>
      </div>
    </template>

    <!-- balanced: header + 3 items max -->
    <template v-else-if="pm === 'balanced' || pm === 'expanded'">
      <div class="d-flex justify-space-between align-center widget-header">
        <div class="d-flex align-center gap-2 overflow-hidden">
          <VAvatar
            :color="color"
            variant="tonal"
            :size="avatarSize"
            rounded
          >
            <VIcon
              :icon="icon"
              :size="iconSize"
            />
          </VAvatar>
          <span class="widget-title text-medium-emphasis">
            {{ title }}
          </span>
        </div>
        <VChip
          v-if="rows.length"
          size="x-small"
          variant="tonal"
          :color="color"
        >
          {{ rows.length }}
        </VChip>
      </div>

      <div class="widget-body">
        <VSkeletonLoader
          v-if="loading"
          type="list-item-two-line"
        />
        <div
          v-else-if="!rows.length"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>
        <VList
          v-else
          density="compact"
          class="widget-list"
        >
          <VListItem
            v-for="(row, idx) in visibleRows"
            :key="idx"
            class="px-0"
          >
            <template #prepend>
              <VAvatar
                :color="color"
                variant="tonal"
                size="32"
                rounded
              >
                <VIcon
                  :icon="icon"
                  size="16"
                />
              </VAvatar>
            </template>
            <VListItemTitle class="text-body-2">
              {{ row.company_name || '—' }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ formatDate(row[dateField]) }}
            </VListItemSubtitle>
            <template #append>
              <span class="text-body-2 font-weight-medium">
                {{ formatAmount(row.amount, row.currency) }}
              </span>
            </template>
          </VListItem>
        </VList>
      </div>
    </template>

    <!-- ═══ Fallback: density-only (pm is null — flag OFF or unknown) ═══ -->
    <template v-else>
      <!-- Header -->
      <div class="d-flex justify-space-between align-center widget-header">
        <div class="d-flex align-center gap-2 overflow-hidden">
          <VAvatar
            :color="color"
            variant="tonal"
            :size="avatarSize"
            rounded
          >
            <VIcon
              :icon="icon"
              :size="iconSize"
            />
          </VAvatar>
          <span class="widget-title text-medium-emphasis">
            {{ title }}
          </span>
        </div>
        <VChip
          v-if="rows.length"
          size="x-small"
          variant="tonal"
          :color="color"
        >
          {{ rows.length }}
        </VChip>
      </div>

      <!-- Body -->
      <div class="widget-body">
        <VSkeletonLoader
          v-if="loading"
          type="list-item-two-line"
        />
        <div
          v-else-if="!rows.length"
          class="d-flex align-center justify-center h-100 text-disabled"
        >
          {{ t('platformBilling.widgets.noData') }}
        </div>

        <!-- S: count badge only (header chip) -->
        <!-- M/L: list -->
        <VList
          v-else-if="density !== 'S'"
          density="compact"
          class="widget-list"
        >
          <VListItem
            v-for="(row, idx) in rows"
            :key="idx"
            class="px-0"
          >
            <template #prepend>
              <VAvatar
                :color="color"
                variant="tonal"
                size="32"
                rounded
              >
                <VIcon
                  :icon="icon"
                  size="16"
                />
              </VAvatar>
            </template>
            <VListItemTitle class="text-body-2">
              {{ row.company_name || '—' }}
            </VListItemTitle>
            <VListItemSubtitle class="text-caption">
              {{ formatDate(row[dateField]) }}
            </VListItemSubtitle>
            <template #append>
              <span class="text-body-2 font-weight-medium">
                {{ formatAmount(row.amount, row.currency) }}
              </span>
            </template>
          </VListItem>
        </VList>
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
  min-height: 0;
  overflow-y: auto;
}

.widget-list {
  background: transparent !important;
}

.density-S .widget-title { font-size: 14px; }
.density-M .widget-title { font-size: 16px; }
.density-L .widget-title { font-size: 18px; }

/* ── WIL: container query — spacious rows when wide ── */

@container dashboard-tile (min-width: 480px) {
  .widget-list :deep(.v-list-item) {
    padding-block: 8px;
  }
}
</style>
