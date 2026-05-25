<script setup>
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import StatusChip from '@/core/components/StatusChip.vue'
import { useCan } from '@/composables/useCan'

const props = defineProps({
  employee: { type: Object, required: true },
})

const { t, locale } = useI18n()
const { can } = useCan()
const store = useEmployeesStore()

const contracts = computed(() => props.employee.contracts || [])
const currentContract = computed(() => contracts.value.find(c => c.is_current))

// ── Create contract drawer ─────────────
const isDrawerOpen = ref(false)
const formLoading = ref(false)
const formData = ref({
  contract_type: 'cdi',
  work_model_key: 'horaire',
  weekly_hours: 35,
  start_date: '',
  convention_collective_id: null,
  compensation_type: 'monthly',
  base_salary_cents: null,
  hourly_rate_cents: null,
  daily_rate_cents: null,
})

const compensationTypes = [
  { title: t('employees.compensationTypes.monthly'), value: 'monthly' },
  { title: t('employees.compensationTypes.hourly'), value: 'hourly' },
  { title: t('employees.compensationTypes.daily'), value: 'daily' },
]

const contractTypes = [
  { title: t('employees.contractTypes.cdi'), value: 'cdi' },
  { title: t('employees.contractTypes.cdd'), value: 'cdd' },
  { title: t('employees.contractTypes.stage'), value: 'stage' },
  { title: t('employees.contractTypes.alternance'), value: 'alternance' },
  { title: t('employees.contractTypes.freelance'), value: 'freelance' },
]

const workModelOptions = [
  { title: t('employees.workModels.horaire'), value: 'horaire' },
  { title: t('employees.workModels.forfait_jours'), value: 'forfait_jours' },
  { title: t('employees.workModels.forfait_heures'), value: 'forfait_heures' },
]

// Load conventions for the dropdown
onMounted(() => store.fetchConventions())

const submitContract = async () => {
  formLoading.value = true
  try {
    await store.createContract(props.employee.id, formData.value)
    isDrawerOpen.value = false
    // Reset form
    formData.value = { contract_type: 'cdi', work_model_key: 'horaire', weekly_hours: 35, start_date: '', convention_collective_id: null, compensation_type: 'monthly', base_salary_cents: null, hourly_rate_cents: null, daily_rate_cents: null }
  }
  finally {
    formLoading.value = false
  }
}

