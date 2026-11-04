<script setup>
// W-G2 (ADR-642) — liste collaborateurs : filtres avancés serveur, URL sync, multi-sélection +
// actions groupées, import guidé et export sécurisé. Gérer 100 collaborateurs comme 5.
// E3-A (ADR-692) — refonte ergonomique : en-tête à action primaire unique (+ overflow), recherche
// toujours visible + filtres avancés repliables (compteur), états skeleton/empty/error partagés.
// 100 % générique : aucune logique sectorielle — réutilisable par tout JobDomain activant workforce.
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'
import StatusChip from '@/core/components/StatusChip.vue'
import EmptyState from '@/core/components/EmptyState.vue'
import ErrorState from '@/core/components/ErrorState.vue'
import AppResponsiveDataView from '@/core/components/AppResponsiveDataView.vue'
import AppFilterPanel from '@/core/components/AppFilterPanel.vue'
import AppAdaptiveDialog from '@/core/components/AppAdaptiveDialog.vue'
import EmployeePicker from '@/components/workforce/EmployeePicker.vue'
import EmployeeImportDrawer from './_EmployeeImportDrawer.vue'
import EmployeeBulkActionDialog from './_EmployeeBulkActionDialog.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const store = useEmployeesStore()
const { toast } = useAppToast()
const { can } = useCan()

// ── Filtres (initialisés depuis l'URL) ───────────────────────
const search = ref(route.query.q || '')
const statusFilter = ref(route.query.status || null)
const departmentFilter = ref(route.query.dept ? Number(route.query.dept) : null)
const jobRoleFilter = ref(route.query.role ? Number(route.query.role) : null)
const managerFilter = ref(route.query.manager ? Number(route.query.manager) : null)
const contractTypeFilter = ref(route.query.contract || null)
const options = ref({ page: 1, itemsPerPage: 15, sortBy: [{ key: 'last_name', order: 'asc' }] })
const loading = ref(false)
const error = ref(false)

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

// Filtres AVANCÉS (hors recherche), pour le compteur et le repli.
const advancedFilterCount = computed(() => [
  statusFilter.value, departmentFilter.value, jobRoleFilter.value, managerFilter.value, contractTypeFilter.value,
].filter(v => v !== null && v !== undefined).length)

const activeFilters = computed(() => ({
  search: search.value || undefined,
  status: statusFilter.value || undefined,
  departmentId: departmentFilter.value || undefined,
  jobRoleId: jobRoleFilter.value || undefined,
  managerId: managerFilter.value || undefined,
  contractType: contractTypeFilter.value || undefined,
}))

const hasFilters = computed(() => Object.values(activeFilters.value).some(v => v !== undefined))

// E3-R/R3-UX : priorité de colonnes — tablette n'affiche que essential+important ; mobile = liste.
const headers = computed(() => [
  { title: t('employees.columns.name'), key: 'last_name', sortable: true, priority: 'essential' },
  { title: t('employees.columns.status'), key: 'status', sortable: true, width: 120, priority: 'important' },
  { title: t('employees.columns.employeeNumber'), key: 'employee_number', sortable: true, priority: 'secondary' },
  { title: t('employees.columns.contract'), key: 'current_contract', sortable: false, width: 180, priority: 'secondary' },
  { title: t('employees.columns.email'), key: 'email', sortable: true, priority: 'detail' },
  { title: t('employees.columns.hireDate'), key: 'hire_date', sortable: true, width: 120, priority: 'detail' },
  { title: '', key: 'actions', sortable: false, width: 60 },
])

