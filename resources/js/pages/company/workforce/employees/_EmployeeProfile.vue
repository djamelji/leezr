<script setup>
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useCan } from '@/composables/useCan'

const props = defineProps({
  employee: { type: Object, required: true },
})

// Load departments/roles/employees for edit dropdowns
onMounted(() => {
  store.fetchDepartments()
  store.fetchJobRoles()
  store.fetchEmployees({ perPage: 200 })
})

const { can } = useCan()
const canManage = computed(() => can('workforce.manage'))

const emit = defineEmits(['updated'])

const { t, locale } = useI18n()
const store = useEmployeesStore()

const isEditing = ref(false)
const formLoading = ref(false)
const formData = ref({})

const departmentItems = computed(() =>
  store.departments.map(d => ({ title: d.name, value: d.id })),
)

const jobRoleItems = computed(() =>
  store.jobRoles.map(r => ({ title: r.title, value: r.id })),
)

const managerItems = computed(() =>
  store.employees
    .filter(e => e.id !== props.employee.id)
    .map(e => ({ title: `${e.first_name} ${e.last_name}`, value: e.id })),
)

const startEdit = () => {
  formData.value = {
    first_name: props.employee.first_name,
    last_name: props.employee.last_name,
    email: props.employee.email || '',
    phone: props.employee.phone || '',
    employee_number: props.employee.employee_number || '',
    department_id: props.employee.department_id || null,
    job_role_id: props.employee.job_role_id || null,
    manager_id: props.employee.manager_id || null,
  }
  isEditing.value = true
}

const cancelEdit = () => {
  isEditing.value = false
}

const saveProfile = async () => {
  formLoading.value = true
  try {
    await store.updateEmployee(props.employee.id, formData.value)
    isEditing.value = false
    emit('updated')
  }
  finally {
    formLoading.value = false
  }
}

const formatDate = d => d ? new Date(d).toLocaleDateString(locale.value) : '—'
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-4">
      <h6 class="text-h6">
        {{ $t('employees.profile.title') }}
      </h6>
      <VBtn
        v-if="!isEditing && canManage"
        variant="text"
        prepend-icon="tabler-edit"
        size="small"
        @click="startEdit"
      >
        {{ $t('common.edit') }}
      </VBtn>
    </div>

    <!-- Display mode -->
    <VRow v-if="!isEditing">
      <VCol
        cols="12"
        md="6"
      >
        <VCard variant="outlined">
          <VCardTitle class="text-subtitle-1">
            {{ $t('employees.profile.personalInfo') }}
          </VCardTitle>
          <VCardText>
            <VList
              density="compact"
              class="pa-0"
            >
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-user"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.firstName') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.first_name }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-user"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.lastName') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.last_name }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-mail"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.email') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.email || '—' }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-phone"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.phone') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.phone || '—' }}
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>

      <VCol
        cols="12"
        md="6"
      >
        <VCard variant="outlined">
          <VCardTitle class="text-subtitle-1">
            {{ $t('employees.profile.employmentInfo') }}
          </VCardTitle>
          <VCardText>
            <VList
              density="compact"
              class="pa-0"
            >
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-id"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.employeeNumber') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.employee_number || '—' }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-calendar"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.hireDate') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ formatDate(employee.hire_date) }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem v-if="employee.termination_date">
                <template #prepend>
                  <VIcon
                    icon="tabler-calendar-off"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.terminationDate') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ formatDate(employee.termination_date) }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-building"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.department') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.department?.name || '—' }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-briefcase"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.jobRole') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  {{ employee.job_role?.title || '—' }}
                </VListItemSubtitle>
              </VListItem>
              <VListItem>
                <template #prepend>
                  <VIcon
                    icon="tabler-user-check"
                    size="20"
                    class="me-3"
                  />
                </template>
                <VListItemTitle class="text-body-2 text-medium-emphasis">
                  {{ $t('employees.fields.manager') }}
                </VListItemTitle>
                <VListItemSubtitle class="text-body-1">
                  <template v-if="employee.manager">
                    {{ employee.manager.first_name }} {{ employee.manager.last_name }}
                  </template>
                  <template v-else>
                    —
                  </template>
                </VListItemSubtitle>
              </VListItem>
            </VList>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Edit mode -->
    <VCard
      v-else
      variant="outlined"
    >
      <VCardText>
        <VForm @submit.prevent="saveProfile">
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="formData.first_name"
                :label="$t('employees.fields.firstName')"
                :rules="[v => !!v || $t('validation.required')]"
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="formData.last_name"
                :label="$t('employees.fields.lastName')"
                :rules="[v => !!v || $t('validation.required')]"
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="formData.email"
                :label="$t('employees.fields.email')"
                type="email"
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="formData.phone"
                :label="$t('employees.fields.phone')"
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="formData.employee_number"
                :label="$t('employees.fields.employeeNumber')"
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="formData.department_id"
                :items="departmentItems"
                :label="$t('employees.fields.department')"
                clearable
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppSelect
                v-model="formData.job_role_id"
                :items="jobRoleItems"
                :label="$t('employees.fields.jobRole')"
                clearable
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppAutocomplete
                v-model="formData.manager_id"
                :items="managerItems"
                :label="$t('employees.fields.manager')"
                clearable
                :disabled="!canManage"
              />
            </VCol>
            <VCol
              v-if="canManage"
              cols="12"
            >
              <div class="d-flex gap-3 justify-end">
                <VBtn
                  variant="outlined"
                  @click="cancelEdit"
                >
                  {{ $t('common.cancel') }}
                </VBtn>
                <VBtn
                  type="submit"
                  color="primary"
                  :loading="formLoading"
                >
                  {{ $t('common.save') }}
                </VBtn>
              </div>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>
  </div>
</template>
