<script setup>
// W-G2 (ADR-642) — liste collaborateurs : filtres avancés serveur, URL sync, multi-sélection +
// actions groupées, import guidé et export sécurisé. Gérer 100 collaborateurs comme 5.
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import StatusChip from '@/core/components/StatusChip.vue'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'
import EmployeeImportDrawer from './_EmployeeImportDrawer.vue'
import EmployeeBulkActionDialog from './_EmployeeBulkActionDialog.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const store = useEmployeesStore()
const { toast } = useAppToast()

// ── Filtres (initialisés depuis l'URL) ───────────────────────
const search = ref(route.query.q || '')
const statusFilter = ref(route.query.status || null)
const departmentFilter = ref(route.query.dept ? Number(route.query.dept) : null)
const jobRoleFilter = ref(route.query.role ? Number(route.query.role) : null)
const managerFilter = ref(route.query.manager ? Number(route.query.manager) : null)
const contractTypeFilter = ref(route.query.contract || null)
const options = ref({ page: 1, itemsPerPage: 15, sortBy: [{ key: 'last_name', order: 'asc' }] })
const loading = ref(false)

const selected = ref([]) // ids des lignes sélectionnées (page visible)

const statusOptions = [
  { title: t('workforce.statuses.employee.active'), value: 'active' },
  { title: t('workforce.statuses.employee.inactive'), value: 'inactive' },
  { title: t('workforce.statuses.employee.on_leave'), value: 'on_leave' },
  { title: t('workforce.statuses.employee.suspended'), value: 'suspended' },
  { title: t('workforce.statuses.employee.terminated'), value: 'terminated' },
]
const contractTypeOptions = ['cdi', 'cdd', 'stage', 'alternance', 'freelance'].map(v => ({ title: t(`employees.contractTypes.${v}`), value: v }))
const departmentOptions = computed(() => store.departments.map(d => ({ title: d.name, value: d.id })))
const jobRoleOptions = computed(() => store.jobRoles.map(r => ({ title: r.title, value: r.id })))

const activeFilters = computed(() => ({
  search: search.value || undefined,
  status: statusFilter.value || undefined,
  departmentId: departmentFilter.value || undefined,
  jobRoleId: jobRoleFilter.value || undefined,
  managerId: managerFilter.value || undefined,
  contractType: contractTypeFilter.value || undefined,
}))

const hasFilters = computed(() => Object.values(activeFilters.value).some(v => v !== undefined))

const headers = computed(() => [
  { title: t('employees.columns.name'), key: 'last_name', sortable: true },
  { title: t('employees.columns.email'), key: 'email', sortable: true },
  { title: t('employees.columns.employeeNumber'), key: 'employee_number', sortable: true },
  { title: t('employees.columns.status'), key: 'status', sortable: true, width: 120 },
  { title: t('employees.columns.contract'), key: 'current_contract', sortable: false, width: 180 },
  { title: t('employees.columns.hireDate'), key: 'hire_date', sortable: true, width: 120 },
  { title: '', key: 'actions', sortable: false, width: 60 },
])

// ── Data fetching + URL sync ─────────────────────────────────
const syncUrl = () => {
  router.replace({ query: {
    ...(search.value ? { q: search.value } : {}),
    ...(statusFilter.value ? { status: statusFilter.value } : {}),
    ...(departmentFilter.value ? { dept: departmentFilter.value } : {}),
    ...(jobRoleFilter.value ? { role: jobRoleFilter.value } : {}),
    ...(managerFilter.value ? { manager: managerFilter.value } : {}),
    ...(contractTypeFilter.value ? { contract: contractTypeFilter.value } : {}),
  } }).catch(() => {})
}

const fetchData = async () => {
  loading.value = true
  try {
    const sort = options.value.sortBy?.[0]

    await store.fetchEmployees({
      ...activeFilters.value,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
      sortBy: sort?.key || 'last_name',
      sortDir: sort?.order || 'asc',
    })
  } finally {
    loading.value = false
  }
}

const resetFilters = () => {
  search.value = ''
  statusFilter.value = null
  departmentFilter.value = null
  jobRoleFilter.value = null
  managerFilter.value = null
  contractTypeFilter.value = null
}

// Tout changement de filtre : page 1, URL, et on vide la sélection (son périmètre devient ambigu).
watchDebounced([search, statusFilter, departmentFilter, jobRoleFilter, managerFilter, contractTypeFilter], () => {
  options.value.page = 1
  selected.value = []
  syncUrl()
  fetchData()
}, { debounce: 300 })

