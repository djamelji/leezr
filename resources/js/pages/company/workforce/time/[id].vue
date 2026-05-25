<script setup>
import { useTimesheetsStore } from '@/modules/company/workforce/timesheets.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.timesheet_view' } })

const store = useTimesheetsStore()
const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const { toast } = useAppToast()

// ── Data fetching ────────────────────────────────────────────
const loading = ref(true)

onMounted(async () => {
  try {
    await store.fetchTimesheet(route.params.id)
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => store.clearCurrentPeriod())

// ── Reactive state ───────────────────────────────────────────
const period = computed(() => store.currentPeriod)

// ── Format helpers ───────────────────────────────────────────
const formatHours = minutes => {
  if (minutes == null) return '0.0h'

  return `${(minutes / 60).toFixed(1)}h`
}

const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '-'

const getDayName = dateStr => {
  if (!dateStr) return ''
  const date = new Date(dateStr)

  return date.toLocaleDateString(locale.value, { weekday: 'short' })
}

// ── Grid headers ─────────────────────────────────────────────
const gridHeaders = computed(() => [
  { title: t('timesheets.grid.date'), key: 'date', width: 120 },
  { title: t('timesheets.grid.day'), key: 'day_name', width: 80 },
  { title: t('timesheets.grid.clockIn'), key: 'clock_in', width: 80 },
  { title: t('timesheets.grid.clockOut'), key: 'clock_out', width: 80 },
  { title: t('timesheets.grid.worked'), key: 'worked_minutes', width: 90 },
  { title: t('timesheets.grid.break'), key: 'break_minutes', width: 90 },
  { title: t('timesheets.grid.overtime'), key: 'overtime_minutes', width: 90 },
  { title: t('timesheets.grid.leave'), key: 'leave_type', width: 120 },
  { title: t('timesheets.grid.anomalies'), key: 'anomalies', width: 100 },
])

// ── Grid lines ───────────────────────────────────────────────
const formatTime = isoString => {
  if (!isoString) return '-'
  const d = new Date(isoString)

  return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}

const gridLines = computed(() => {
  if (!period.value?.lines) return []

  return period.value.lines.map(line => {
    const entries = line.source_snapshot?.time_entries || []
    const firstEntry = entries[0]
    const lastEntry = entries[entries.length - 1]

    return {
      ...line,
      day_name: getDayName(line.date),
      clock_in: firstEntry?.clock_in || null,
      clock_out: lastEntry?.clock_out || null,
    }
  })
})

const isLeaveDay = line => !!line.leave_type

// ── Action dialogs ───────────────────────────────────────────
const showApproveDialog = ref(false)
const showRejectDialog = ref(false)
const approvalNote = ref('')
const rejectionReason = ref('')
const actionLoading = ref(false)

const handleSubmit = async () => {
  actionLoading.value = true
  try {
    await store.submitTimesheet(period.value.id)
    toast(t('timesheets.submitted'), 'success')
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleApprove = async () => {
  actionLoading.value = true
  try {
    await store.approveTimesheet(period.value.id, {
      approvalNote: approvalNote.value || undefined,
    })
    toast(t('timesheets.approved'), 'success')
    showApproveDialog.value = false
    approvalNote.value = ''
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleReject = async () => {
  actionLoading.value = true
  try {
    await store.rejectTimesheet(period.value.id, {
      rejectionReason: rejectionReason.value,
    })
    toast(t('timesheets.rejected'), 'success')
    showRejectDialog.value = false
    rejectionReason.value = ''
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleLock = async () => {
  actionLoading.value = true
  try {
    await store.lockTimesheet(period.value.id)
    toast(t('timesheets.locked'), 'success')
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}

const handleReopen = async () => {
  actionLoading.value = true
  try {
    await store.reopenTimesheet(period.value.id)
    toast(t('timesheets.reopened'), 'success')
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    actionLoading.value = false
  }
}

// ── Anomalies grouping ───────────────────────────────────────
const anomaliesByType = computed(() => {
  if (!period.value?.lines) return {}

  const grouped = {}

  for (const line of period.value.lines) {
    if (!line.anomalies?.length) continue
    for (const anomaly of line.anomalies) {
      const type = anomaly.type || 'unknown'
      if (!grouped[type]) grouped[type] = []
      grouped[type].push({ ...anomaly, date: line.date })
    }
  }

  return grouped
})

const hasAnomalies = computed(() => Object.keys(anomaliesByType.value).length > 0)

const anomalyIcon = type => {
  const icons = {
    missing_clock_out: 'tabler-clock-off',
    excessive_hours: 'tabler-alert-triangle',
    missing_break: 'tabler-coffee-off',
    overlap: 'tabler-arrows-cross',
    unknown: 'tabler-help',
  }

  return icons[type] || icons.unknown
}

const anomalyColor = type => {
  const colors = {
    missing_clock_out: 'error',
    excessive_hours: 'warning',
    missing_break: 'warning',
    overlap: 'error',
    unknown: 'secondary',
  }

  return colors[type] || colors.unknown
}
</script>

<template>
  <div>
    <!-- Back button -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon
        variant="text"
        size="small"
        @click="router.push({ name: 'company-workforce-time' })"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <span class="text-body-1 text-medium-emphasis">
        {{ $t('timesheets.backToList') }}
      </span>
    </div>

    <!-- Loading state -->
    <VCard
      v-if="loading"
      class="pa-8 text-center"
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </VCard>

    <template v-else-if="period">
      <!-- Header Card -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex align-center gap-4 flex-wrap">
            <!-- Avatar -->
            <VAvatar
              color="primary"
              variant="tonal"
              size="64"
            >
              <span class="text-h5 font-weight-medium">
                {{ (period.employee?.first_name?.[0] || '') + (period.employee?.last_name?.[0] || '') }}
              </span>
            </VAvatar>

            <!-- Info -->
            <div class="flex-grow-1">
              <h4 class="text-h4 font-weight-bold">
                {{ period.employee?.first_name }} {{ period.employee?.last_name }}
              </h4>
              <div class="d-flex align-center gap-3 mt-1 flex-wrap">
                <StatusChip
                  :status="period.status"
                  domain="timesheet"
                >
                  {{ $t(`workforce.statuses.timesheet.${period.status}`) }}
                </StatusChip>
                <span class="text-body-2 text-medium-emphasis">
                  {{ formatDate(period.period_start) }} — {{ formatDate(period.period_end) }}
                </span>
              </div>
              <div class="d-flex align-center gap-4 mt-2 flex-wrap">
                <span class="text-body-2">
                  <VIcon
                    icon="tabler-clock"
                    size="16"
                    class="me-1"
                  />
                  {{ $t('timesheets.summary.worked') }}: {{ formatHours(period.total_worked_minutes) }}
                </span>
                <span class="text-body-2">
                  <VIcon
                    icon="tabler-clock-plus"
                    size="16"
                    class="me-1"
                  />
                  {{ $t('timesheets.summary.overtime') }}: {{ formatHours(period.total_overtime_minutes) }}
                </span>
                <VChip
                  v-if="period.anomalies_count > 0"
                  size="small"
                  color="error"
                  variant="tonal"
                  prepend-icon="tabler-alert-triangle"
                >
                  {{ period.anomalies_count }} {{ $t('timesheets.summary.anomalies') }}
                </VChip>
              </div>
            </div>

            <!-- Action buttons -->
            <div class="d-flex gap-2 flex-wrap">
              <!-- Draft → Submit -->
              <VBtn
                v-if="period.status === 'draft'"
                v-can="'workforce.timesheet_submit'"
                color="primary"
                prepend-icon="tabler-send"
                :loading="actionLoading"
                @click="handleSubmit"
              >
                {{ $t('timesheets.actions.submit') }}
              </VBtn>

              <!-- Submitted → Approve / Reject -->
              <VBtn
                v-if="period.status === 'submitted'"
                v-can="'workforce.timesheet_approve'"
                color="success"
                prepend-icon="tabler-check"
                :loading="actionLoading"
                @click="showApproveDialog = true"
              >
                {{ $t('timesheets.actions.approve') }}
              </VBtn>
              <VBtn
                v-if="period.status === 'submitted'"
                v-can="'workforce.timesheet_approve'"
                color="error"
                variant="outlined"
                prepend-icon="tabler-x"
                :loading="actionLoading"
                @click="showRejectDialog = true"
              >
                {{ $t('timesheets.actions.reject') }}
              </VBtn>

              <!-- Approved → Lock -->
              <VBtn
                v-if="period.status === 'approved'"
                v-can="'workforce.time_approve'"
                color="primary"
                prepend-icon="tabler-lock"
                :loading="actionLoading"
                @click="handleLock"
              >
                {{ $t('timesheets.actions.lock') }}
              </VBtn>

              <!-- Rejected → Reopen -->
              <VBtn
                v-if="period.status === 'rejected'"
                color="warning"
                prepend-icon="tabler-refresh"
                :loading="actionLoading"
                @click="handleReopen"
              >
                {{ $t('timesheets.actions.reopen') }}
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Timesheet Grid -->
      <VCard class="mb-6">
        <VCardTitle class="pa-4">
          {{ $t('timesheets.grid.title') }}
        </VCardTitle>
        <VDataTable
          :headers="gridHeaders"
          :items="gridLines"
          :items-per-page="-1"
          density="compact"
          class="text-no-wrap"
          hide-default-footer
        >
          <!-- Custom row rendering for leave-day highlighting -->
          <template #item="{ item, props: rowProps }">
            <tr
              v-bind="rowProps"
              :class="{ 'bg-light-info': isLeaveDay(item) }"
            >
              <td>{{ formatDate(item.date) }}</td>
              <td><span class="text-capitalize">{{ item.day_name }}</span></td>
              <td>
                <span class="font-weight-medium">{{ formatTime(item.clock_in) }}</span>
              </td>
              <td>
                <span class="font-weight-medium">{{ formatTime(item.clock_out) }}</span>
              </td>
              <td>
                <span :class="{ 'text-disabled': isLeaveDay(item) }">
                  {{ formatHours(item.worked_minutes) }}
                </span>
              </td>
              <td>
                <span :class="{ 'text-disabled': isLeaveDay(item) }">
                  {{ formatHours(item.break_minutes) }}
                </span>
              </td>
              <td>
                <span :class="[
                  { 'text-disabled': isLeaveDay(item) },
                  item.overtime_minutes > 0 ? 'text-warning' : '',
                ]">
                  {{ formatHours(item.overtime_minutes) }}
                </span>
              </td>
              <td>
                <VChip
                  v-if="item.leave_type"
                  size="small"
                  color="info"
                  variant="tonal"
                >
                  {{ $t(`timesheets.leaveTypes.${item.leave_type}`, item.leave_type) }}
                </VChip>
                <span
                  v-else
                  class="text-medium-emphasis"
                >-</span>
              </td>
              <td>
                <template v-if="item.anomalies?.length">
                  <VIcon
                    v-for="(anomaly, i) in item.anomalies"
                    :key="i"
                    :icon="anomalyIcon(anomaly.type)"
                    :color="anomalyColor(anomaly.type)"
                    size="20"
                    class="me-1"
                  />
                </template>
                <span
                  v-else
                  class="text-medium-emphasis"
                >-</span>
              </td>
            </tr>
          </template>
        </VDataTable>
      </VCard>

      <!-- Anomalies Summary -->
      <VCard v-if="hasAnomalies">
        <VCardTitle class="pa-4">
          <VIcon
            icon="tabler-alert-triangle"
            size="20"
            color="error"
            class="me-2"
          />
          {{ $t('timesheets.anomalies.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <div
            v-for="(items, type) in anomaliesByType"
            :key="type"
            class="mb-4"
          >
            <div class="d-flex align-center gap-2 mb-2">
              <VIcon
                :icon="anomalyIcon(type)"
                :color="anomalyColor(type)"
                size="20"
              />
              <span class="font-weight-medium">
                {{ $t(`timesheets.anomalies.types.${type}`, type) }}
              </span>
              <VChip
                size="x-small"
                :color="anomalyColor(type)"
                variant="tonal"
              >
                {{ items.length }}
              </VChip>
            </div>
            <VList
              density="compact"
              class="py-0"
            >
              <VListItem
                v-for="(anomaly, i) in items"
                :key="i"
                class="px-0"
              >
                <template #prepend>
                  <span class="text-body-2 text-medium-emphasis me-2">
                    {{ formatDate(anomaly.date) }}
                  </span>
                </template>
                <VListItemTitle class="text-body-2">
                  {{ anomaly.message || $t(`timesheets.anomalies.types.${type}`, type) }}
                </VListItemTitle>
              </VListItem>
            </VList>
          </div>
        </VCardText>
      </VCard>
    </template>

    <!-- Approve Dialog -->
    <VDialog
      v-model="showApproveDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle class="pa-4">
          {{ $t('timesheets.dialogs.approve.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-1 mb-4">
            {{ $t('timesheets.dialogs.approve.message') }}
          </p>
          <AppTextField
            v-model="approvalNote"
            :label="$t('timesheets.dialogs.approve.noteLabel')"
            :placeholder="$t('timesheets.dialogs.approve.notePlaceholder')"
          />
        </VCardText>
        <VCardActions class="justify-end pa-4">
          <VBtn
            variant="outlined"
            @click="showApproveDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="success"
            :loading="actionLoading"
            @click="handleApprove"
          >
            {{ $t('timesheets.actions.approve') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Reject Dialog -->
    <VDialog
      v-model="showRejectDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle class="pa-4">
          {{ $t('timesheets.dialogs.reject.title') }}
        </VCardTitle>
        <VDivider />
        <VCardText>
          <p class="text-body-1 mb-4">
            {{ $t('timesheets.dialogs.reject.message') }}
          </p>
          <AppTextField
            v-model="rejectionReason"
            :label="$t('timesheets.dialogs.reject.reasonLabel')"
            :placeholder="$t('timesheets.dialogs.reject.reasonPlaceholder')"
            :rules="[v => !!v || $t('validation.required')]"
          />
        </VCardText>
        <VCardActions class="justify-end pa-4">
          <VBtn
            variant="outlined"
            @click="showRejectDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            color="error"
            :loading="actionLoading"
            :disabled="!rejectionReason"
            @click="handleReject"
          >
            {{ $t('timesheets.actions.reject') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
