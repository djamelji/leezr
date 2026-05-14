<script setup>
import { useLeavesStore } from '@/modules/company/workforce/leaves.store'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce_leave', permission: 'workforce.leave_request' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const store = useLeavesStore()
const employeesStore = useEmployeesStore()

// ── Table state ──────────────────────────────────────────────
const search = ref('')
const statusFilter = ref(null)
const options = ref({ page: 1, itemsPerPage: 15, sortBy: [{ key: 'date_from', order: 'desc' }] })
const loading = ref(false)

const statusOptions = [
  { title: t('workforce.statuses.leave.pending'), value: 'pending' },
  { title: t('workforce.statuses.leave.approved'), value: 'approved' },
  { title: t('workforce.statuses.leave.rejected'), value: 'rejected' },
  { title: t('workforce.statuses.leave.consumed'), value: 'consumed' },
  { title: t('workforce.statuses.leave.cancelled'), value: 'cancelled' },
]

const headers = computed(() => [
  { title: t('leaves.columns.employee'), key: 'employee', sortable: false },
  { title: t('leaves.columns.type'), key: 'leave_type', sortable: false, width: 160 },
  { title: t('leaves.columns.dates'), key: 'dates', sortable: true, width: 200 },
  { title: t('leaves.columns.duration'), key: 'days_count_hundredths', sortable: true, width: 100 },
  { title: t('leaves.columns.status'), key: 'status', sortable: true, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 120 },
])

// ── Data fetching ────────────────────────────────────────────
const fetchData = async () => {
  loading.value = true
  try {
    const sort = options.value.sortBy?.[0]

    await store.fetchLeaveRequests({
      status: statusFilter.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
    })
  } finally {
    loading.value = false
  }
}

watch([search, statusFilter], () => {
  options.value.page = 1
  fetchData()
}, { debounce: 300 })

watch(options, fetchData, { deep: true })

onMounted(async () => {
  await Promise.all([
    fetchData(),
    store.fetchLeaveTypes(),
    store.fetchStatistics(),
    employeesStore.fetchEmployees({ perPage: 200 }),
  ])
})

// ── Stats ────────────────────────────────────────────────────
const pendingCount = computed(() => store.statistics?.pending ?? 0)
const approvedCount = computed(() => store.statistics?.approved ?? 0)
const rejectedCount = computed(() => store.statistics?.rejected ?? 0)

// ── Format helpers ───────────────────────────────────────────
const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '\u2014'

const formatDuration = hundredths => {
  if (!hundredths && hundredths !== 0) return '\u2014'
  const days = (hundredths / 100).toFixed(2)

  return `${days} j`
}

const getLeaveTypeColor = leaveType => {
  if (!leaveType) return 'primary'
  const colors = {
    conges_payes: 'success',
    rtt: 'info',
    maladie: 'error',
    sans_solde: 'warning',
    maternite: 'primary',
    paternite: 'primary',
    formation: 'secondary',
  }

  return colors[leaveType.key] || 'primary'
}

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)
const formData = ref({
  employee_id: null,
  leave_type_id: null,
  date_from: '',
  date_to: '',
  days_count_hundredths: null,
  reason: '',
})
const formLoading = ref(false)

const employeeItems = computed(() =>
  employeesStore.employees.map(e => ({
    title: `${e.first_name} ${e.last_name}`,
    value: e.id,
  })),
)

const leaveTypeItems = computed(() =>
  store.leaveTypes.map(lt => ({
    title: lt.name,
    value: lt.id,
  })),
)

const resetForm = () => {
  formData.value = {
    employee_id: null,
    leave_type_id: null,
    date_from: '',
    date_to: '',
    days_count_hundredths: null,
    reason: '',
  }
}

const submitLeaveRequest = async () => {
  formLoading.value = true
  try {
    await store.createLeaveRequest({
      employeeId: formData.value.employee_id,
      leaveTypeId: formData.value.leave_type_id,
      dateFrom: formData.value.date_from,
      dateTo: formData.value.date_to,
      daysCountHundredths: formData.value.days_count_hundredths,
      reason: formData.value.reason,
    })
    toast(t('leaves.created'), 'success')
    isDrawerOpen.value = false
    resetForm()
    fetchData()
    store.fetchStatistics()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    formLoading.value = false
  }
}

