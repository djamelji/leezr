<script setup>
import { usePlatformAutomationsStore } from '@/modules/platform-admin/automations/automations.store'
import { useRealtimeSubscription } from '@/core/realtime/useRealtimeSubscription'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveKey: 'automations',
    module: 'platform.automations',
  },
})

const { t } = useI18n()
const { toast } = useAppToast()
const store = usePlatformAutomationsStore()

// ── Drawer state ──────────────────────────────────────
const drawerVisible = ref(false)
const selectedTask = ref(null)

// ── Table headers ─────────────────────────────────────
const headers = computed(() => [
  { title: t('automations.table.task'), key: 'name', width: 240 },
  { title: t('automations.table.frequency'), key: 'frequency', width: 120 },
  { title: t('automations.table.health'), key: 'health', width: 110 },
  { title: t('automations.table.lastRun'), key: 'last_run_at', width: 170 },
  { title: t('automations.table.status'), key: 'last_status', width: 110 },
  { title: t('automations.table.duration'), key: 'last_duration_ms', width: 110 },
  { title: t('automations.table.output'), key: 'last_output', width: 200 },
  { title: t('automations.actions'), key: 'actions', sortable: false, width: 120 },
])

// ── Helpers ───────────────────────────────────────────
const healthColor = h => ({ ok: 'success', delayed: 'warning', broken: 'error' })[h] || 'secondary'
const healthLabel = h => t(`automations.health.${h}`) || h
const statusColor = s => ({ success: 'success', failed: 'error', running: 'info' })[s] || 'secondary'
const statusLabel = s => t(`automations.status${s?.charAt(0).toUpperCase()}${s?.slice(1)}`) || '—'
const freqLabel = f => t(`automations.freq.${f}`) || f

const formatDate = d => {
  if (!d) return t('automations.neverRun')

  return new Date(d).toLocaleString()
}

const truncate = (str, len = 50) => {
  if (!str) return '—'

  return str.length > len ? `${str.substring(0, len)}...` : str
}

const schedulerHealthColor = computed(() => {
  const s = store.schedulerHealth?.status
  if (s === 'ok') return 'success'
  if (s === 'silent') return 'warning'

  return 'error'
})

// ── Actions ───────────────────────────────────────────
const runNow = async task => {
  try {
    await store.runTask(task.name)
    toast(t('automations.runSuccess'), 'success')
  }
  catch {
    toast(t('automations.runFailed'), 'error')
  }
}

const openDrawer = task => {
  selectedTask.value = task
  store.fetchRuns(task.name)
  drawerVisible.value = true
}

const closeDrawer = () => {
  drawerVisible.value = false
  selectedTask.value = null
}

const loadRunsPage = page => {
  if (selectedTask.value) {
    store.fetchRuns(selectedTask.value.name, page)
  }
}

// ── Realtime ──────────────────────────────────────────
useRealtimeSubscription('automation.run.completed', () => {
  store.fetchTasks()

  // Refresh runs if drawer is open
  if (drawerVisible.value && selectedTask.value) {
    store.fetchRuns(selectedTask.value.name)
  }
})