watch(options, () => { selected.value = []; fetchData() }, { deep: true })

onMounted(() => {
  store.fetchDepartments()
  store.fetchJobRoles()
  fetchData()
})

// ── Export / Import ──────────────────────────────────────────
const exporting = ref(false)
const exportCsv = async () => {
  exporting.value = true
  try {
    const blob = await store.exportEmployees(activeFilters.value)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = 'collaborateurs.csv'
    a.click()
    setTimeout(() => URL.revokeObjectURL(url), 10000)
  } catch {
    toast(t('common.error'), 'error')
  } finally {
    exporting.value = false
  }
}

const importOpen = ref(false)
const onImported = () => { toast(t('employees.import.done'), 'success'); fetchData() }

// ── Actions groupées ─────────────────────────────────────────
const bulkOpen = ref(false)
const bulkAction = ref('set_department')
const openBulk = action => { bulkAction.value = action; bulkOpen.value = true }
const onBulkDone = () => { selected.value = []; fetchData() }

// ── Create drawer ────────────────────────────────────────────
const isDrawerOpen = ref(false)
const formData = ref({ first_name: '', last_name: '', email: '', phone: '', employee_number: '', hire_date: '' })
const formLoading = ref(false)
const resetForm = () => { formData.value = { first_name: '', last_name: '', email: '', phone: '', employee_number: '', hire_date: '' } }

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