// Skeleton uniquement au PREMIER chargement (évite le clignotement sur filtrage).
const firstLoad = ref(true)
const showSkeleton = computed(() => loading.value && firstLoad.value)

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
  error.value = false
  try {
    const sort = options.value.sortBy?.[0]

    await store.fetchEmployees({
      ...activeFilters.value,
      perPage: options.value.itemsPerPage,
      page: options.value.page,
      sortBy: sort?.key || 'last_name',
      sortDir: sort?.order || 'asc',
    })
  } catch {
    error.value = true
  } finally {
    loading.value = false
    firstLoad.value = false
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
const createFormRef = ref(null)
const formData = ref({ first_name: '', last_name: '', email: '', phone: '', employee_number: '', hire_date: new Date().toISOString().slice(0, 10) })
const formLoading = ref(false)

// Validation du formulaire avant soumission (bouton hors du VForm en mode dialogue adaptatif).
const onCreateSubmit = async () => {
  const res = await createFormRef.value?.validate?.()
  if (res && res.valid === false) return
  submitEmployee()
}
const resetForm = () => { formData.value = { first_name: '', last_name: '', email: '', phone: '', employee_number: '', hire_date: new Date().toISOString().slice(0, 10) } }

const submitEmployee = async () => {
  formLoading.value = true
  try {
    // W-HR-UX-1 — après création, on GUIDE la RH vers la fiche du collaborateur (où vivent contrat,
    // signature et accès) via la checklist d'intégration, au lieu de la laisser sur la liste sans suite.
    const created = await store.createEmployee(formData.value)
    const createdEmp = created?.data ?? created
    isDrawerOpen.value = false
    resetForm()
    toast(t('employees.created'), 'success')
    if (createdEmp?.id) goToEmployee(createdEmp.id)
    else fetchData()
  } catch (error) {
    // 422 = erreur de validation : les messages inline par champ suffisent, pas de toast générique anxiogène.
    if (error.response?.status !== 422)
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
    <!-- Header : titre + compteur + action primaire unique (overflow pour export/import) -->
    <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
      <div class="d-flex align-center gap-3">
        <VAvatar
          size="42"
          variant="tonal"
          color="primary"
          rounded
        >
          <VIcon
            icon="tabler-users-group"
            size="22"
          />
        </VAvatar>
        <div>
          <h4 class="text-h4 font-weight-bold mb-0 d-flex align-center gap-2">
            {{ $t('employees.title') }}
            <VChip
              v-if="!firstLoad && !error"
              size="small"
              color="primary"
              variant="tonal"
              data-test="emp-count"
            >
              {{ store.totalEmployees }}
            </VChip>
          </h4>
          <span class="text-body-2 text-medium-emphasis">{{ $t('employees.subtitle') }}</span>
        </div>
      </div>

      <div class="d-flex align-center gap-2 flex-wrap">
        <VBtn
          v-can="'workforce.manage'"
          color="primary"
          data-test="emp-add"
          prepend-icon="tabler-user-plus"
          @click="isDrawerOpen = true"
        >
          {{ $t('employees.addEmployee') }}
        </VBtn>

        <!-- Actions secondaires regroupées (évite 3 boutons concurrents) -->
        <VBtn
          v-if="can('workforce.export') || can('workforce.import')"
          icon
          variant="tonal"
          color="secondary"
          data-test="emp-more"
        >
          <VIcon icon="tabler-dots-vertical" />
          <VMenu activator="parent" location="bottom end">
            <VList density="compact" min-width="220">
              <VListItem
                v-can="'workforce.export'"
                prepend-icon="tabler-file-export"
                :disabled="exporting"
                data-test="emp-export"
                @click="exportCsv"
              >
                <VListItemTitle>{{ $t('employees.export.button') }}</VListItemTitle>
              </VListItem>
              <VListItem
                v-can="'workforce.import'"
                prepend-icon="tabler-file-import"
                data-test="emp-import"
                @click="importOpen = true"
              >
                <VListItemTitle>{{ $t('employees.import.button') }}</VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </VBtn>
      </div>
    </div>

    <!-- Toolbar : recherche toujours visible + bascule filtres avancés -->
    <VCard class="mb-6">
      <VCardText class="pb-2">
        <AppFilterPanel
          v-model:search="search"
          :placeholder="$t('employees.searchPlaceholder')"
          :active-count="advancedFilterCount"
          @reset="resetFilters"
        >
          <template #filters>
            <VRow>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="statusFilter"
                  :items="statusOptions"
                  :label="$t('employees.filterStatus')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="departmentFilter"
                  :items="departmentOptions"
                  :label="$t('employees.filters.department')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="jobRoleFilter"
                  :items="jobRoleOptions"
                  :label="$t('employees.filters.jobRole')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" sm="6" md="3">
                <AppSelect
                  v-model="contractTypeFilter"
                  :items="contractTypeOptions"
                  :label="$t('employees.filters.contractType')"
                  clearable
                  density="compact"
                />
              </VCol>
              <VCol cols="12" md="6">
                <EmployeePicker
                  v-model="managerFilter"
                  :label="$t('employees.filters.manager')"
                  clearable
                  density="compact"
                />
              </VCol>
            </VRow>
          </template>
        </AppFilterPanel>
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

      <VDivider />

      <!-- Skeleton (1er chargement) -->
      <VCardText v-if="showSkeleton">
        <VSkeletonLoader type="table-row@6" />
      </VCardText>

      <!-- Erreur de chargement : état explicite + réessayer (pas seulement un toast) -->
      <ErrorState
        v-else-if="error"
        :message="$t('employees.listLoadError')"
        @retry="fetchData"
      />

      <!-- Table -->
      <AppResponsiveDataView
        v-else
        v-model="selected"
        v-model:options="options"
        show-select
        item-value="id"
        :headers="headers"
        :items="store.employees"
        :items-length="store.totalEmployees"
        :loading="loading"
        @row-click="goToEmployee($event.id)"
      >
        <!-- MOBILE : ligne compacte — nom + fonction/équipe + statut (sobre). Email/tél → fiche. -->
        <template #mobile-item="{ item }">
          <div class="d-flex align-center gap-3" style="min-inline-size: 0;" :data-test="`emp-row-${item.id}`">
            <VAvatar color="primary" variant="tonal" size="36">
              <span class="text-xs font-weight-medium">{{ (item.first_name?.[0] || '') + (item.last_name?.[0] || '') }}</span>
            </VAvatar>
            <div class="flex-grow-1" style="min-inline-size: 0;">
              <div class="font-weight-medium text-truncate">
                {{ item.first_name }} {{ item.last_name }}
              </div>
              <!-- Fonction + matricule : identifie même les noms longs/similaires -->
              <div class="text-caption text-medium-emphasis text-truncate">
                <template v-if="item.job_role?.title || item.department?.name">
                  {{ item.job_role?.title || item.department?.name }}<template v-if="item.employee_number"> · {{ item.employee_number }}</template>
                </template>
                <template v-else>{{ item.employee_number || '—' }}</template>
              </div>
            </div>
            <StatusChip :status="item.status" domain="employee" size="small">
              {{ $t(`workforce.statuses.employee.${item.status}`) }}
            </StatusChip>
          </div>
        </template>

        <template #item.last_name="{ item }">
          <div
            class="d-flex align-center gap-3 py-2"
            :data-test="`emp-row-${item.id}`"
          >
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
          <EmptyState
            :icon="hasFilters ? 'tabler-search-off' : 'tabler-users-group'"
            :title="hasFilters ? $t('employees.emptyFiltered.title') : $t('employees.emptyState.title')"
            :description="hasFilters ? $t('employees.emptyFiltered.description') : $t('employees.emptyState.description')"
          >
            <VBtn
              v-if="hasFilters"
              variant="tonal"
              prepend-icon="tabler-filter-off"
              @click="resetFilters"
            >
              {{ $t('employees.filters.reset') }}
            </VBtn>
            <VBtn
              v-else-if="can('workforce.manage')"
              color="primary"
              prepend-icon="tabler-user-plus"
              @click="isDrawerOpen = true"
            >
              {{ $t('employees.emptyState.action') }}
            </VBtn>
          </EmptyState>
        </template>
      </AppResponsiveDataView>
    </VCard>

    <!-- Create Employee — dialogue adaptatif (plein écran mobile) -->
    <AppAdaptiveDialog
      v-model="isDrawerOpen"
      :title="$t('employees.addEmployee')"
      :max-width="480"
    >
      <VForm ref="createFormRef" @submit.prevent="onCreateSubmit">
        <VRow>
          <VCol cols="12">
            <AppTextField v-model="formData.first_name" data-test="emp-first-name" :label="$t('employees.fields.firstName') + ' *'" :rules="[v => !!v || $t('validation.required')]" />
          </VCol>
          <VCol cols="12">
            <AppTextField v-model="formData.last_name" data-test="emp-last-name" :label="$t('employees.fields.lastName') + ' *'" :rules="[v => !!v || $t('validation.required')]" />
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
            <AppDateTimePicker v-model="formData.hire_date" data-test="emp-hire-date" :label="$t('employees.fields.hireDate') + ' *'" :config="{ dateFormat: 'Y-m-d', altInput: true, altFormat: 'd/m/Y' }" :rules="[v => !!v || $t('validation.required')]" />
          </VCol>
        </VRow>
      </VForm>
      <template #actions="{ close }">
        <VSpacer />
        <VBtn variant="outlined" @click="close">
          {{ $t('common.cancel') }}
        </VBtn>
        <VBtn color="primary" data-test="emp-submit" :loading="formLoading" @click="onCreateSubmit">
          {{ $t('common.create') }}
        </VBtn>
      </template>
    </AppAdaptiveDialog>

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
