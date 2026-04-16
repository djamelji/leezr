<script setup>
import { usePlatformActivityStore } from '@/modules/platform-admin/activity/activity.store'
import { formatDateTime } from '@/utils/datetime'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveKey: 'activity',
    module: 'platform.activity',
  },
})

const { t } = useI18n()
const router = useRouter()
const store = usePlatformActivityStore()

// ── Category config ──────────────────────────────────────
const categoryConfig = {
  billing: { icon: 'tabler-receipt', color: 'info' },
  auth: { icon: 'tabler-lock', color: 'secondary' },
  admin: { icon: 'tabler-settings', color: 'primary' },
  company: { icon: 'tabler-building', color: 'success' },
  support: { icon: 'tabler-headset', color: 'warning' },
  module: { icon: 'tabler-puzzle', color: 'primary' },
  document: { icon: 'tabler-file-text', color: 'info' },
}

const severityColor = s => ({
  info: 'primary',
  warning: 'warning',
  critical: 'error',
})[s] || 'primary'

const categoryIcon = cat => categoryConfig[cat]?.icon || 'tabler-activity'
const categoryColor = cat => categoryConfig[cat]?.color || 'primary'

// ── Filter options ───────────────────────────────────────
const categoryOptions = computed(() => [
  { title: t('activity.allTypes'), value: null },
  { title: t('activity.categories.billing'), value: 'billing' },
  { title: t('activity.categories.auth'), value: 'auth' },
  { title: t('activity.categories.admin'), value: 'admin' },
  { title: t('activity.categories.company'), value: 'company' },
  { title: t('activity.categories.support'), value: 'support' },
  { title: t('activity.categories.module'), value: 'module' },
  { title: t('activity.categories.document'), value: 'document' },
])

const severityOptions = computed(() => [
  { title: t('activity.allSeverities'), value: null },
  { title: 'Info', value: 'info' },
  { title: 'Warning', value: 'warning' },
  { title: 'Critical', value: 'critical' },
])

// ── Relative time ────────────────────────────────────────
const relativeTime = dateStr => {
  if (!dateStr) return '\u2014'

  const now = new Date()
  const date = new Date(dateStr)
  const diffMs = now - date
  const diffSec = Math.floor(diffMs / 1000)
  const diffMin = Math.floor(diffSec / 60)
  const diffHours = Math.floor(diffMin / 60)
  const diffDays = Math.floor(diffHours / 24)
  const diffMonths = Math.floor(diffDays / 30)

  const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

  if (diffMin < 1) return rtf.format(-diffSec, 'second')
  if (diffHours < 1) return rtf.format(-diffMin, 'minute')
  if (diffDays < 1) return rtf.format(-diffHours, 'hour')
  if (diffMonths < 1) return rtf.format(-diffDays, 'day')

  return rtf.format(-diffMonths, 'month')
}

// ── Aggregation expand ───────────────────────────────────
const expandedGroups = ref(new Set())

const toggleExpand = itemId => {
  if (expandedGroups.value.has(itemId)) {
    expandedGroups.value.delete(itemId)
  }
  else {
    expandedGroups.value.add(itemId)
  }
}

// ── Navigate to target ───────────────────────────────────
const navigateToTarget = item => {
  if (!item.target_type || !item.target_id) return

  const routes = {
    Company: { name: 'platform-companies-id', params: { id: item.target_id } },
    Invoice: { name: 'platform-billing-invoices-id', params: { id: item.target_id } },
    User: item.company_id
      ? { name: 'platform-companies-id', params: { id: item.company_id } }
      : null,
    PlatformUser: { name: 'platform-users-id', params: { id: item.target_id } },
  }

  const route = routes[item.target_type]
  if (route) router.push(route)
}

const navigateToCompany = companyId => {
  if (!companyId) return
  router.push({ name: 'platform-companies-id', params: { id: companyId } })
}

const isClickable = item => {
  if (!item.target_type || !item.target_id) return false

  return ['Company', 'Invoice', 'PlatformUser'].includes(item.target_type)
    || (item.target_type === 'User' && item.company_id)
}

