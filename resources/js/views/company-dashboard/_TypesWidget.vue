<script setup>
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'

const props = defineProps({
  data: { type: Object, default: null },
  loading: { type: Boolean, default: false },
  scope: { type: String, default: 'company' },
  viewport: { type: Object, default: () => ({ density: 'L' }) },
})

const { t } = useI18n()
const store = useCompanyComplianceStore()

const density = computed(() => props.viewport?.density || 'L')
const avatarSize = computed(() => density.value === 'S' ? 28 : density.value === 'M' ? 34 : 40)
const iconSize = computed(() => density.value === 'S' ? 16 : density.value === 'M' ? 20 : 22)

const typesWithIssues = computed(() =>
  store.byType.filter(t => t.missing > 0 || t.expired > 0).length,
)

const rateColor = rate => {
  if (rate >= 80) return 'success'
  if (rate >= 50) return 'warning'

  return 'error'
}
</script>

<template>
  <div
    class="widget-root"
    :class="`density-${density}`"
  >
    <div class="d-flex justify-space-between align-center widget-header">
      <div class="d-flex align-center gap-2 overflow-hidden">
        <VAvatar
          color="primary"
          variant="tonal"
          :size="avatarSize"
          rounded
        >
          <VIcon
            icon="tabler-file-text"
            :size="iconSize"
          />
        </VAvatar>
        <span class="widget-title text-medium-emphasis">
          {{ t('compliance.types') }}
        </span>
      </div>
    </div>

    <div class="widget-body">
      <VSkeletonLoader
        v-if="store.isLoading"
        type="text"
      />
      <div
        v-else-if="!store.hasData"
        class="d-flex align-center justify-center h-100 text-disabled"
      >
        {{ t('compliance.noData') }}
      </div>

      <!-- Density S: KPI only -->
      <template v-else-if="density === 'S'">
        <div class="widget-kpi font-weight-bold text-primary">
          {{ typesWithIssues }}
        </div>
        <div class="widget-subtext text-medium-emphasis">
          {{ t('compliance.typesWithIssues') }}
        </div>
      </template>

      <!-- Density M/L: list -->
      <template v-else>
        <div class="widget-list">
          <div
            v-for="docType in store.byType"
            :key="docType.code"
            class="d-flex align-center justify-space-between py-2"
          >
            <div class="d-flex align-center gap-2">
              <span class="font-weight-medium">
                {{ docType.label }}
              </span>
              <VChip
                size="x-small"
                variant="tonal"
                color="secondary"
              >
                {{ docType.code }}
              </VChip>
            </div>
            <div class="d-flex gap-2 align-center">
              <VChip
                v-if="docType.expired > 0"
                size="small"
                color="error"
                variant="tonal"
              >
                {{ docType.expired }} {{ t('compliance.expired') }}
              </VChip>
              <VChip
                v-if="docType.missing > 0"
                size="small"
                color="warning"
                variant="tonal"
              >
                {{ docType.missing }} {{ t('compliance.missing') }}
              </VChip>
              <VChip
                size="small"
                :color="rateColor(docType.rate)"
                variant="tonal"
              >
                {{ docType.rate }}%
              </VChip>
            </div>
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

.widget-list {
  overflow-y: auto;
  flex: 1 1 auto;
  min-height: 0;
}

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