const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '—'
const formatSalary = (cents, currency) => {
  if (!cents)
    return '—'

  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: currency || 'EUR' }).format(cents / 100)
}
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4">
      <h6 class="text-h6">
        {{ $t('employees.contract.title') }}
      </h6>
      <VBtn
        v-can="'workforce.contracts'"
        color="primary"
        prepend-icon="tabler-file-plus"
        size="small"
        @click="isDrawerOpen = true"
      >
        {{ $t('employees.contract.addContract') }}
      </VBtn>
    </div>

    <!-- Current Contract Card -->
    <VCard
      v-if="currentContract"
      class="mb-6"
      variant="outlined"
      color="primary"
    >
      <VCardTitle class="d-flex align-center gap-2">
        <VIcon icon="tabler-file-check" />
        {{ $t('employees.contract.currentContract') }}
        <VSpacer />
        <StatusChip
          :status="currentContract.status"
          domain="contract"
        >
          {{ $t(`workforce.statuses.contract.${currentContract.status}`) }}
        </StatusChip>
      </VCardTitle>
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <div class="text-body-2 text-medium-emphasis">
              {{ $t('employees.contract.type') }}
            </div>
            <div class="text-body-1 font-weight-medium">
              {{ $t(`employees.contractTypes.${currentContract.contract_type}`) }}
            </div>
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <div class="text-body-2 text-medium-emphasis">
              {{ $t('employees.contract.workModel') }}
            </div>
            <div class="text-body-1">
              {{ $t(`employees.workModels.${currentContract.work_model_key}`) }} — {{ currentContract.weekly_hours }}h/{{ $t('employees.contract.week') }}
            </div>
          </VCol>
          <VCol
            cols="12"
            md="4"
          >
            <div class="text-body-2 text-medium-emphasis">
              {{ $t('employees.contract.startDate') }}
            </div>
            <div class="text-body-1">
              {{ formatDate(currentContract.start_date) }}
            </div>
          </VCol>
        </VRow>

        <!-- Convention Collective -->
        <div
          v-if="currentContract.convention_collective"
          class="mt-4"
        >
          <VChip
            color="info"
            variant="tonal"
            prepend-icon="tabler-scale"
            size="small"
          >
            {{ currentContract.convention_collective.short_name }} (IDCC {{ currentContract.convention_collective.idcc }})
          </VChip>
        </div>

        <!-- Current Compensation -->
        <template v-if="currentContract.compensation_plans?.length && can('workforce.compensation_read')">
          <VDivider class="my-4" />
          <div class="text-body-2 text-medium-emphasis mb-2">
            {{ $t('employees.contract.compensation') }}
          </div>
          <div class="d-flex gap-6 flex-wrap">
            <div>
              <div class="text-body-2 text-medium-emphasis">
                {{ $t(`employees.compensationTypes.${currentContract.compensation_plans[0].compensation_type || 'monthly'}`) }}
              </div>
              <div class="text-h6">
                <template v-if="currentContract.compensation_plans[0].compensation_type === 'hourly'">
                  {{ formatSalary(currentContract.compensation_plans[0].hourly_rate_cents, currentContract.compensation_plans[0].currency) }}/h
                </template>
                <template v-else-if="currentContract.compensation_plans[0].compensation_type === 'daily'">
                  {{ formatSalary(currentContract.compensation_plans[0].daily_rate_cents, currentContract.compensation_plans[0].currency) }}/{{ $t('employees.contract.day') }}
                </template>
                <template v-else>
                  {{ formatSalary(currentContract.compensation_plans[0].base_salary_cents, currentContract.compensation_plans[0].currency) }}
                </template>
              </div>
              <div class="text-caption text-medium-emphasis">
                / {{ $t(`employees.payFrequency.${currentContract.compensation_plans[0].pay_frequency}`) }}
              </div>
            </div>
            <div v-if="currentContract.probation_end_date">
              <div class="text-body-2 text-medium-emphasis">
                {{ $t('employees.contract.probationEnd') }}
              </div>
              <div class="text-body-1">
                {{ formatDate(currentContract.probation_end_date) }}
              </div>
            </div>
          </div>
        </template>
      </VCardText>
    </VCard>

    <!-- No current contract -->
    <VAlert
      v-else
      type="warning"
      variant="tonal"
      class="mb-6"
    >
      {{ $t('employees.contract.noCurrentContract') }}
    </VAlert>

    <!-- Contract Timeline -->
    <VCard
      v-if="contracts.length > 0"
      variant="outlined"
    >
      <VCardTitle class="text-subtitle-1">
        {{ $t('employees.contract.history') }}
      </VCardTitle>
      <VCardText>
        <VTimeline
          density="compact"
          side="end"
          truncate-line="both"
        >
          <VTimelineItem
            v-for="contract in contracts"
            :key="contract.id"
            :dot-color="contract.is_current ? 'primary' : 'secondary'"
            size="small"
          >
            <div class="d-flex align-center gap-2 flex-wrap">
              <VChip
                :color="contract.is_current ? 'primary' : 'default'"
                size="small"
                variant="tonal"
              >
                {{ $t(`employees.contractTypes.${contract.contract_type}`) }}
              </VChip>
              <StatusChip
                :status="contract.status"
                domain="contract"
                size="x-small"
              >
                {{ $t(`workforce.statuses.contract.${contract.status}`) }}
              </StatusChip>
              <span class="text-body-2 text-medium-emphasis">
                {{ formatDate(contract.start_date) }}
                <template v-if="contract.end_date"> → {{ formatDate(contract.end_date) }}</template>
              </span>
              <VChip
                v-if="contract.convention_collective"
                size="x-small"
                color="info"
                variant="tonal"
              >
                IDCC {{ contract.convention_collective.idcc }}
              </VChip>
            </div>
          </VTimelineItem>
        </VTimeline>
      </VCardText>
    </VCard>

    <!-- Create Contract Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('employees.contract.addContract') }}
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
        <VForm @submit.prevent="submitContract">
          <VRow>
            <VCol cols="12">
              <AppSelect
                v-model="formData.contract_type"
                :items="contractTypes"
                :label="$t('employees.contract.type')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="formData.work_model_key"
                :items="workModelOptions"
                :label="$t('employees.contract.workModel')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.weekly_hours"
                :label="$t('employees.contract.weeklyHours')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.start_date"
                :label="$t('employees.contract.startDate')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="formData.convention_collective_id"
                :items="store.conventions.map(cc => ({ title: `${cc.short_name} (IDCC ${cc.idcc})`, value: cc.id }))"
                :label="$t('employees.contract.conventionCollective')"
                clearable
              />
            </VCol>

            <!-- Compensation Section -->
            <VCol cols="12">
              <VDivider class="mb-2" />
              <div class="text-subtitle-1 font-weight-medium mb-2">
                {{ $t('employees.contract.compensationSection') }}
              </div>
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="formData.compensation_type"
                :items="compensationTypes"
                :label="$t('employees.contract.compensationType')"
              />
            </VCol>
            <VCol
              v-if="formData.compensation_type === 'monthly'"
              cols="12"
            >
              <AppTextField
                v-model="formData.base_salary_cents"
                :label="$t('employees.contract.baseSalaryCents')"
                :hint="$t('employees.contract.amountInCents')"
                type="number"
                persistent-hint
              />
            </VCol>
            <VCol
              v-if="formData.compensation_type === 'hourly'"
              cols="12"
            >
              <AppTextField
                v-model="formData.hourly_rate_cents"
                :label="$t('employees.contract.hourlyRateCents')"
                :hint="$t('employees.contract.amountInCents')"
                type="number"
                persistent-hint
              />
            </VCol>
            <VCol
              v-if="formData.compensation_type === 'daily'"
              cols="12"
            >
              <AppTextField
                v-model="formData.daily_rate_cents"
                :label="$t('employees.contract.dailyRateCents')"
                :hint="$t('employees.contract.amountInCents')"
                type="number"
                persistent-hint
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
  </div>
</template>