// ── Filter application ──────────────────────────────────
const selectedCategory = ref(null)
const selectedSeverity = ref(null)
const dateFrom = ref(null)
const dateTo = ref(null)

const applyFilters = () => {
  store.setFilter('type', selectedCategory.value)
  store.setFilter('severity', selectedSeverity.value)
  store.setFilter('date_from', dateFrom.value)
  store.setFilter('date_to', dateTo.value)
  store.fetchActivity(1)
}

const resetFilters = () => {
  selectedCategory.value = null
  selectedSeverity.value = null
  dateFrom.value = null
  dateTo.value = null
  store.resetFilters()
  store.fetchActivity(1)
}

const onPageChange = page => {
  store.fetchActivity(page)
}

// Watch filters for immediate application
watch([selectedCategory, selectedSeverity], () => {
  applyFilters()
})

// ── Init ─────────────────────────────────────────────────
onMounted(() => {
  store.fetchActivity()
  store.fetchTypes()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('activity.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('activity.subtitle') }}
        </p>
      </div>
      <div class="d-flex align-center gap-3">
        <VChip
          v-if="store.totalEvents > 0"
          variant="tonal"
          color="primary"
          size="small"
        >
          {{ store.totalEvents }} {{ t('activity.events') }}
        </VChip>
        <VBtn
          variant="tonal"
          prepend-icon="tabler-refresh"
          :loading="store.loading"
          @click="store.fetchActivity(store.pagination.current_page)"
        >
          {{ t('activity.refresh') }}
        </VBtn>
      </div>
    </div>

    <!-- Filters -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="selectedCategory"
              :items="categoryOptions"
              :label="t('activity.filterType')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="2"
          >
            <AppSelect
              v-model="selectedSeverity"
              :items="severityOptions"
              :label="t('activity.filterSeverity')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppTextField
              v-model="dateFrom"
              :label="t('activity.dateFrom')"
              type="date"
              density="compact"
              clearable
              @change="applyFilters"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppTextField
              v-model="dateTo"
              :label="t('activity.dateTo')"
              type="date"
              density="compact"
              clearable
              @change="applyFilters"
            />
          </VCol>
          <VCol
            cols="12"
            md="1"
            class="d-flex align-center"
          >
            <VBtn
              icon
              size="small"
              variant="text"
              color="secondary"
              @click="resetFilters"
            >
              <VIcon icon="tabler-filter-off" />
              <VTooltip activator="parent">
                {{ t('activity.resetFilters') }}
              </VTooltip>
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Loading -->
    <VProgressLinear
      v-if="store.loading"
      indeterminate
      class="mb-4"
    />

    <!-- Empty state -->
    <VCard
      v-if="!store.loading && store.items.length === 0"
      class="text-center pa-10"
    >
      <VIcon
        icon="tabler-activity-heartbeat"
        size="64"
        class="mb-4 text-medium-emphasis"
      />
      <h5 class="text-h5 mb-2">
        {{ t('activity.empty') }}
      </h5>
      <p class="text-body-1 text-medium-emphasis">
        {{ t('activity.emptyHint') }}
      </p>
    </VCard>

    <!-- Timeline -->
    <VCard v-if="store.items.length > 0">
      <VCardText class="pa-5">
        <VTimeline
          density="compact"
          side="end"
          truncate-line="both"
        >
          <VTimelineItem
            v-for="item in store.items"
            :key="item.id"
            :dot-color="severityColor(item.severity)"
            size="small"
          >
            <template #icon>
              <VIcon
                :icon="categoryIcon(item.category)"
                size="14"
                color="white"
              />
            </template>

            <!-- Main event card -->
            <VCard
              variant="outlined"
              :class="{ 'cursor-pointer': isClickable(item) }"
              @click="isClickable(item) ? navigateToTarget(item) : null"
            >
              <VCardText class="pa-3">
                <!-- Top row: description + timestamp -->
                <div class="d-flex align-center justify-space-between mb-1">
                  <span class="text-body-1 font-weight-medium">
                    {{ item.description }}
                  </span>
                  <VTooltip :text="formatDateTime(item.created_at)">
                    <template #activator="{ props }">
                      <span
                        v-bind="props"
                        class="text-caption text-disabled ms-3 text-no-wrap"
                      >
                        {{ relativeTime(item.created_at) }}
                      </span>
                    </template>
                  </VTooltip>
                </div>

                <!-- Chips row: actor + company + severity -->
                <div class="d-flex align-center gap-2 flex-wrap">
                  <!-- Actor chip -->
                  <VChip
                    v-if="item.actor_name && item.actor_type !== 'system'"
                    size="x-small"
                    variant="tonal"
                    :color="item.actor_type === 'admin' ? 'primary' : 'secondary'"
                  >
                    <VIcon
                      :icon="item.actor_type === 'admin' ? 'tabler-shield' : 'tabler-user'"
                      size="12"
                      class="me-1"
                    />
                    {{ item.actor_name }}
                  </VChip>

                  <!-- Company chip (clickable) -->
                  <VChip
                    v-if="item.company_name"
                    size="x-small"
                    variant="tonal"
                    color="success"
                    class="cursor-pointer"
                    @click.stop="navigateToCompany(item.company_id)"
                  >
                    <VIcon
                      icon="tabler-building"
                      size="12"
                      class="me-1"
                    />
                    {{ item.company_name }}
                  </VChip>

                  <!-- Severity chip (only for warning/critical) -->
                  <VChip
                    v-if="item.severity !== 'info'"
                    size="x-small"
                    :color="severityColor(item.severity)"
                  >
                    {{ item.severity }}
                  </VChip>

                  <!-- Category chip -->
                  <VChip
                    size="x-small"
                    variant="outlined"
                    :color="categoryColor(item.category)"
                  >
                    {{ t(`activity.categories.${item.category}`) }}
                  </VChip>

                  <!-- Aggregation indicator -->
                  <VChip
                    v-if="item.aggregated_count > 1"
                    size="x-small"
                    variant="tonal"
                    color="warning"
                    class="cursor-pointer"
                    @click.stop="toggleExpand(item.id)"
                  >
                    <VIcon
                      :icon="expandedGroups.has(item.id) ? 'tabler-chevron-up' : 'tabler-chevron-down'"
                      size="12"
                      class="me-1"
                    />
                    {{ item.aggregated_count }} {{ t('activity.groupedEvents') }}
                  </VChip>
                </div>

                <!-- Aggregated sub-items (accordion) -->
                <VExpandTransition>
                  <div
                    v-if="item.aggregated_count > 1 && expandedGroups.has(item.id)"
                    class="mt-3"
                  >
                    <VDivider class="mb-2" />
                    <div
                      v-for="(sub, si) in item.aggregated_items"
                      :key="si"
                      class="d-flex align-center gap-2 py-1 text-body-2"
                    >
                      <VIcon
                        icon="tabler-point"
                        size="12"
                        class="text-medium-emphasis"
                      />
                      <span>{{ sub.description }}</span>
                      <VChip
                        v-if="sub.company_name"
                        size="x-small"
                        variant="text"
                        color="success"
                        class="cursor-pointer"
                        @click.stop="navigateToCompany(sub.company_id)"
                      >
                        {{ sub.company_name }}
                      </VChip>
                    </div>
                  </div>
                </VExpandTransition>
              </VCardText>
            </VCard>
          </VTimelineItem>
        </VTimeline>
      </VCardText>

      <!-- Pagination -->
      <VDivider />
      <VCardText
        v-if="store.pagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="store.pagination.current_page"
          :length="store.pagination.last_page"
          :total-visible="7"
          density="compact"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

/* Smooth transitions for timeline items */
:deep(.v-timeline-item) {
  transition: opacity 0.3s ease;
}

:deep(.v-card--variant-outlined) {
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

:deep(.v-card--variant-outlined:hover) {
  border-color: rgba(var(--v-theme-primary), 0.4);
}
</style>
