<script setup>
import { usePlatformSecurityStore } from '@/modules/platform-admin/security/security.store'
import { formatDateTime } from '@/utils/datetime'
import SecurityRealtime from './_SecurityRealtime.vue'

const { t } = useI18n()

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.security' } })

const securityStore = usePlatformSecurityStore()
const isLoading = ref(true)
const activeTab = ref('alerts')

// Filters
const filterStatus = ref('')
const filterSeverity = ref('')

const statusOptions = computed(() => [
  { title: t('common.all'), value: '' },
  { title: t('security.open'), value: 'open' },
  { title: t('security.acknowledged'), value: 'acknowledged' },
  { title: t('security.resolved'), value: 'resolved' },
  { title: t('security.falsePositive'), value: 'false_positive' },
])

const severityOptions = computed(() => [
  { title: t('common.all'), value: '' },
  { title: t('security.low'), value: 'low' },
  { title: t('security.medium'), value: 'medium' },
  { title: t('security.high'), value: 'high' },
  { title: t('security.critical'), value: 'critical' },
])

const resolveSeverityColor = severity => {
  if (severity === 'critical') return 'error'
  if (severity === 'high') return 'warning'
  if (severity === 'medium') return 'info'

  return 'secondary'
}

const resolveStatusColor = status => {
  if (status === 'open') return 'error'
  if (status === 'acknowledged') return 'warning'
  if (status === 'resolved') return 'success'

  return 'secondary'
}

const headers = computed(() => [
  { title: t('security.createdAt'), key: 'created_at', width: '180px' },
  { title: t('security.alertType'), key: 'alert_type' },
  { title: t('security.severity'), key: 'severity', width: '100px' },
  { title: t('security.status'), key: 'status', width: '130px' },
  { title: t('security.company'), key: 'company_id', width: '100px' },
  { title: t('security.evidence'), key: 'data-table-expand', width: '80px' },
  { title: t('common.actions'), key: 'actions', width: '150px', sortable: false },
])

const expanded = ref([])

const buildParams = (page = 1) => {
  const params = { page, per_page: 25 }

  if (filterStatus.value) params.status = filterStatus.value
  if (filterSeverity.value) params.severity = filterSeverity.value

  return params
}

const loadData = async (page = 1) => {
  isLoading.value = true

  try {
    await securityStore.fetchAlerts(buildParams(page))
  }
  finally {
    isLoading.value = false
  }
}

watch([filterStatus, filterSeverity], () => loadData())

onMounted(() => loadData())

const onAcknowledge = async id => {
  await securityStore.acknowledgeAlert(id)
}

const onResolve = async id => {
  await securityStore.resolveAlert(id)
}

const onFalsePositive = async id => {
  await securityStore.markFalsePositive(id)
}
</script>

<template>
  <div>
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab value="alerts">
        <VIcon
          size="20"
          start
          icon="tabler-shield-lock"
        />
        {{ t('security.alertsTab') }}
      </VTab>
      <VTab value="monitoring">
        <VIcon
          size="20"
          start
          icon="tabler-broadcast"
        />
        {{ t('security.monitoringTab') }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <!-- Alerts Tab -->
      <VWindowItem value="alerts">
        <VCard>
          <VCardTitle class="d-flex align-center">
            <VIcon
              icon="tabler-shield-lock"
              class="me-2"
            />
            {{ t('security.alertsTitle') }}
          </VCardTitle>
          <VCardSubtitle>{{ t('security.alertsSubtitle') }}</VCardSubtitle>

          <VDivider />

          <!-- Filters -->
          <VCardText class="d-flex gap-4">
            <AppSelect
              v-model="filterStatus"
              :items="statusOptions"
              :label="t('security.status')"
              clearable
              style="max-inline-size: 200px;"
            />
            <AppSelect
              v-model="filterSeverity"
              :items="severityOptions"
              :label="t('security.severity')"
              clearable
              style="max-inline-size: 200px;"
            />
          </VCardText>

          <VDataTable
            v-model:expanded="expanded"
            :headers="headers"
            :items="securityStore.alerts"
            :loading="isLoading"
            :items-per-page="-1"
            hide-default-footer
            show-expand
            item-value="id"
          >
            <template #item.created_at="{ item }">
              <span class="text-body-2 text-no-wrap">{{ formatDateTime(item.created_at) }}</span>
            </template>

            <template #item.alert_type="{ item }">
              <code class="text-body-2">{{ item.alert_type }}</code>
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

            <template #item.status="{ item }">
              <VChip
                size="small"
                variant="tonal"
                :color="resolveStatusColor(item.status)"
              >
                {{ item.status }}
              </VChip>
            </template>

            <template #item.company_id="{ item }">
              {{ item.company_id ?? '—' }}
            </template>

            <template #item.actions="{ item }">
              <div class="d-flex gap-1">
                <VBtn
                  v-if="item.status === 'open'"
                  size="small"
                  variant="tonal"
                  color="warning"
                  @click="onAcknowledge(item.id)"
                >
                  {{ t('security.acknowledge') }}
                </VBtn>
                <VBtn
                  v-if="item.status === 'open' || item.status === 'acknowledged'"
                  size="small"
                  variant="tonal"
                  color="success"
                  @click="onResolve(item.id)"
                >
                  {{ t('security.resolve') }}
                </VBtn>
                <VBtn
                  v-if="item.status !== 'false_positive' && item.status !== 'resolved'"
                  size="small"
                  variant="tonal"
                  color="secondary"
                  @click="onFalsePositive(item.id)"
                >
                  {{ t('security.falsePositive') }}
                </VBtn>
              </div>
            </template>

            <template #expanded-row="{ columns, item }">
              <tr>
                <td :colspan="columns.length">
                  <div class="pa-4">
                    <strong class="text-caption">{{ t('security.evidence') }}:</strong>
                    <pre class="text-caption mt-1">{{ JSON.stringify(item.evidence, null, 2) }}</pre>
                  </div>
                </td>
              </tr>
            </template>

            <template #no-data>
              <div class="text-center pa-4 text-disabled">
                {{ t('security.noAlerts') }}
              </div>
            </template>
          </VDataTable>

          <!-- Pagination -->
          <VCardText
            v-if="securityStore.alertsPagination.last_page > 1"
            class="d-flex justify-center"
          >
            <VPagination
              :model-value="securityStore.alertsPagination.current_page"
              :length="securityStore.alertsPagination.last_page"
              @update:model-value="loadData"
            />
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- Monitoring Tab -->
      <VWindowItem value="monitoring">
        <SecurityRealtime />
      </VWindowItem>
    </VWindow>
  </div>
</template>
