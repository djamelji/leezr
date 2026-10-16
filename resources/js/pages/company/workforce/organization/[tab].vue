<script setup>
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { module: 'workforce', permission: 'workforce.admin' } })

const { t, locale } = useI18n()
const { toast } = useAppToast()
const route = useRoute()
const router = useRouter()
const store = useEmployeesStore()

const currentTab = computed({
  get: () => route.params.tab || 'departments',
  set: val => router.replace({ params: { tab: val } }),
})

// ── Departments ──────────────────────────────────────────
const deptLoading = ref(false)
const deptHeaders = computed(() => [
  { title: t('organization.departments.name'), key: 'name', sortable: true },
  { title: t('organization.departments.manager'), key: 'manager', sortable: false },
  { title: t('organization.departments.employeeCount'), key: 'employees_count', sortable: true, width: 160 },
  { title: '', key: 'actions', sortable: false, width: 100 },
])
const deptDrawerOpen = ref(false)
const deptFormLoading = ref(false)
const deptEditId = ref(null)
const deptForm = ref({ name: '', parent_id: null, manager_id: null, sort_order: 0 })

const fetchDepartments = async () => {
  deptLoading.value = true
  try {
    await store.fetchDepartments()
  } finally {
    deptLoading.value = false
  }
}

const openDeptCreate = () => {
  deptEditId.value = null
  deptForm.value = { name: '', parent_id: null, manager_id: null, sort_order: 0 }
  deptDrawerOpen.value = true
}

const openDeptEdit = dept => {
  deptEditId.value = dept.id
  deptForm.value = { name: dept.name, parent_id: dept.parent_id, manager_id: dept.manager_id, sort_order: dept.sort_order }
  deptDrawerOpen.value = true
}

const submitDept = async () => {
  deptFormLoading.value = true
  try {
    if (deptEditId.value) {
      await store.updateDepartment(deptEditId.value, deptForm.value)
      toast(t('organization.departments.updated'), 'success')
    }
    else {
      await store.createDepartment(deptForm.value)
      toast(t('organization.departments.created'), 'success')
    }
    deptDrawerOpen.value = false
    fetchDepartments()
  }
  catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
  finally {
    deptFormLoading.value = false
  }
}

const deleteDept = async id => {
  try {
    await store.deleteDepartment(id)
    toast(t('organization.departments.deleted'), 'success')
  }
  catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
}

// ── Job Roles ────────────────────────────────────────────
const roleLoading = ref(false)
const roleHeaders = computed(() => [
  { title: t('organization.roles.title'), key: 'title', sortable: true },
  { title: t('organization.roles.department'), key: 'department', sortable: false },
  { title: t('organization.roles.level'), key: 'level', sortable: true, width: 120 },
  { title: t('organization.roles.defaultRate'), key: 'default_hourly_rate_cents', sortable: true, width: 140 },
  { title: t('organization.roles.employeeCount'), key: 'employees_count', sortable: true, width: 140 },
  { title: '', key: 'actions', sortable: false, width: 100 },
])
const roleDrawerOpen = ref(false)
const roleFormLoading = ref(false)
const roleEditId = ref(null)
const roleForm = ref({ title: '', department_id: null, level: '', description: '', default_hourly_rate_cents: null })

const fetchRoles = async () => {
  roleLoading.value = true
  try {
    await store.fetchJobRoles()
  } finally {
    roleLoading.value = false
  }
}

const openRoleCreate = () => {
  roleEditId.value = null
  roleForm.value = { title: '', department_id: null, level: '', description: '', default_hourly_rate_cents: null }
  roleDrawerOpen.value = true
}

const openRoleEdit = role => {
  roleEditId.value = role.id
  roleForm.value = {
    title: role.title,
    department_id: role.department_id,
    level: role.level || '',
    description: role.description || '',
    default_hourly_rate_cents: role.default_hourly_rate_cents,
  }
  roleDrawerOpen.value = true
}

const submitRole = async () => {
  roleFormLoading.value = true
  try {
    const payload = { ...roleForm.value }
    if (payload.default_hourly_rate_cents !== null && payload.default_hourly_rate_cents !== '') {
      payload.default_hourly_rate_cents = parseInt(payload.default_hourly_rate_cents)
    }
    else {
      payload.default_hourly_rate_cents = null
    }

    if (roleEditId.value) {
      await store.updateJobRole(roleEditId.value, payload)
      toast(t('organization.roles.updated'), 'success')
    }
    else {
      await store.createJobRole(payload)
      toast(t('organization.roles.created'), 'success')
    }
    roleDrawerOpen.value = false
    fetchRoles()
  }
  catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
  finally {
    roleFormLoading.value = false
  }
}

