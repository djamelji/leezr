<script setup>
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { module: 'workforce', permission: 'workforce.admin' } })

const { t } = useI18n()
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

  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(cents / 100)
}

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
            <div class="d-flex justify-end mb-4">
              <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="openDeptCreate"
              >
                {{ $t('organization.departments.add') }}
              </VBtn>
            </div>

            <VTable
              :loading="deptLoading"
              density="comfortable"
            >
              <thead>
                <tr>
                  <th>{{ $t('organization.departments.name') }}</th>
                  <th>{{ $t('organization.departments.manager') }}</th>
                  <th>{{ $t('organization.departments.employeeCount') }}</th>
                  <th width="80" />
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="dept in store.departments"
                  :key="dept.id"
                >
                  <td>
                    <span class="font-weight-medium">{{ dept.name }}</span>
                    <VChip
                      v-if="dept.parent_id"
                      size="x-small"
                      color="secondary"
                      variant="tonal"
                      class="ms-2"
                    >
                      {{ $t('organization.departments.subDept') }}
                    </VChip>
                  </td>
                  <td>
                    <template v-if="dept.manager">
                      {{ dept.manager.first_name }} {{ dept.manager.last_name }}
                    </template>
                    <span
                      v-else
                      class="text-medium-emphasis"
                    >—</span>
                  </td>
                  <td>
                    <VChip
                      size="small"
                      variant="tonal"
                    >
                      {{ dept.employees_count ?? 0 }}
                    </VChip>
                  </td>
                  <td>
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      @click="openDeptEdit(dept)"
                    >
                      <VIcon icon="tabler-edit" />
                    </VBtn>
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      color="error"
                      @click="deleteDept(dept.id)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
                <tr v-if="store.departments.length === 0 && !deptLoading">
                  <td
                    colspan="4"
                    class="text-center pa-8 text-medium-emphasis"
                  >
                    {{ $t('organization.departments.empty') }}
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VWindowItem>

          <!-- Job Roles Tab -->
          <VWindowItem value="roles">
            <div class="d-flex justify-end mb-4">
              <VBtn
                color="primary"
                prepend-icon="tabler-plus"
                @click="openRoleCreate"
              >
                {{ $t('organization.roles.add') }}
              </VBtn>
            </div>

            <VTable
              :loading="roleLoading"
              density="comfortable"
            >
              <thead>
                <tr>
                  <th>{{ $t('organization.roles.title') }}</th>
                  <th>{{ $t('organization.roles.department') }}</th>
                  <th>{{ $t('organization.roles.level') }}</th>
                  <th>{{ $t('organization.roles.defaultRate') }}</th>
                  <th>{{ $t('organization.roles.employeeCount') }}</th>
                  <th width="80" />
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="role in store.jobRoles"
                  :key="role.id"
                >
                  <td class="font-weight-medium">
                    {{ role.title }}
                  </td>
                  <td>{{ role.department?.name || '—' }}</td>
                  <td>{{ role.level || '—' }}</td>
                  <td>{{ formatRate(role.default_hourly_rate_cents) }}/h</td>
                  <td>
                    <VChip
                      size="small"
                      variant="tonal"
                    >
                      {{ role.employees_count ?? 0 }}
                    </VChip>
                  </td>
                  <td>
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      @click="openRoleEdit(role)"
                    >
                      <VIcon icon="tabler-edit" />
                    </VBtn>
                    <VBtn
                      icon
                      variant="text"
                      size="small"
                      color="error"
                      @click="deleteRole(role.id)"
                    >
                      <VIcon icon="tabler-trash" />
                    </VBtn>
                  </td>
                </tr>
                <tr v-if="store.jobRoles.length === 0 && !roleLoading">
                  <td
                    colspan="6"
                    class="text-center pa-8 text-medium-emphasis"
                  >
                    {{ $t('organization.roles.empty') }}
                  </td>
                </tr>
              </tbody>
            </VTable>
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
