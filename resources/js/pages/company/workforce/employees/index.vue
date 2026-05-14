<script setup>
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t, locale } = useI18n()
const router = useRouter()
const store = useEmployeesStore()
const { toast } = useAppToast()

// ── Table state ──────────────────────────────────────────────
const search = ref('')
const statusFilter = ref(null)
const options = ref({ page: 1, itemsPerPage: 15, sortBy: [{ key: 'last_name', order: 'asc' }] })
const loading = ref(false)

const statusOptions = [
  { title: t('workforce.statuses.employee.active'), value: 'active' },
  { title: t('workforce.statuses.employee.inactive'), value: 'inactive' },
  { title: t('workforce.statuses.employee.on_leave'), value: 'on_leave' },
  { title: t('workforce.statuses.employee.suspended'), value: 'suspended' },
  { title: t('workforce.statuses.employee.terminated'), value: 'terminated' },
]

const headers = computed(() => [
  { title: t('employees.columns.name'), key: 'last_name', sortable: true },
  { title: t('employees.columns.email'), key: 'email', sortable: true },
  { title: t('employees.columns.employeeNumber'), key: 'employee_number', sortable: true },
  { title: t('employees.columns.status'), key: 'status', sortable: true, width: 120 },
  { title: t('employees.columns.contract'), key: 'current_contract', sortable: false, width: 200 },
  { title: t('employees.columns.hireDate'), key: 'hire_date', sortable: true, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 60 },
])

// ── Data fetching ────────────────────────────────────────────
const fetchData = async () => {
  loading.value = true
  try {
    const sort = options.value.sortBy?.[0]

    await store.fetchEmployees({
      search: search.value || undefined,
      status: statusFilter.value || undefined,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
      sortBy: sort?.key || 'last_name',
      sortDir: sort?.order || 'asc',
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

onMounted(fetchData)

// ── Drawer ───────────────────────────────────────────────────
const isDrawerOpen = ref(false)
const formData = ref({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  employee_number: '',
  hire_date: '',
})
const formLoading = ref(false)

const resetForm = () => {
  formData.value = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    employee_number: '',
    hire_date: '',
  }
}

const submitEmployee = async () => {
  formLoading.value = true
  try {
    await store.createEmployee(formData.value)
    isDrawerOpen.value = false
    resetForm()
    fetchData()
    toast(t('employees.created'), 'success')
  } catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  } finally {
    formLoading.value = false
  }
}

// ── Navigation ───────────────────────────────────────────────
const goToEmployee = id => {
  router.push({ name: 'company-workforce-employees-id', params: { id } })
}

// ── Format helpers ───────────────────────────────────────────
const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '—'

const getContractLabel = employee => {
  const contract = employee.current_contract
  if (!contract) return null

  const type = t(`employees.contractTypes.${contract.contract_type}`, contract.contract_type)
  const cc = contract.convention_collective
    ? `${contract.convention_collective.short_name} (IDCC ${contract.convention_collective.idcc})`
    : null

  return { type, cc }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('employees.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('employees.subtitle') }}
        </p>
      </div>
      <VBtn
        v-can="'workforce.manage'"
        color="primary"
        prepend-icon="tabler-user-plus"
        @click="isDrawerOpen = true"
      >
        {{ $t('employees.addEmployee') }}
      </VBtn>
    </div>

    <!-- Filters -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="search"
              :placeholder="$t('employees.searchPlaceholder')"
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
              :placeholder="$t('employees.filterStatus')"
              clearable
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>

      <!-- Table -->
      <VDataTableServer
        v-model:options="options"
        :headers="headers"
        :items="store.employees"
        :items-length="store.totalEmployees"
        :loading="loading"
        class="text-no-wrap"
        @click:row="(_, { item }) => goToEmployee(item.id)"
      >
        <!-- Name -->
        <template #item.last_name="{ item }">
          <div class="d-flex align-center gap-3 py-2">
            <VAvatar
              color="primary"
              variant="tonal"
              size="38"
            >
              <span class="text-sm font-weight-medium">
                {{ (item.first_name?.[0] || '') + (item.last_name?.[0] || '') }}
              </span>
            </VAvatar>
            <div>
              <div class="font-weight-medium">
                {{ item.first_name }} {{ item.last_name }}
              </div>
            </div>
          </div>
        </template>

        <!-- Status -->
        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="employee"
          >
            {{ $t(`workforce.statuses.employee.${item.status}`) }}
          </StatusChip>
        </template>

        <!-- Contract -->
        <template #item.current_contract="{ item }">
          <template v-if="item.current_contract">
            <VChip
              size="small"
              color="primary"
              variant="tonal"
              class="me-1"
            >
              {{ $t(`employees.contractTypes.${item.current_contract.contract_type}`, item.current_contract.contract_type) }}
            </VChip>
            <VChip
              v-if="item.current_contract.convention_collective"
              size="x-small"
              color="info"
              variant="tonal"
            >
              IDCC {{ item.current_contract.convention_collective.idcc }}
            </VChip>
          </template>
          <span
            v-else
            class="text-medium-emphasis text-body-2"
          >
            {{ $t('employees.noContract') }}
          </span>
        </template>

        <!-- Hire Date -->
        <template #item.hire_date="{ item }">
          {{ formatDate(item.hire_date) }}
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <VBtn
            icon
            variant="text"
            size="small"
            @click.stop="goToEmployee(item.id)"
          >
            <VIcon icon="tabler-chevron-right" />
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-users-group"
              size="48"
              color="secondary"
              class="mb-4"
            />
            <h6 class="text-h6 mb-2">
              {{ $t('employees.emptyState.title') }}
            </h6>
            <p class="text-body-1 text-medium-emphasis mb-4">
              {{ $t('employees.emptyState.description') }}
            </p>
            <VBtn
              v-can="'workforce.manage'"
              color="primary"
              prepend-icon="tabler-user-plus"
              @click="isDrawerOpen = true"
            >
              {{ $t('employees.emptyState.action') }}
            </VBtn>
          </div>
        </template>
      </VDataTableServer>
    </VCard>

    <!-- Create Employee Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ $t('employees.addEmployee') }}
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
        <VForm @submit.prevent="submitEmployee">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="formData.first_name"
                :label="$t('employees.fields.firstName')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.last_name"
                :label="$t('employees.fields.lastName')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.email"
                :label="$t('employees.fields.email')"
                type="email"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.phone"
                :label="$t('employees.fields.phone')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.employee_number"
                :label="$t('employees.fields.employeeNumber')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="formData.hire_date"
                :label="$t('employees.fields.hireDate')"
                type="date"
                :rules="[v => !!v || $t('validation.required')]"
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
