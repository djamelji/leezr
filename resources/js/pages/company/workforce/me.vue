<script setup>
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'

definePage({ meta: { module: 'workforce' } })

const { t, locale } = useI18n()
const router = useRouter()
const clockStore = useWorkforceClockStore()
const { toast } = useAppToast()

// ── State ───────────────────────────────────────────────────
const loading = ref(true)
const documents = ref([])
const documentsLoading = ref(false)
const clockActionLoading = ref(false)

// ── Data fetching ───────────────────────────────────────────
onMounted(async () => {
  try {
    await clockStore.fetchProfile()
    await fetchDocuments()
  }
  catch {
    toast(t('workforceMe.loadError'), 'error')
  }
  finally {
    loading.value = false
  }
})

async function fetchDocuments() {
  documentsLoading.value = true
  try {
    const data = await $api('/workforce/me/documents')

    documents.value = (data.documents || []).slice(0, 5)
  }
  catch {
    // Silently fail — documents are secondary
  }
  finally {
    documentsLoading.value = false
  }
}

// ── Reactive computed ───────────────────────────────────────
const employee = computed(() => clockStore.employee)
const todayClock = computed(() => clockStore.todayClock)
const leaveBalances = computed(() => clockStore.leaveBalances)

const employeeName = computed(() => {
  if (!employee.value) return ''

  return `${employee.value.first_name} ${employee.value.last_name}`
})

const payslipDocs = computed(() => documents.value.filter(d => d.template?.code?.startsWith('payslip_')))

const currentContract = computed(() => {
  if (!employee.value) return null

  // EmployeeReadModel::detail loads contracts relation ordered by start_date desc
  const contracts = employee.value.contracts || []

  return contracts.find(c => c.is_current) || contracts[0] || null
})

// ── Clock status helpers ────────────────────────────────────
const clockStatusConfig = computed(() => {
  const status = clockStore.clockStatus

  const configs = {
    working: { icon: 'tabler-player-play', color: 'success', labelKey: 'workforceMe.status.working' },
    on_break: { icon: 'tabler-coffee', color: 'warning', labelKey: 'workforceMe.status.onBreak' },
    completed: { icon: 'tabler-check', color: 'info', labelKey: 'workforceMe.status.completed' },
    not_started: { icon: 'tabler-clock', color: 'secondary', labelKey: 'workforceMe.status.notStarted' },
  }

  return configs[status] || configs.not_started
})

// ── Time formatting ─────────────────────────────────────────
function formatMinutes(minutes) {
  if (!minutes || minutes <= 0) return '0h00'
  const h = Math.floor(minutes / 60)
  const m = minutes % 60

  return `${h}h${String(m).padStart(2, '0')}`
}

function formatTime(isoString) {
  if (!isoString) return '--:--'
  const d = new Date(isoString)

  return d.toLocaleTimeString(locale.value, { hour: '2-digit', minute: '2-digit' })
}

function formatDate(dateString) {
  if (!dateString) return '-'

  return new Date(dateString).toLocaleDateString(locale.value, { day: 'numeric', month: 'short', year: 'numeric' })
}

// ── Contract type labels ────────────────────────────────────
async function handleClockAction(actionFn) {
  clockActionLoading.value = true
  try {
    await actionFn()
    toast(t('workforceMe.clockActionSuccess'), 'success')
  }
  catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
  finally {
    clockActionLoading.value = false
  }
}

function contractTypeLabel(type) {
  return t(`employees.contractTypes.${type}`, type?.toUpperCase() ?? '-')
}

function workModelLabel(key) {
  return t(`employees.workModels.${key}`, key ?? '-')
}

// ── Signature status ────────────────────────────────────────
function signatureChipProps(status) {
  const map = {
    none: { color: 'secondary', text: t('workforceMe.signatureNone') },
    pending: { color: 'warning', text: t('workforceMe.signaturePending') },
    signed: { color: 'success', text: t('workforceMe.signatureSigned') },
    declined: { color: 'error', text: t('workforceMe.signatureDeclined') },
  }

  return map[status] || map.none
}
</script>