// ── Navigation + helpers ─────────────────────────────────────
const goToEmployee = id => router.push({ name: 'company-workforce-employees-id', params: { id } })
const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '—'
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('employees.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('employees.subtitle') }}
        </p>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <VBtn
          v-can="'workforce.export'"
          variant="tonal"
          color="secondary"
          prepend-icon="tabler-file-export"
          :loading="exporting"
          @click="exportCsv"
        >
          {{ $t('employees.export.button') }}
        </VBtn>
        <VBtn
          v-can="'workforce.import'"
          variant="tonal"
          prepend-icon="tabler-file-import"
          @click="importOpen = true"
        >
          {{ $t('employees.import.button') }}
        </VBtn>
        <VBtn
          v-can="'workforce.manage'"
          color="primary"
          data-test="emp-add"
          prepend-icon="tabler-user-plus"
          @click="isDrawerOpen = true"
        >
          {{ $t('employees.addEmployee') }}
        </VBtn>
      </div>
    </div>

    <!-- Filters -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol cols="12" md="4">
            <AppTextField
              v-model="search"
              :placeholder="$t('employees.searchPlaceholder')"
              prepend-inner-icon="tabler-search"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :placeholder="$t('employees.filterStatus')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <AppSelect
              v-model="departmentFilter"
              :items="departmentOptions"
              :placeholder="$t('employees.filters.department')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <AppSelect
              v-model="jobRoleFilter"
              :items="jobRoleOptions"
              :placeholder="$t('employees.filters.jobRole')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" sm="6" md="2">
            <AppSelect
              v-model="contractTypeFilter"
              :items="contractTypeOptions"
              :placeholder="$t('employees.filters.contractType')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol cols="12" md="4">
            <EmployeePicker
              v-model="managerFilter"
              :label="$t('employees.filters.manager')"
              clearable
              density="compact"
            />
          </VCol>
          <VCol
            v-if="hasFilters"
            cols="12"
            md="2"
            class="d-flex align-center"
          >
            <VBtn
              variant="text"
              size="small"
              prepend-icon="tabler-filter-off"
              @click="resetFilters"
            >
              {{ $t('employees.filters.reset') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>

      <!-- Barre d'actions groupées (apparaît à la sélection) -->
      <VExpandTransition>
        <div
          v-if="selected.length"
          class="d-flex align-center gap-3 px-4 py-2 bg-primary-lighten-5 flex-wrap"
        >
          <span class="font-weight-medium">{{ $t('employees.bulk.selected', { count: selected.length }) }}</span>
          <VSpacer />
          <VBtn
            v-can="'workforce.manage'"
            size="small"
            variant="tonal"
            prepend-icon="tabler-hierarchy-2"
            @click="openBulk('set_department')"
          >
            {{ $t('employees.bulk.actions.set_department') }}
          </VBtn>
          <VBtn
            v-can="'workforce.manage'"
            size="small"
            variant="tonal"
            prepend-icon="tabler-briefcase"
            @click="openBulk('set_job_role')"
          >
            {{ $t('employees.bulk.actions.set_job_role') }}
          </VBtn>
          <VBtn
            v-can="'workforce.manage'"
            size="small"
            variant="tonal"
            prepend-icon="tabler-user-check"
            @click="openBulk('set_manager')"
          >
            {{ $t('employees.bulk.actions.set_manager') }}
          </VBtn>
          <VBtn
            size="small"
            variant="text"
            @click="selected = []"
          >
            {{ $t('employees.bulk.clear') }}
          </VBtn>
        </div>
      </VExpandTransition>

      <!-- Table -->
      <VDataTableServer
        v-model="selected"
        v-model:options="options"
        show-select
        item-value="id"
        :headers="headers"
        :items="store.employees"
        :items-length="store.totalEmployees"
        :loading="loading"
        class="text-no-wrap cursor-pointer"
        @click:row="(_, { item }) => goToEmployee(item.id)"
      >
        <template #item.last_name="{ item }">
          <div class="d-flex align-center gap-3 py-2">
            <VAvatar color="primary" variant="tonal" size="38">
              <span class="text-sm font-weight-medium">{{ (item.first_name?.[0] || '') + (item.last_name?.[0] || '') }}</span>
            </VAvatar>
            <div class="font-weight-medium">
              {{ item.first_name }} {{ item.last_name }}
            </div>
          </div>
        </template>

        <template #item.status="{ item }">
          <StatusChip :status="item.status" domain="employee">
            {{ $t(`workforce.statuses.employee.${item.status}`) }}
          </StatusChip>
        </template>

        <template #item.current_contract="{ item }">
          <template v-if="item.current_contract">
            <VChip size="small" color="primary" variant="tonal" class="me-1">
              {{ $t(`employees.contractTypes.${item.current_contract.contract_type}`, item.current_contract.contract_type) }}
            </VChip>
          </template>
          <span v-else class="text-medium-emphasis text-body-2">{{ $t('employees.noContract') }}</span>
        </template>

        <template #item.hire_date="{ item }">
          {{ formatDate(item.hire_date) }}
        </template>

        <template #item.actions="{ item }">
          <VBtn icon variant="text" size="small" @click.stop="goToEmployee(item.id)">
            <VIcon icon="tabler-chevron-right" />
          </VBtn>
        </template>

        <template #no-data>
          <div class="text-center pa-8">
            <VIcon icon="tabler-users-group" size="48" color="secondary" class="mb-4" />
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
        <VBtn icon variant="text" size="small" @click="isDrawerOpen = false">
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="submitEmployee">
          <VRow>
            <VCol cols="12">
              <AppTextField v-model="formData.first_name" data-test="emp-first-name" :label="$t('employees.fields.firstName')" :rules="[v => !!v || $t('validation.required')]" />
            </VCol>
            <VCol cols="12">
              <AppTextField v-model="formData.last_name" data-test="emp-last-name" :label="$t('employees.fields.lastName')" :rules="[v => !!v || $t('validation.required')]" />
            </VCol>
            <VCol cols="12">
              <AppTextField v-model="formData.email" :label="$t('employees.fields.email')" type="email" />
            </VCol>
            <VCol cols="12">
              <AppTextField v-model="formData.phone" :label="$t('employees.fields.phone')" />
            </VCol>
            <VCol cols="12">
              <AppTextField v-model="formData.employee_number" :label="$t('employees.fields.employeeNumber')" />
            </VCol>
            <VCol cols="12">
              <AppDateTimePicker v-model="formData.hire_date" data-test="emp-hire-date" :label="$t('employees.fields.hireDate')" :config="{ dateFormat: 'Y-m-d' }" :rules="[v => !!v || $t('validation.required')]" />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn variant="outlined" @click="isDrawerOpen = false">
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn type="submit" color="primary" data-test="emp-submit" :loading="formLoading">
                  {{ $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Import wizard + Bulk action dialog -->
    <EmployeeImportDrawer
      v-model="importOpen"
      @imported="onImported"
    />
    <EmployeeBulkActionDialog
      v-model="bulkOpen"
      :action="bulkAction"
      :employee-ids="selected"
      @done="onBulkDone"
    />
  </div>
</template>