const deleteRole = async id => {
  try {
    await store.deleteJobRole(id)
    toast(t('organization.roles.deleted'), 'success')
  }
  catch (error) {
    toast(error.response?.data?.message || t('common.error'), 'error')
  }
}

// ── Employee items for dropdowns ─────────────────────────
const employeeItems = computed(() =>
  store.employees.map(e => ({ title: `${e.first_name} ${e.last_name}`, value: e.id })),
)

const departmentItems = computed(() =>
  store.departments.map(d => ({ title: d.name, value: d.id })),
)

const formatRate = cents => {
  if (!cents) return '—'

  return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'EUR' }).format(cents / 100)
}

// ── Stats ────────────────────────────────────────────────
const totalDepartments = computed(() => store.departments.length)
const totalDeptEmployees = computed(() =>
  store.departments.reduce((sum, d) => sum + (d.employees_count ?? 0), 0),
)
const subDepartmentsCount = computed(() =>
  store.departments.filter(d => d.parent_id).length,
)

const totalRoles = computed(() => store.jobRoles.length)
const totalRoleEmployees = computed(() =>
  store.jobRoles.reduce((sum, r) => sum + (r.employees_count ?? 0), 0),
)
const rolesWithRateCount = computed(() =>
  store.jobRoles.filter(r => r.default_hourly_rate_cents).length,
)