// ── Approve / Reject / Cancel ────────────────────────────────
const confirmDialog = ref(false)
const confirmAction = ref(null)
const confirmTargetId = ref(null)
const confirmTitle = ref('')
const confirmText = ref('')

const openConfirm = (action, item) => {
  confirmAction.value = action
  confirmTargetId.value = item.id
  if (action === 'approve') {
    confirmTitle.value = t('leaves.confirm.approveTitle')
    confirmText.value = t('leaves.confirm.approveText', { name: item.employee?.first_name })
  } else if (action === 'reject') {
    confirmTitle.value = t('leaves.confirm.rejectTitle')
    confirmText.value = t('leaves.confirm.rejectText', { name: item.employee?.first_name })
  } else if (action === 'cancel') {
    confirmTitle.value = t('leaves.confirm.cancelTitle')
    confirmText.value = t('leaves.confirm.cancelText')
  }
  confirmDialog.value = true
}

const executeConfirmAction = async () => {
  try {
    if (confirmAction.value === 'approve') {
      await store.approveLeaveRequest(confirmTargetId.value)
      toast(t('leaves.approved'), 'success')
    } else if (confirmAction.value === 'reject') {
      await store.rejectLeaveRequest(confirmTargetId.value)
      toast(t('leaves.rejected'), 'success')
    } else if (confirmAction.value === 'cancel') {
      await store.cancelLeaveRequest(confirmTargetId.value)
      toast(t('leaves.cancelled'), 'success')
    }
    confirmDialog.value = false
    fetchData()
    store.fetchStatistics()
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
    confirmDialog.value = false
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('leaves.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('leaves.subtitle') }}
        </p>
      </div>
      <VBtn
        v-can="'workforce.leave_request'"
        color="primary"
        prepend-icon="tabler-calendar-plus"
        @click="isDrawerOpen = true"
      >
        {{ $t('leaves.newRequest') }}
      </VBtn>
    </div>

    <!-- Stats Row -->
    <VRow class="mb-6">
      <VCol
        cols="12"
        md="4"
      >
        <VCard
          variant="outlined"
          class="text-center pa-4"
        >
          <VIcon
            icon="tabler-clock"
            size="32"
            color="warning"
            class="mb-2"
          />
          <div class="text-h5 font-weight-bold">
            {{ pendingCount }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ $t('leaves.stats.pending') }}
          </div>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="4"
      >
        <VCard
          variant="outlined"
          class="text-center pa-4"
        >
          <VIcon
            icon="tabler-check"
            size="32"
            color="success"
            class="mb-2"
          />
          <div class="text-h5 font-weight-bold">
            {{ approvedCount }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ $t('leaves.stats.approved') }}
          </div>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="4"
      >
        <VCard
          variant="outlined"
          class="text-center pa-4"
        >
          <VIcon
            icon="tabler-x"
            size="32"
            color="error"
            class="mb-2"
          />
          <div class="text-h5 font-weight-bold">
            {{ rejectedCount }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ $t('leaves.stats.rejected') }}
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters + Table -->
    <VCard>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="search"
              :placeholder="$t('leaves.searchPlaceholder')"
              prepend-inner-icon="tabler-search"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            cols="12"
            md="3"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="$t('leaves.filterStatus')"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDataTableServer
        v-model:options="options"
        :headers="headers"
        :items="store.leaveRequests"
        :items-length="store.totalRequests"
        :loading="loading"
        class="text-no-wrap"
      >
        <!-- Employee -->
        <template #item.employee="{ item }">
          <div class="d-flex align-center gap-3 py-2">
            <VAvatar
              color="primary"
              variant="tonal"
              size="38"
            >
              <span class="text-sm font-weight-medium">
                {{ (item.employee?.first_name?.[0] || '') + (item.employee?.last_name?.[0] || '') }}
              </span>
            </VAvatar>
            <div class="font-weight-medium">
              {{ item.employee?.first_name }} {{ item.employee?.last_name }}
            </div>
          </div>
        </template>

        <!-- Leave Type -->
        <template #item.leave_type="{ item }">
          <VChip
            :color="getLeaveTypeColor(item.leave_type)"
            size="small"
            variant="tonal"
          >
            {{ item.leave_type?.name || '\u2014' }}
          </VChip>
        </template>

        <!-- Dates -->
        <template #item.dates="{ item }">
          {{ formatDate(item.date_from) }} &rarr; {{ formatDate(item.date_to) }}
        </template>

        <!-- Duration -->
        <template #item.days_count_hundredths="{ item }">
          {{ formatDuration(item.days_count_hundredths) }}
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="leave"
          >
            {{ $t(`workforce.statuses.leave.${item.status}`) }}
          </StatusChip>
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <div class="d-flex gap-1">
            <VBtn
              v-if="item.status === 'pending'"
              v-can="'workforce.leave_approve'"
              icon
              variant="text"
              size="x-small"
              color="success"
              :title="$t('leaves.actions.approve')"
              @click.stop="openConfirm('approve', item)"
            >
              <VIcon
                icon="tabler-check"
                size="18"
              />
            </VBtn>
            <VBtn
              v-if="item.status === 'pending'"
              v-can="'workforce.leave_approve'"
              icon
              variant="text"
              size="x-small"
              color="error"
              :title="$t('leaves.actions.reject')"
              @click.stop="openConfirm('reject', item)"
            >
              <VIcon
                icon="tabler-x"
                size="18"
              />
            </VBtn>
            <VBtn
              v-if="item.status === 'pending' || item.status === 'approved'"
              icon
              variant="text"
              size="x-small"
              color="secondary"
              :title="$t('leaves.actions.cancel')"
              @click.stop="openConfirm('cancel', item)"
            >
              <VIcon
                icon="tabler-ban"
                size="18"
              />
            </VBtn>
          </div>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-beach"
              size="48"
              color="success"
              class="mb-4"
            />
            <h6 class="text-h6 mb-2">
              {{ $t('workforce.emptyStates.leave.title') }}
            </h6>
            <p class="text-body-1 text-medium-emphasis mb-4">
              {{ $t('workforce.emptyStates.leave.description') }}
            </p>
            <VBtn
              v-can="'workforce.leave_request'"
              color="primary"
              prepend-icon="tabler-calendar-plus"
              @click="isDrawerOpen = true"
            >
              {{ $t('leaves.newRequest') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Create Leave Request Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('leaves.newRequest') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="isDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>

      <VDivider />

      <div class="pa-4">
        <VForm @submit.prevent="submitLeaveRequest">
          <VRow>
            <VCol cols="12">
              <AppSelect
                v-model="formData.employee_id"
                :items="employeeItems"
                :label="$t('leaves.fields.employee')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="formData.leave_type_id"
                :items="leaveTypeItems"
                :label="$t('leaves.fields.leaveType')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.date_from"
                :label="$t('leaves.fields.dateFrom')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.date_to"
                :label="$t('leaves.fields.dateTo')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model.number="formData.days_count_hundredths"
                :label="$t('leaves.fields.duration')"
                :hint="$t('leaves.fields.durationHint')"
                persistent-hint
                type="number"
                :rules="[v => (v !== null && v > 0) || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.reason"
                :label="$t('leaves.fields.reason')"
                type="textarea"
                rows="3"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="isDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="formLoading"
                >
                  {{ $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Confirm Dialog -->
    <VDialog
      v-model="confirmDialog"
      max-width="440"
    >
      <VCard>
        <VCardTitle class="text-h5 pa-4">
          {{ confirmTitle }}
        </VCardTitle>
        <VCardText>
          {{ confirmText }}
        </VCardText>
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="confirmDialog = false"
          >
            {{ $t('common.cancel') }}
          </VBtn>
          <VBtn
            :color="confirmAction === 'approve' ? 'success' : confirmAction === 'reject' ? 'error' : 'secondary'"
            @click="executeConfirmAction"
          >
            {{ $t('common.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