<template>
  <div>
    <!-- Page Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ t('workforceMe.title') }}
        </h4>
        <p
          v-if="employee"
          class="text-body-1 text-medium-emphasis mb-0"
        >
          {{ t('workforceMe.welcome', { name: employeeName }) }}
        </p>
      </div>
      <div class="d-flex gap-2">
        <!-- Clock actions -->
        <VBtn
          v-if="clockStore.clockStatus === 'not_started'"
          color="success"
          prepend-icon="tabler-player-play"
          :loading="clockActionLoading"
          @click="handleClockAction(clockStore.clockIn)"
        >
          {{ t('workforceClock.clockIn') }}
        </VBtn>
        <template v-else-if="clockStore.isWorking">
          <VBtn
            color="warning"
            variant="tonal"
            prepend-icon="tabler-coffee"
            :loading="clockActionLoading"
            @click="handleClockAction(clockStore.startBreak)"
          >
            {{ t('workforceClock.startBreak') }}
          </VBtn>
          <VBtn
            color="error"
            variant="tonal"
            prepend-icon="tabler-player-stop"
            :loading="clockActionLoading"
            @click="handleClockAction(clockStore.clockOut)"
          >
            {{ t('workforceClock.clockOut') }}
          </VBtn>
        </template>
        <VBtn
          v-else-if="clockStore.isOnBreak"
          color="success"
          prepend-icon="tabler-player-play"
          :loading="clockActionLoading"
          @click="handleClockAction(clockStore.endBreak)"
        >
          {{ t('workforceClock.endBreak') }}
        </VBtn>

        <VBtn
          variant="tonal"
          color="primary"
          prepend-icon="tabler-refresh"
          :loading="loading"
          @click="clockStore.fetchProfile(); fetchDocuments()"
        >
          {{ t('workforceMe.refresh') }}
        </VBtn>
      </div>
    </div>

    <!-- Loading state -->
    <div
      v-if="loading"
      class="d-flex justify-center align-center py-16"
    >
      <VProgressCircular
        indeterminate
        color="primary"
        size="48"
      />
    </div>

    <!-- No employee record -->
    <VAlert
      v-else-if="!employee"
      type="info"
      variant="tonal"
      class="mb-6"
    >
      {{ t('workforceMe.noEmployee') }}
    </VAlert>

    <!-- Main content -->
    <template v-else>
      <!-- Card 1: Today's Status -->
      <VRow class="card-grid card-grid-xs mb-6">
        <VCol
          cols="6"
          sm="3"
        >
          <VCard>
            <VCardText class="d-flex align-center gap-3">
              <VAvatar
                :color="clockStatusConfig.color"
                variant="tonal"
                rounded
                size="44"
              >
                <VIcon
                  :icon="clockStatusConfig.icon"
                  size="26"
                />
              </VAvatar>
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('workforceMe.todayStatus') }}
                </div>
                <div class="text-h6 font-weight-bold" :class="`text-${clockStatusConfig.color}`">
                  {{ t(clockStatusConfig.labelKey) }}
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="6"
          sm="3"
        >
          <VCard>
            <VCardText class="d-flex align-center gap-3">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                size="44"
              >
                <VIcon
                  icon="tabler-clock-hour-4"
                  size="26"
                />
              </VAvatar>
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('workforceMe.hoursWorked') }}
                </div>
                <div class="text-h6 font-weight-bold">
                  {{ formatMinutes(clockStore.workedMinutesToday) }}
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="6"
          sm="3"
        >
          <VCard>
            <VCardText class="d-flex align-center gap-3">
              <VAvatar
                color="warning"
                variant="tonal"
                rounded
                size="44"
              >
                <VIcon
                  icon="tabler-coffee"
                  size="26"
                />
              </VAvatar>
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('workforceMe.breakTime') }}
                </div>
                <div class="text-h6 font-weight-bold">
                  {{ formatMinutes(clockStore.breakMinutesToday) }}
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>

        <VCol
          cols="6"
          sm="3"
        >
          <VCard>
            <VCardText class="d-flex align-center gap-3">
              <VAvatar
                color="info"
                variant="tonal"
                rounded
                size="44"
              >
                <VIcon
                  icon="tabler-login"
                  size="26"
                />
              </VAvatar>
              <div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('workforceMe.clockIn') }}
                </div>
                <div class="text-h6 font-weight-bold">
                  {{ todayClock ? formatTime(todayClock.clock_in) : '--:--' }}
                </div>
              </div>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <!-- Card 2: My Contract -->
        <VCol
          cols="12"
          md="6"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="info"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-file-text" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.myContract') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="!currentContract">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('workforceMe.noContract') }}
              </VAlert>
            </VCardText>

            <VCardText v-else>
              <VList density="compact">
                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-check"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.contractType') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ contractTypeLabel(currentContract.contract_type) }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-settings"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.workModel') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ workModelLabel(currentContract.work_model_key) }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-clock"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.weeklyHours') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ currentContract.weekly_hours ? `${currentContract.weekly_hours}h` : '-' }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem>
                  <template #prepend>
                    <VIcon
                      icon="tabler-calendar"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.startDate') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ formatDate(currentContract.start_date) }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem v-if="employee.department">
                  <template #prepend>
                    <VIcon
                      icon="tabler-building"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.department') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ employee.department.name }}
                  </VListItemSubtitle>
                </VListItem>

                <VListItem v-if="employee.job_role">
                  <template #prepend>
                    <VIcon
                      icon="tabler-briefcase"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.jobRole') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ employee.job_role.title }}
                  </VListItemSubtitle>
                </VListItem>
              </VList>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Card 3: Leave Balances -->
        <VCol
          cols="12"
          md="6"
        >
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="success"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-beach" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.leaveBalances') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="!leaveBalances.length">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('workforceMe.noLeaveBalances') }}
              </VAlert>
            </VCardText>

            <VCardText v-else>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('workforceMe.leaveType') }}</th>
                    <th class="text-end">
                      {{ t('workforceMe.leaveAvailable') }}
                    </th>
                    <th class="text-end">
                      {{ t('workforceMe.leaveConsumed') }}
                    </th>
                    <th class="text-end">
                      {{ t('workforceMe.leaveAccrued') }}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="balance in leaveBalances"
                    :key="balance.leave_type_id"
                  >
                    <td>
                      <span class="font-weight-medium">{{ balance.leave_type_name }}</span>
                    </td>
                    <td class="text-end">
                      <VChip
                        :color="balance.available > 0 ? 'success' : 'error'"
                        size="small"
                        label
                      >
                        {{ t('workforceMe.daysUnit', { count: balance.available }) }}
                      </VChip>
                    </td>
                    <td class="text-end text-medium-emphasis">
                      {{ t('workforceMe.daysUnit', { count: balance.consumed }) }}
                    </td>
                    <td class="text-end text-medium-emphasis">
                      {{ t('workforceMe.daysUnit', { count: balance.accrued }) }}
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>

            <VCardActions>
              <VSpacer />
              <VBtn
                variant="tonal"
                color="success"
                prepend-icon="tabler-plus"
                @click="router.push({ name: 'company-workforce-leave' })"
              >
                {{ t('workforceMe.requestLeave') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>

      <!-- Card: Mes bulletins de paie -->
      <VRow class="mt-2">
        <VCol cols="12">
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="primary"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-receipt" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.myPayslips') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="!payslipDocs.length">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('workforceMe.noPayslips') }}
              </VAlert>
            </VCardText>

            <VCardText v-else class="px-0">
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>{{ t('workforceMe.payslipPeriod') }}</th>
                    <th>{{ t('workforceMe.payslipType') }}</th>
                    <th>{{ t('workforceMe.payslipGeneratedAt') }}</th>
                    <th>{{ t('workforceMe.payslipSignatureStatus') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="doc in payslipDocs"
                    :key="doc.id"
                  >
                    <td class="font-weight-medium">
                      {{ doc.template?.name || doc.subject_type }}
                    </td>
                    <td>
                      <VChip
                        :color="doc.template?.code?.includes('official') ? 'success' : 'warning'"
                        size="small"
                        label
                      >
                        {{ doc.template?.code?.includes('official') ? t('workforceMe.payslipOfficial') : t('workforceMe.payslipDraft') }}
                      </VChip>
                    </td>
                    <td class="text-medium-emphasis">
                      {{ formatDate(doc.generated_at) }}
                    </td>
                    <td>
                      <VChip
                        :color="signatureChipProps(doc.signature_status).color"
                        size="x-small"
                        label
                      >
                        {{ signatureChipProps(doc.signature_status).text }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>

            <VCardActions>
              <VSpacer />
              <VBtn
                variant="tonal"
                color="primary"
                prepend-icon="tabler-receipt"
                @click="router.push({ name: 'company-workforce-payroll' })"
              >
                {{ t('workforceMe.viewPayroll') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>

      <!-- Card 4: Recent Documents -->
      <VRow class="mt-2">
        <VCol cols="12">
          <VCard>
            <VCardItem>
              <template #prepend>
                <VAvatar
                  color="secondary"
                  variant="tonal"
                  rounded
                  size="40"
                >
                  <VIcon icon="tabler-file-certificate" />
                </VAvatar>
              </template>
              <VCardTitle>{{ t('workforceMe.recentDocuments') }}</VCardTitle>
            </VCardItem>

            <VCardText v-if="documentsLoading">
              <div class="d-flex justify-center py-4">
                <VProgressCircular
                  indeterminate
                  color="primary"
                  size="32"
                />
              </div>
            </VCardText>

            <VCardText v-else-if="!documents.length">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
              >
                {{ t('workforceMe.noDocuments') }}
              </VAlert>
            </VCardText>

            <VCardText v-else class="px-0">
              <VList density="compact">
                <VListItem
                  v-for="doc in documents"
                  :key="doc.id"
                >
                  <template #prepend>
                    <VIcon
                      icon="tabler-file-text"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-1 font-weight-medium">
                    {{ doc.template?.name || doc.subject_type }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-2 text-medium-emphasis">
                    {{ formatDate(doc.generated_at) }}
                  </VListItemSubtitle>
                  <template #append>
                    <VChip
                      :color="signatureChipProps(doc.signature_status).color"
                      size="x-small"
                      label
                    >
                      {{ signatureChipProps(doc.signature_status).text }}
                    </VChip>
                  </template>
                </VListItem>
              </VList>
            </VCardText>

            <VCardActions>
              <VSpacer />
              <VBtn
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-folder"
                @click="router.push({ name: 'company-workforce-documents' })"
              >
                {{ t('workforceMe.viewAllDocuments') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
