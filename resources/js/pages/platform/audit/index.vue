<script setup>
import { usePlatformAuditStore } from '@/modules/platform-admin/audit/audit.store'
import { formatDateTime } from '@/utils/datetime'

const { t } = useI18n()

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.audit' } })

const auditStore = usePlatformAuditStore()
const isLoading = ref(true)
const activeTab = ref('platform')

// Filters
const filterAction = ref('')
const filterSeverity = ref('')

const severityOptions = [
  { title: t('audit.allSeverities'), value: '' },
  { title: t('audit.info'), value: 'info' },
  { title: t('audit.warning'), value: 'warning' },
  { title: t('audit.critical'), value: 'critical' },
]

const resolveSeverityColor = severity => {
  if (severity === 'critical') return 'error'
  if (severity === 'warning') return 'warning'

  return 'info'
}

const platformHeaders = computed(() => [
  { title: t('audit.timestamp'), key: 'created_at', width: '180px' },
  { title: t('audit.action'), key: 'action' },
  { title: t('audit.actorType'), key: 'actor_type', width: '100px' },
  { title: t('audit.target'), key: 'target_type', width: '120px' },
  { title: t('audit.targetId'), key: 'target_id', width: '100px' },
  { title: t('audit.severity'), key: 'severity', width: '100px' },
  { title: t('audit.details'), key: 'data-table-expand', width: '80px' },
])

const companyHeaders = computed(() => [
  { title: t('audit.timestamp'), key: 'created_at', width: '180px' },
  { title: t('audit.companyId'), key: 'company_id', width: '100px' },
  { title: t('audit.action'), key: 'action' },
  { title: t('audit.actorType'), key: 'actor_type', width: '100px' },
  { title: t('audit.target'), key: 'target_type', width: '120px' },
  { title: t('audit.targetId'), key: 'target_id', width: '100px' },
  { title: t('audit.severity'), key: 'severity', width: '100px' },
  { title: t('audit.details'), key: 'data-table-expand', width: '80px' },
])

const expanded = ref([])

const buildParams = (page = 1) => {
  const params = { page, per_page: 25 }

  if (filterAction.value) params.action = filterAction.value
  if (filterSeverity.value) params.severity = filterSeverity.value

  return params
}

const loadData = async (page = 1) => {
  isLoading.value = true

  try {
    if (activeTab.value === 'platform') {
      await auditStore.fetchPlatformLogs(buildParams(page))
    }
    else {
      await auditStore.fetchCompanyLogs(buildParams(page))
    }
  }
  finally {
    isLoading.value = false
  }
}

const currentLogs = computed(() =>
  activeTab.value === 'platform' ? auditStore.platformLogs : auditStore.companyLogs,
)

const currentPagination = computed(() =>
  activeTab.value === 'platform' ? auditStore.platformLogsPagination : auditStore.companyLogsPagination,
)

watch(activeTab, () => {
  expanded.value = []
  loadData()
})

watch([filterAction, filterSeverity], () => loadData())

onMounted(async () => {
  await Promise.all([
    loadData(),
    auditStore.fetchActions(),
  ])
})