onMounted(() => {
  fetchDepartments()
  fetchRoles()
  store.fetchEmployees({ perPage: 200 })
})
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('organization.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('organization.description') }}
        </p>
      </div>
    </div>

    <!-- Tabs -->
    <VCard>
      <VTabs
        v-model="currentTab"
        class="v-tabs-pill"
      >
        <VTab value="departments">
          <VIcon
            icon="tabler-building"
            size="20"
            class="me-2"
          />
          {{ $t('organization.tabs.departments') }}
        </VTab>
        <VTab value="roles">
          <VIcon
            icon="tabler-briefcase"
            size="20"
            class="me-2"
          />
          {{ $t('organization.tabs.roles') }}
        </VTab>
      </VTabs>

      <VDivider />

      <VCardText>
        <VWindow v-model="currentTab">
          <!-- Departments Tab -->
          <VWindowItem value="departments">
            <!-- Stats Row -->
            <VRow class="card-grid card-grid-xs mb-6">
              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="outlined"
                  class="text-center pa-4"
                >
                  <VIcon
                    icon="tabler-building"
                    size="32"
                    color="primary"
                    class="mb-2"
                  />
                  <div class="text-h5 font-weight-bold">
                    {{ totalDepartments }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('organization.departments.statsTotal') }}
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
                    icon="tabler-users"
                    size="32"
                    color="success"
                    class="mb-2"
                  />
                  <div class="text-h5 font-weight-bold">
                    {{ totalDeptEmployees }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('organization.departments.statsEmployees') }}
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
                    icon="tabler-hierarchy"
                    size="32"
                    color="info"
                    class="mb-2"
                  />
                  <div class="text-h5 font-weight-bold">
                    {{ subDepartmentsCount }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('organization.departments.statsSub') }}
                  </div>
                </VCard>
              </VCol>
            </VRow>

            <div class="d-flex justify-end mb-4">
              <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="openDeptCreate"
              >
                {{ $t('organization.departments.add') }}
              </VBtn>
            </div>

            <VDataTable
              :headers="deptHeaders"
              :items="store.departments"
              :loading="deptLoading"
              :items-per-page="15"
              class="text-no-wrap"
            >
              <!-- Name -->
              <template #item.name="{ item }">
                <div class="d-flex align-center gap-2 py-2">
                  <VAvatar
                    color="primary"
                    variant="tonal"
                    size="34"
                  >
                    <VIcon
                      icon="tabler-building"
                      size="18"
                    />
                  </VAvatar>
                  <div>
                    <div class="font-weight-medium">
                      {{ item.name }}
                    </div>
                    <VChip
                      v-if="item.parent_id"
                      size="x-small"
                      color="secondary"
                      variant="tonal"
                      class="mt-1"
                    >
                      {{ $t('organization.departments.subDept') }}
                    </VChip>
                  </div>
                </div>
              </template>

              <!-- Manager -->
              <template #item.manager="{ item }">
                <div
                  v-if="item.manager"
                  class="d-flex align-center gap-2"
                >
                  <VAvatar
                    color="secondary"
                    variant="tonal"
                    size="30"
                  >
                    <span class="text-xs font-weight-medium">
                      {{ (item.manager.first_name?.[0] || '') + (item.manager.last_name?.[0] || '') }}
                    </span>
                  </VAvatar>
                  <span class="font-weight-medium">
                    {{ item.manager.first_name }} {{ item.manager.last_name }}
                  </span>
                </div>
                <span
                  v-else
                  class="text-medium-emphasis"
                >—</span>
              </template>

              <!-- Employee Count -->
              <template #item.employees_count="{ item }">
                <VChip
                  :color="(item.employees_count ?? 0) > 0 ? 'primary' : 'secondary'"
                  size="small"
                  variant="tonal"
                >
                  <VIcon
                    icon="tabler-users"
                    size="14"
                    class="me-1"
                  />
                  {{ item.employees_count ?? 0 }}
                </VChip>
              </template>

              <!-- Actions -->
              <template #item.actions="{ item }">
                <div class="d-flex gap-1">
                  <VBtn
                    icon
                    variant="text"
                    size="x-small"
                    color="primary"
                    @click.stop="openDeptEdit(item)"
                  >
                    <VIcon
                      icon="tabler-edit"
                      size="18"
                    />
                    <VTooltip
                      activator="parent"
                      :text="$t('common.edit')"
                      location="top"
                    />
                  </VBtn>
                  <VBtn
                    icon
                    variant="text"
                    size="x-small"
                    color="error"
                    @click.stop="deleteDept(item.id)"
                  >
                    <VIcon
                      icon="tabler-trash"
                      size="18"
                    />
                    <VTooltip
                      activator="parent"
                      :text="$t('common.delete')"
                      location="top"
                    />
                  </VBtn>
                </div>
              </template>

              <!-- Empty state -->
              <template #no-data>
                <div class="text-center pa-8">
                  <VIcon
                    icon="tabler-building-skyscraper"
                    size="48"
                    color="secondary"
                    class="mb-4"
                  />
                  <h6 class="text-h6 mb-2">
                    {{ $t('organization.departments.empty') }}
                  </h6>
                  <p class="text-body-1 text-medium-emphasis mb-4">
                    {{ $t('organization.departments.emptyDescription') }}
                  </p>
                  <VBtn
                    color="primary"
                    prepend-icon="tabler-plus"
                    @click="openDeptCreate"
                  >
                    {{ $t('organization.departments.add') }}
                  </VBtn>
                </div>
              </template>
            </VDataTable>
          </VWindowItem>

          <!-- Job Roles Tab -->
          <VWindowItem value="roles">
            <!-- Stats Row -->
            <VRow class="card-grid card-grid-xs mb-6">
              <VCol
                cols="12"
                md="4"
              >
                <VCard
                  variant="outlined"
                  class="text-center pa-4"
                >
                  <VIcon
                    icon="tabler-briefcase"
                    size="32"
                    color="primary"
                    class="mb-2"
                  />
                  <div class="text-h5 font-weight-bold">
                    {{ totalRoles }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('organization.roles.statsTotal') }}
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
                    icon="tabler-users"
                    size="32"
                    color="success"
                    class="mb-2"
                  />
                  <div class="text-h5 font-weight-bold">
                    {{ totalRoleEmployees }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('organization.roles.statsEmployees') }}
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
                    icon="tabler-coin-euro"
                    size="32"
                    color="info"
                    class="mb-2"
                  />
                  <div class="text-h5 font-weight-bold">
                    {{ rolesWithRateCount }}
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ $t('organization.roles.statsWithRate') }}
                  </div>
                </VCard>
              </VCol>
            </VRow>

            <div class="d-flex justify-end mb-4">
              <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="openRoleCreate"
              >
                {{ $t('organization.roles.add') }}
              </VBtn>
            </div>

            <VDataTable
              :headers="roleHeaders"
              :items="store.jobRoles"
              :loading="roleLoading"
              :items-per-page="15"
              class="text-no-wrap"
            >
              <!-- Title -->
              <template #item.title="{ item }">
                <div class="d-flex align-center gap-2 py-2">
                  <VAvatar
                    color="primary"
                    variant="tonal"
                    size="34"
                  >
                    <VIcon
                      icon="tabler-briefcase"
                      size="18"
                    />
                  </VAvatar>
                  <div>
                    <div class="font-weight-medium">
                      {{ item.title }}
                    </div>
                    <div
                      v-if="item.description"
                      class="text-body-2 text-medium-emphasis"
                    >
                      {{ item.description }}
                    </div>
                  </div>
                </div>
              </template>

              <!-- Department -->
              <template #item.department="{ item }">
                <VChip
                  v-if="item.department?.name"
                  size="small"
                  color="info"
                  variant="tonal"
                >
                  <VIcon
                    icon="tabler-building"
                    size="14"
                    class="me-1"
                  />
                  {{ item.department.name }}
                </VChip>
                <span
                  v-else
                  class="text-medium-emphasis"
                >—</span>
              </template>

              <!-- Level -->
              <template #item.level="{ item }">
                <VChip
                  v-if="item.level"
                  size="small"
                  color="secondary"
                  variant="tonal"
                >
                  {{ item.level }}
                </VChip>
                <span
                  v-else
                  class="text-medium-emphasis"
                >—</span>
              </template>

              <!-- Default Rate -->
              <template #item.default_hourly_rate_cents="{ item }">
                <span
                  v-if="item.default_hourly_rate_cents"
                  class="font-weight-medium"
                >
                  {{ formatRate(item.default_hourly_rate_cents) }}/h
                </span>
                <span
                  v-else
                  class="text-medium-emphasis"
                >—</span>
              </template>

              <!-- Employee Count -->
              <template #item.employees_count="{ item }">
                <VChip
                  :color="(item.employees_count ?? 0) > 0 ? 'primary' : 'secondary'"
                  size="small"
                  variant="tonal"
                >
                  <VIcon
                    icon="tabler-users"
                    size="14"
                    class="me-1"
                  />
                  {{ item.employees_count ?? 0 }}
                </VChip>
              </template>

              <!-- Actions -->
              <template #item.actions="{ item }">
                <div class="d-flex gap-1">
                  <VBtn
                    icon
                    variant="text"
                    size="x-small"
                    color="primary"
                    @click.stop="openRoleEdit(item)"
                  >
                    <VIcon
                      icon="tabler-edit"
                      size="18"
                    />
                    <VTooltip
                      activator="parent"
                      :text="$t('common.edit')"
                      location="top"
                    />
                  </VBtn>
                  <VBtn
                    icon
                    variant="text"
                    size="x-small"
                    color="error"
                    @click.stop="deleteRole(item.id)"
                  >
                    <VIcon
                      icon="tabler-trash"
                      size="18"
                    />
                    <VTooltip
                      activator="parent"
                      :text="$t('common.delete')"
                      location="top"
                    />
                  </VBtn>
                </div>
              </template>

              <!-- Empty state -->
              <template #no-data>
                <div class="text-center pa-8">
                  <VIcon
                    icon="tabler-briefcase-off"
                    size="48"
                    color="secondary"
                    class="mb-4"
                  />
                  <h6 class="text-h6 mb-2">
                    {{ $t('organization.roles.empty') }}
                  </h6>
                  <p class="text-body-1 text-medium-emphasis mb-4">
                    {{ $t('organization.roles.emptyDescription') }}
                  </p>
                  <VBtn
                    color="primary"
                    prepend-icon="tabler-plus"
                    @click="openRoleCreate"
                  >
                    {{ $t('organization.roles.add') }}
                  </VBtn>
                </div>
              </template>
            </VDataTable>
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>

    <!-- Department Drawer -->
    <VNavigationDrawer
      v-model="deptDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ deptEditId ? $t('organization.departments.edit') : $t('organization.departments.add') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="deptDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="submitDept">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="deptForm.name"
                :label="$t('organization.departments.name')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="deptForm.parent_id"
                :items="departmentItems"
                :label="$t('organization.departments.parentDept')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <AppAutocomplete
                v-model="deptForm.manager_id"
                :items="employeeItems"
                :label="$t('organization.departments.manager')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="deptForm.sort_order"
                :label="$t('organization.departments.sortOrder')"
                type="number"
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="deptDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="deptFormLoading"
                >
                  {{ deptEditId ? $t('common.save') : $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Job Role Drawer -->
    <VNavigationDrawer
      v-model="roleDrawerOpen"
      temporary
      location="end"
      width="400"
    >
      <div class="d-flex align-center pa-4">
        <h5 class="text-h5 flex-grow-1">
          {{ roleEditId ? $t('organization.roles.edit') : $t('organization.roles.add') }}
        </h5>
        <VBtn
          icon
          variant="text"
          size="small"
          @click="roleDrawerOpen = false"
        >
          <VIcon icon="tabler-x" />
        </VBtn>
      </div>
      <VDivider />
      <div class="pa-4">
        <VForm @submit.prevent="submitRole">
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="roleForm.title"
                :label="$t('organization.roles.titleField')"
                :rules="[v => !!v || $t('validation.required')]"
              />
            </VCol>
            <VCol cols="12">
              <AppSelect
                v-model="roleForm.department_id"
                :items="departmentItems"
                :label="$t('organization.roles.department')"
                clearable
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="roleForm.level"
                :label="$t('organization.roles.level')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="roleForm.description"
                :label="$t('organization.roles.descriptionField')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="roleForm.default_hourly_rate_cents"
                :label="$t('organization.roles.defaultRateLabel')"
                :hint="$t('organization.roles.defaultRateHint')"
                type="number"
                persistent-hint
              />
            </VCol>
            <VCol cols="12">
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="roleDrawerOpen = false"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="roleFormLoading"
                >
                  {{ roleEditId ? $t('common.save') : $t('common.create') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </div>
    </VNavigationDrawer>
  </div>
</template>
