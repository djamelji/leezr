<script setup>
import { usePlatformAutomationsStore } from '@/modules/platform-admin/automations/automations.store'
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

const logsDialog = ref(false)
const logsRuleId = ref(null)
const logsRuleName = ref('')

const headers = computed(() => [
  { title: t('automations.rule'), key: 'label', width: 220 },
  { title: t('automations.category'), key: 'category', width: 140 },
  { title: t('automations.status'), key: 'last_status', width: 120 },
  { title: t('automations.schedule'), key: 'schedule', width: 140 },
  { title: t('automations.lastRun'), key: 'last_run_at', width: 180 },
  { title: t('automations.nextRun'), key: 'next_run_at', width: 180 },
  { title: t('automations.enabled'), key: 'enabled', width: 100 },
  { title: t('automations.actions'), key: 'actions', sortable: false, width: 160 },
])

const statusColor = status => {
  return {
    ok: 'success',
    error: 'error',
    skipped: 'warning',
  }[status] || 'secondary'
}

const statusLabel = status => {
  return {
    ok: t('automations.statusOk'),
    error: t('automations.statusError'),
    skipped: t('automations.statusSkipped'),
  }[status] || '—'
}

const categoryLabel = cat => {
  return t(`automations.categories.${cat}`) || cat
}

const formatDate = d => {
  if (!d) return t('automations.neverRun')

  return new Date(d).toLocaleString()
}

const toggleEnabled = async rule => {
  try {
    await store.updateRule(rule.id, { enabled: !rule.enabled })
    toast(t('automations.updated'), 'success')
  }
  catch {
    toast(t('automations.runFailed'), 'error')
  }
}

const runNow = async rule => {
  try {
    await store.runRule(rule.id)
    toast(t('automations.runSuccess'), 'success')
  }
  catch {
    toast(t('automations.runFailed'), 'error')
  }
}

const openLogs = rule => {
  logsRuleId.value = rule.id
  logsRuleName.value = rule.label
  store.fetchLogs(rule.id)
  logsDialog.value = true
}

const logHeaders = computed(() => [
  { title: t('automations.status'), key: 'status', width: 100 },
  { title: t('automations.actions'), key: 'actions_count', width: 100 },
  { title: 'Duration', key: 'duration_ms', width: 100 },
  { title: 'Error', key: 'error' },
  { title: 'Date', key: 'created_at', width: 180 },
])

onMounted(() => {
  store.fetchRules()
})
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center pa-5">
        <VIcon
          icon="tabler-robot"
          class="me-2"
        />
        {{ t('automations.title') }}
      </VCardTitle>
      <VCardSubtitle class="px-5 pb-4">
        {{ t('automations.subtitle') }}
      </VCardSubtitle>

      <VDivider />

      <VDataTable
        :headers="headers"
        :items="store.rules"
        :loading="store.loading"
        item-value="id"
        class="text-no-wrap"
      >
        <!-- Label + description -->
        <template #item.label="{ item }">
          <div>
            <span class="font-weight-medium">{{ item.label }}</span>
            <div
              v-if="item.description"
              class="text-body-2 text-medium-emphasis text-truncate"
              style="max-width: 300px"
            >
              {{ item.description }}
            </div>
          </div>
        </template>

        <!-- Category chip -->
        <template #item.category="{ item }">
          <VChip
            size="small"
            variant="tonal"
          >
            {{ categoryLabel(item.category) }}
          </VChip>
        </template>

        <!-- Status chip -->
        <template #item.last_status="{ item }">
          <VChip
            v-if="item.last_status"
            :color="statusColor(item.last_status)"
            size="small"
          >
            {{ statusLabel(item.last_status) }}
          </VChip>
          <span
            v-else
            class="text-medium-emphasis"
          >—</span>
        </template>

        <!-- Schedule -->
        <template #item.schedule="{ item }">
          <code class="text-body-2">{{ item.schedule }}</code>
        </template>

        <!-- Last run -->
        <template #item.last_run_at="{ item }">
          <div>
            <span>{{ formatDate(item.last_run_at) }}</span>
            <div
              v-if="item.last_run_at && item.last_run_duration_ms != null"
              class="text-body-2 text-medium-emphasis"
            >
              {{ t('automations.duration', { ms: item.last_run_duration_ms }) }} · {{ t('automations.actionsCount', { count: item.last_run_actions }) }}
            </div>
          </div>
        </template>

        <!-- Next run -->
        <template #item.next_run_at="{ item }">
          {{ formatDate(item.next_run_at) }}
        </template>

        <!-- Enable/disable switch -->
        <template #item.enabled="{ item }">
          <VSwitch
            :model-value="item.enabled"
            density="compact"
            hide-details
            @update:model-value="toggleEnabled(item)"
          />
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn
              icon
              size="small"
              variant="text"
              color="primary"
              :loading="store.runningRuleId === item.id"
              :disabled="!!store.runningRuleId"
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
              @click="openLogs(item)"
            >
              <VIcon icon="tabler-list" />
              <VTooltip activator="parent">
                {{ t('automations.viewLogs') }}
              </VTooltip>
            </VBtn>
          </div>
        </template>

        <!-- Expanded row: last 5 runs inline -->
        <template #expanded-row="{ item }">
          <tr>
            <td :colspan="headers.length">
              <div class="pa-4">
                <div
                  v-if="item.run_logs && item.run_logs.length > 0"
                  class="d-flex gap-2 flex-wrap"
                >
                  <VChip
                    v-for="log in item.run_logs"
                    :key="log.id"
                    :color="statusColor(log.status)"
                    size="small"
                    variant="tonal"
                  >
                    {{ statusLabel(log.status) }} · {{ log.duration_ms }}ms · {{ new Date(log.created_at).toLocaleString() }}
                  </VChip>
                </div>
                <span
                  v-else
                  class="text-medium-emphasis"
                >{{ t('automations.noLogs') }}</span>
              </div>
            </td>
          </tr>
        </template>

        <!-- No data -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-robot-off"
              size="48"
              class="mb-4 text-medium-emphasis"
            />
            <p class="text-body-1 text-medium-emphasis">
              {{ t('automations.noLogs') }}
            </p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Logs dialog -->
    <VDialog
      v-model="logsDialog"
      max-width="800"
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between pa-5">
          <span>{{ t('automations.viewLogs') }} — {{ logsRuleName }}</span>
          <VBtn
            icon
            size="small"
            variant="text"
            @click="logsDialog = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VDivider />

        <VDataTable
          :headers="logHeaders"
          :items="store.logs"
          item-value="id"
          class="text-no-wrap"
        >
          <template #item.status="{ item }">
            <VChip
              :color="statusColor(item.status)"
              size="small"
            >
              {{ statusLabel(item.status) }}
            </VChip>
          </template>

          <template #item.duration_ms="{ item }">
            {{ item.duration_ms != null ? `${item.duration_ms}ms` : '—' }}
          </template>

          <template #item.error="{ item }">
            <span
              v-if="item.error"
              class="text-error text-truncate d-inline-block"
              style="max-width: 300px"
            >
              {{ item.error }}
            </span>
            <span
              v-else
              class="text-medium-emphasis"
            >—</span>
          </template>

          <template #item.created_at="{ item }">
            {{ item.created_at ? new Date(item.created_at).toLocaleString() : '—' }}
          </template>

          <template #no-data>
            <div class="text-center pa-8 text-medium-emphasis">
              {{ t('automations.noLogs') }}
            </div>
          </template>
        </VDataTable>
      </VCard>
    </VDialog>
  </div>
</template>
