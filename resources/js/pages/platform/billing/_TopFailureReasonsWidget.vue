<script setup>
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

const reasons = computed(() => props.data?.reasons || [])
</script>

<template>
  <div
    class="widget-root"
    :class="`density-${density}`"
  >
    <div class="d-flex justify-space-between align-center widget-header">
      <div class="d-flex align-center gap-2 overflow-hidden">
        <VAvatar
          color="error"
          variant="tonal"
          :size="avatarSize"
          rounded
        >
          <VIcon
            icon="tabler-alert-circle"
            :size="iconSize"
          />
        </VAvatar>
        <span class="widget-title text-medium-emphasis">
          {{ t('platformBilling.widgets.topFailureReasons') }}
        </span>
      </div>
      <VChip
        v-if="reasons.length"
        size="x-small"
        variant="tonal"
        color="error"
      >
        {{ reasons.length }}
      </VChip>
    </div>

    <div class="widget-body">
      <VSkeletonLoader
        v-if="loading"
        type="list-item-two-line"
      />
      <div
        v-else-if="!reasons.length"
        class="d-flex align-center justify-center h-100 text-disabled"
      >
        {{ t('platformBilling.widgets.noData') }}
      </div>

      <VList
        v-else-if="density !== 'S'"
        density="compact"
        class="widget-list"
      >
        <VListItem
          v-for="(item, idx) in reasons"
          :key="idx"
          class="px-0"
        >
          <VListItemTitle class="text-body-2 text-capitalize">
            {{ item.reason }}
          </VListItemTitle>
          <template #append>
            <VChip
              size="x-small"
              variant="tonal"
              color="error"
            >
              {{ item.count }}
            </VChip>
          </template>
        </VListItem>
      </VList>
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
  min-height: 0;
  overflow-y: auto;
}

.widget-list {
  background: transparent !important;
}

.density-S .widget-title { font-size: 14px; }
.density-M .widget-title { font-size: 16px; }
.density-L .widget-title { font-size: 18px; }
</style>
