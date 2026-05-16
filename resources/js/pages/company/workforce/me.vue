<script setup>
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import { useAppToast } from '@/composables/useAppToast'
import { $api } from '@/utils/api'

definePage({ meta: { module: 'workforce' } })

const { t } = useI18n()
const router = useRouter()
const clockStore = useWorkforceClockStore()
const { toast } = useAppToast()

// ── State ───────────────────────────────────────────────────
const loading = ref(true)
const documents = ref([])
const documentsLoading = ref(false)

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

  return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
}

function formatDate(dateString) {
  if (!dateString) return '-'

  return new Date(dateString).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
}

// ── Contract type labels ────────────────────────────────────
const contractTypeLabels = {
  cdi: 'CDI',
  cdd: 'CDD',
  stage: 'Stage',
  alternance: 'Alternance',
  freelance: 'Freelance',
}

const workModelLabels = {
  horaire: 'Horaire',
  forfait_jours: 'Forfait jours',
  forfait_heures: 'Forfait heures',
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
                    {{ contractTypeLabels[currentContract.contract_type] || currentContract.contract_type }}
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
                    {{ workModelLabels[currentContract.work_model_key] || currentContract.work_model_key || '-' }}
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

                <VListItem v-if="currentContract.metadata?.job_title">
                  <template #prepend>
                    <VIcon
                      icon="tabler-briefcase"
                      size="20"
                      color="primary"
                      class="me-2"
                    />
                  </template>
                  <VListItemTitle class="text-body-2 text-medium-emphasis">
                    {{ t('workforceMe.jobTitle') }}
                  </VListItemTitle>
                  <VListItemSubtitle class="text-body-1 font-weight-medium">
                    {{ currentContract.metadata.job_title }}
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
                        {{ balance.available }}j
                      </VChip>
                    </td>
                    <td class="text-end text-medium-emphasis">
                      {{ balance.consumed }}j
                    </td>
                    <td class="text-end text-medium-emphasis">
                      {{ balance.accrued }}j
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
                    {{ doc.template?.code }} &mdash; {{ formatDate(doc.generated_at) }}
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