// ── Init ──────────────────────────────────────────────
onMounted(() => {
  store.fetchTasks()
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('automations.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ t('automations.subtitle') }}
        </p>
      </div>
      <div class="d-flex align-center gap-3">
        <!-- Scheduler global health -->
        <VChip
          v-if="store.schedulerHealth"
          :color="schedulerHealthColor"
          variant="tonal"
          size="small"
        >
          <VIcon
            icon="tabler-heartbeat"
            size="16"
            class="me-1"
          />
          {{ t('automations.schedulerHealth') }}: {{ store.schedulerHealth?.status }}
        </VChip>
        <VBtn
          variant="tonal"
          prepend-icon="tabler-refresh"
          :loading="store.loading"
          @click="store.fetchTasks()"
        >
          {{ t('automations.refresh') }}
        </VBtn>
      </div>
    </div>

    <!-- KPI Cards (24h) -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold text-success">
              {{ store.summary?.success_24h ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.kpi.success24h') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold text-error">
              {{ store.summary?.failed_24h ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.kpi.failed24h') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">
              {{ store.summary?.avg_duration_ms ?? 0 }}ms
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.kpi.avgDuration') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h4 font-weight-bold">
              {{ (store.summary?.queue_default_pending ?? 0) + (store.summary?.queue_ai_pending ?? 0) }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.kpi.queuePending') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Queue Monitor -->
    <VRow class="card-grid card-grid-xs mb-6">
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold">
              {{ store.summary?.queue_default_pending ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.queue.defaultPending') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold" :class="store.summary?.queue_default_failed_24h > 0 ? 'text-error' : ''">
              {{ store.summary?.queue_default_failed_24h ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.queue.defaultFailed') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold">
              {{ store.summary?.queue_ai_pending ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.queue.aiPending') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="6"
        md="3"
      >
        <VCard>
          <VCardText class="text-center">
            <div class="text-h5 font-weight-bold" :class="store.summary?.queue_ai_failed_24h > 0 ? 'text-error' : ''">
              {{ store.summary?.queue_ai_failed_24h ?? 0 }}
            </div>
            <div class="text-caption text-medium-emphasis">
              {{ t('automations.queue.aiFailed') }}
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Tasks Table -->
    <VCard>
      <VCardTitle class="d-flex align-center pa-5">
        <VIcon
          icon="tabler-robot"
          class="me-2"
        />
        {{ t('automations.scheduledTasks') }}
      </VCardTitle>

      <VDivider />

      <VDataTable
        :headers="headers"
        :items="store.tasks"
        :loading="store.loading"
        item-value="name"
        class="text-no-wrap"
      >
        <!-- Task name + description -->
        <template #item.name="{ item }">
          <div>
            <span class="font-weight-medium">{{ item.name }}</span>
            <div class="text-body-2 text-medium-emphasis text-truncate" style="max-width: 280px">
              {{ t(item.description) }}
            </div>
          </div>
        </template>

        <!-- Frequency -->
        <template #item.frequency="{ item }">
          <VChip
            size="small"
            variant="tonal"
          >
            {{ freqLabel(item.frequency) }}
          </VChip>
        </template>

        <!-- Health badge -->
        <template #item.health="{ item }">
          <VChip
            :color="healthColor(item.health)"
            size="small"
          >
            {{ healthLabel(item.health) }}
          </VChip>
        </template>

        <!-- Last run -->
        <template #item.last_run_at="{ item }">
          {{ formatDate(item.last_run_at) }}
        </template>

        <!-- Status -->
        <template #item.last_status="{ item }">
          <VChip
            v-if="item.last_status"
            :color="statusColor(item.last_status)"
            size="small"
          >
            {{ statusLabel(item.last_status) }}
          </VChip>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <!-- Duration -->
        <template #item.last_duration_ms="{ item }">
          {{ item.last_duration_ms != null ? `${item.last_duration_ms}ms` : '—' }}
        </template>

        <!-- Output preview -->
        <template #item.last_output="{ item }">
          <span
            v-if="item.last_output"
            class="text-body-2 text-truncate d-inline-block"
            style="max-width: 200px"
            :title="item.last_output"
          >
            {{ truncate(item.last_output) }}
          </span>
          <span v-else class="text-medium-emphasis">—</span>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn
              icon
              size="small"
              variant="text"
              color="primary"
              :loading="store.runningTask === item.name"
              :disabled="!!store.runningTask"
              @click="runNow(item)"
            >
              <VIcon icon="tabler-player-play" />
              <VTooltip activator="parent">
                {{ t('automations.runNow') }}
              </VTooltip>
            </VBtn>

            <VBtn
              icon
              size="small"
              variant="text"
              @click="openDrawer(item)"
            >
              <VIcon icon="tabler-info-circle" />
              <VTooltip activator="parent">
                {{ t('automations.viewDetails') }}
              </VTooltip>
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-robot-off"
              size="48"
              class="mb-4 text-medium-emphasis"
            />
            <p class="text-body-1 text-medium-emphasis">
              {{ t('automations.noData') }}
            </p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Detail Drawer -->
    <VNavigationDrawer
      v-model="drawerVisible"
      temporary
      location="end"
      :width="520"
    >
      <template v-if="selectedTask">
        <!-- Drawer header -->
        <div class="pa-5 d-flex align-center justify-space-between">
          <div>
            <h5 class="text-h5 font-weight-bold">
              {{ selectedTask.name }}
            </h5>
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ t(selectedTask.description) }}
            </p>
          </div>
          <VBtn
            icon
            size="small"
            variant="text"
            @click="closeDrawer"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </div>

        <VDivider />

        <!-- Task info -->
        <div class="pa-5">
          <div class="d-flex align-center gap-2 mb-4">
            <VChip
              :color="healthColor(selectedTask.health)"
              size="small"
            >
              {{ healthLabel(selectedTask.health) }}
            </VChip>
            <VChip
              size="small"
              variant="tonal"
            >
              {{ freqLabel(selectedTask.frequency) }}
            </VChip>
            <code class="text-body-2">{{ selectedTask.cron }}</code>
          </div>

          <!-- Next run -->
          <div
            v-if="selectedTask.next_run_at"
            class="text-body-2 text-medium-emphasis mb-4"
          >
            {{ t('automations.nextRun') }}: {{ formatDate(selectedTask.next_run_at) }}
          </div>

          <!-- 24h stats -->
          <VRow class="card-grid card-grid-xs mb-4">
            <VCol cols="6">
              <VCard variant="outlined">
                <VCardText class="text-center pa-3">
                  <div class="text-h6 font-weight-bold text-success">
                    {{ selectedTask.success_count_24h }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('automations.drawer.success24h') }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="6">
              <VCard variant="outlined">
                <VCardText class="text-center pa-3">
                  <div class="text-h6 font-weight-bold text-error">
                    {{ selectedTask.failed_count_24h }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('automations.drawer.failed24h') }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="6">
              <VCard variant="outlined">
                <VCardText class="text-center pa-3">
                  <div class="text-h6 font-weight-bold">
                    {{ selectedTask.runs_count_24h }}
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('automations.drawer.totalRuns') }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="6">
              <VCard variant="outlined">
                <VCardText class="text-center pa-3">
                  <div class="text-h6 font-weight-bold">
                    {{ selectedTask.avg_duration_24h ?? '—' }}ms
                  </div>
                  <div class="text-caption text-medium-emphasis">
                    {{ t('automations.drawer.avgDuration') }}
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>

          <!-- Last output -->
          <div v-if="selectedTask.last_output" class="mb-4">
            <h6 class="text-subtitle-2 font-weight-medium mb-2">
              {{ t('automations.drawer.lastOutput') }}
            </h6>
            <pre class="text-body-2 pa-3 rounded" style="background: rgb(var(--v-theme-surface-variant)); overflow-x: auto; white-space: pre-wrap; max-height: 200px">{{ selectedTask.last_output }}</pre>
          </div>

          <!-- Last error -->
          <div v-if="selectedTask.last_error" class="mb-4">
            <h6 class="text-subtitle-2 font-weight-medium mb-2 text-error">
              {{ t('automations.drawer.error') }}
            </h6>
            <pre class="text-body-2 pa-3 rounded text-error" style="background: rgb(var(--v-theme-surface-variant)); overflow-x: auto; white-space: pre-wrap; max-height: 150px">{{ selectedTask.last_error }}</pre>
          </div>

          <VDivider class="mb-4" />

          <!-- Run history -->
          <h6 class="text-subtitle-2 font-weight-medium mb-3">
            {{ t('automations.drawer.runHistory') }}
          </h6>

          <VTable
            v-if="store.runs.length > 0"
            density="compact"
            class="mb-3"
          >
            <thead>
              <tr>
                <th>{{ t('automations.table.status') }}</th>
                <th>{{ t('automations.drawer.startedAt') }}</th>
                <th>{{ t('automations.table.duration') }}</th>
                <th>{{ t('automations.table.output') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="run in store.runs"
                :key="run.id"
              >
                <td>
                  <VChip
                    :color="statusColor(run.status)"
                    size="x-small"
                  >
                    {{ statusLabel(run.status) }}
                  </VChip>
                </td>
                <td class="text-body-2">
                  {{ run.started_at ? new Date(run.started_at).toLocaleString() : '—' }}
                </td>
                <td class="text-body-2">
                  {{ run.duration_ms != null ? `${run.duration_ms}ms` : '—' }}
                </td>
                <td class="text-body-2">
                  <span
                    v-if="run.output"
                    :title="run.output"
                    class="text-truncate d-inline-block"
                    style="max-width: 120px"
                  >
                    {{ truncate(run.output, 30) }}
                  </span>
                  <span v-else-if="run.error" class="text-error text-truncate d-inline-block" style="max-width: 120px" :title="run.error">
                    {{ truncate(run.error, 30) }}
                  </span>
                  <span v-else class="text-medium-emphasis">—</span>
                </td>
              </tr>
            </tbody>
          </VTable>

          <div
            v-else-if="!store.runsLoading"
            class="text-center pa-4 text-medium-emphasis"
          >
            {{ t('automations.noLogs') }}
          </div>

          <VProgressLinear
            v-if="store.runsLoading"
            indeterminate
            class="mb-3"
          />

          <!-- Pagination -->
          <div
            v-if="store.runsPagination.last_page > 1"
            class="d-flex justify-center"
          >
            <VPagination
              :model-value="store.runsPagination.current_page"
              :length="store.runsPagination.last_page"
              :total-visible="5"
              density="compact"
              @update:model-value="loadRunsPage"
            />
          </div>
        </div>
      </template>
    </VNavigationDrawer>
  </div>
</template>