const actionOptions = computed(() => [
  { title: t('audit.allActions'), value: '' },
  ...auditStore.actions.map(a => ({ title: a, value: a })),
])
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-file-search"
          class="me-2"
        />
        {{ t('audit.title') }}
      </VCardTitle>
      <VCardSubtitle>{{ t('audit.subtitle') }}</VCardSubtitle>

      <!-- Tabs -->
      <VTabs
        v-model="activeTab"
        class="mx-4 mt-2"
      >
        <VTab value="platform">
          {{ t('audit.platformTab') }}
        </VTab>
        <VTab value="company">
          {{ t('audit.companyTab') }}
        </VTab>
      </VTabs>

      <VDivider />

      <!-- Filters -->
      <VCardText class="d-flex gap-4">
        <AppSelect
          v-model="filterAction"
          :items="actionOptions"
          :label="t('audit.filterByAction')"
          clearable
          style="max-inline-size: 250px;"
        />
        <AppSelect
          v-model="filterSeverity"
          :items="severityOptions"
          :label="t('audit.filterBySeverity')"
          clearable
          style="max-inline-size: 200px;"
        />
      </VCardText>

      <!-- Platform logs table -->
      <VDataTable
        v-if="activeTab === 'platform'"
        v-model:expanded="expanded"
        :headers="platformHeaders"
        :items="currentLogs"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
        show-expand
        item-value="id"
      >
        <template #item.created_at="{ item }">
          <span class="text-body-2 text-no-wrap">{{ formatDateTime(item.created_at) }}</span>
        </template>

        <template #item.action="{ item }">
          <code class="text-body-2">{{ item.action }}</code>
        </template>

        <template #item.actor_type="{ item }">
          <VChip
            size="small"
            variant="tonal"
            :color="item.actor_type === 'admin' ? 'primary' : 'secondary'"
          >
            {{ item.actor_type }}
          </VChip>
        </template>

        <template #item.severity="{ item }">
          <VChip
            size="small"
            variant="tonal"
            :color="resolveSeverityColor(item.severity)"
          >
            {{ item.severity }}
          </VChip>
        </template>

        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length">
              <div class="pa-4">
                <div class="d-flex gap-4 flex-wrap mb-2">
                  <span class="text-caption"><strong>{{ t('audit.ipAddress') }}:</strong> {{ item.ip_address ?? '—' }}</span>
                  <span class="text-caption"><strong>{{ t('audit.userAgent') }}:</strong> {{ item.user_agent ?? '—' }}</span>
                </div>
                <div
                  v-if="item.diff_before || item.diff_after"
                  class="d-flex gap-4"
                >
                  <VCard
                    v-if="item.diff_before"
                    variant="outlined"
                    class="flex-grow-1"
                  >
                    <VCardTitle class="text-body-2 pa-2">
                      {{ t('audit.diffBefore') }}
                    </VCardTitle>
                    <VCardText class="pa-2">
                      <pre class="text-caption">{{ JSON.stringify(item.diff_before, null, 2) }}</pre>
                    </VCardText>
                  </VCard>
                  <VCard
                    v-if="item.diff_after"
                    variant="outlined"
                    class="flex-grow-1"
                  >
                    <VCardTitle class="text-body-2 pa-2">
                      {{ t('audit.diffAfter') }}
                    </VCardTitle>
                    <VCardText class="pa-2">
                      <pre class="text-caption">{{ JSON.stringify(item.diff_after, null, 2) }}</pre>
                    </VCardText>
                  </VCard>
                </div>
                <div
                  v-if="item.metadata"
                  class="mt-2"
                >
                  <strong class="text-caption">Metadata:</strong>
                  <pre class="text-caption">{{ JSON.stringify(item.metadata, null, 2) }}</pre>
                </div>
              </div>
            </td>
          </tr>
        </template>

        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('audit.noLogs') }}
          </div>
        </template>
      </VDataTable>

      <!-- Company logs table -->
      <VDataTable
        v-if="activeTab === 'company'"
        v-model:expanded="expanded"
        :headers="companyHeaders"
        :items="currentLogs"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
        show-expand
        item-value="id"
      >
        <template #item.created_at="{ item }">
          <span class="text-body-2 text-no-wrap">{{ formatDateTime(item.created_at) }}</span>
        </template>

        <template #item.action="{ item }">
          <code class="text-body-2">{{ item.action }}</code>
        </template>

        <template #item.actor_type="{ item }">
          <VChip
            size="small"
            variant="tonal"
            :color="item.actor_type === 'admin' ? 'primary' : 'secondary'"
          >
            {{ item.actor_type }}
          </VChip>
        </template>

        <template #item.severity="{ item }">
          <VChip
            size="small"
            variant="tonal"
            :color="resolveSeverityColor(item.severity)"
          >
            {{ item.severity }}
          </VChip>
        </template>

        <template #expanded-row="{ columns, item }">
          <tr>
            <td :colspan="columns.length">
              <div class="pa-4">
                <div class="d-flex gap-4 flex-wrap mb-2">
                  <span class="text-caption"><strong>{{ t('audit.ipAddress') }}:</strong> {{ item.ip_address ?? '—' }}</span>
                  <span class="text-caption"><strong>{{ t('audit.userAgent') }}:</strong> {{ item.user_agent ?? '—' }}</span>
                </div>
                <div
                  v-if="item.diff_before || item.diff_after"
                  class="d-flex gap-4"
                >
                  <VCard
                    v-if="item.diff_before"
                    variant="outlined"
                    class="flex-grow-1"
                  >
                    <VCardTitle class="text-body-2 pa-2">
                      {{ t('audit.diffBefore') }}
                    </VCardTitle>
                    <VCardText class="pa-2">
                      <pre class="text-caption">{{ JSON.stringify(item.diff_before, null, 2) }}</pre>
                    </VCardText>
                  </VCard>
                  <VCard
                    v-if="item.diff_after"
                    variant="outlined"
                    class="flex-grow-1"
                  >
                    <VCardTitle class="text-body-2 pa-2">
                      {{ t('audit.diffAfter') }}
                    </VCardTitle>
                    <VCardText class="pa-2">
                      <pre class="text-caption">{{ JSON.stringify(item.diff_after, null, 2) }}</pre>
                    </VCardText>
                  </VCard>
                </div>
                <div
                  v-if="item.metadata"
                  class="mt-2"
                >
                  <strong class="text-caption">Metadata:</strong>
                  <pre class="text-caption">{{ JSON.stringify(item.metadata, null, 2) }}</pre>
                </div>
              </div>
            </td>
          </tr>
        </template>

        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ t('audit.noLogs') }}
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VCardText
        v-if="currentPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="currentPagination.current_page"
          :length="currentPagination.last_page"
          @update:model-value="loadData"
        />
      </VCardText>
    </VCard>
  </div>
</template>
