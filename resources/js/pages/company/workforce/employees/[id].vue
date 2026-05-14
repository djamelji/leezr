<script setup>
import StatusChip from '@/core/components/StatusChip.vue'
import EmployeeProfile from './_EmployeeProfile.vue'
import EmployeeContract from './_EmployeeContract.vue'
import EmployeeVariables from './_EmployeeVariables.vue'
import EmployeeDocuments from './_EmployeeDocuments.vue'
import EmployeeActivity from './_EmployeeActivity.vue'
import { useEmployeesStore } from '@/modules/company/workforce/employees.store'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { can } = useCan()
const store = useEmployeesStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const { toast } = useAppToast()

// ── Data fetching ────────────────────────────────────────────
const loading = ref(true)

onMounted(async () => {
  try {
    await store.fetchEmployee(route.params.id)
  } catch {
    toast(t('employees.loadError'), 'error')
  } finally {
    loading.value = false
  }
})

onBeforeUnmount(() => store.clearCurrentEmployee())

// ── Reactive state ───────────────────────────────────────────
const employee = computed(() => store.currentEmployee)
const currentTab = ref(0)

const tabs = computed(() => [
  { title: t('employees.tabs.profile'), icon: 'tabler-user' },
  { title: t('employees.tabs.contract'), icon: 'tabler-file-text' },
  { title: t('employees.tabs.variables'), icon: 'tabler-adjustments' },
  { title: t('employees.tabs.documents'), icon: 'tabler-folder' },
  { title: t('employees.tabs.activity'), icon: 'tabler-activity' },
])

// ── Computed helpers ─────────────────────────────────────────
const conventionLabel = computed(() => {
  const cc = employee.value?.current_contract?.convention_collective
  if (!cc) return null

  return `${cc.short_name} (IDCC ${cc.idcc})`
})
</script>

<template>
  <div>
    <!-- Back button -->
    <div class="d-flex align-center gap-2 mb-4">
      <VBtn
        icon
        variant="text"
        size="small"
        @click="router.push({ name: 'company-workforce-employees' })"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <span class="text-body-1 text-medium-emphasis">
        {{ $t('employees.backToList') }}
      </span>
    </div>

    <!-- Loading state -->
    <VCard
      v-if="loading"
      class="pa-8 text-center"
    >
      <VProgressCircular
        indeterminate
        color="primary"
      />
    </VCard>

    <template v-else-if="employee">
      <!-- Employee Header -->
      <VCard class="mb-6">
        <VCardText>
          <div class="d-flex align-center gap-4 flex-wrap">
            <!-- Avatar -->
            <VAvatar
              color="primary"
              variant="tonal"
              size="64"
            >
              <span class="text-h5 font-weight-medium">
                {{ (employee.first_name?.[0] || '') + (employee.last_name?.[0] || '') }}
              </span>
            </VAvatar>

            <!-- Info -->
            <div class="flex-grow-1">
              <h4 class="text-h4 font-weight-bold">
                {{ employee.first_name }} {{ employee.last_name }}
              </h4>
              <div class="d-flex align-center gap-3 mt-1 flex-wrap">
                <StatusChip
                  :status="employee.status"
                  domain="employee"
                >
                  {{ $t(`workforce.statuses.employee.${employee.status}`) }}
                </StatusChip>
                <span
                  v-if="employee.employee_number"
                  class="text-body-2 text-medium-emphasis"
                >
                  {{ $t('employees.fields.employeeNumber') }}: {{ employee.employee_number }}
                </span>
                <VChip
                  v-if="conventionLabel"
                  size="small"
                  color="info"
                  variant="tonal"
                  prepend-icon="tabler-scale"
                >
                  {{ conventionLabel }}
                </VChip>
              </div>
            </div>

            <!-- Actions -->
            <div class="d-flex gap-2">
              <VBtn
                v-can="'workforce.manage'"
                variant="outlined"
                prepend-icon="tabler-edit"
                @click="currentTab = 0"
              >
                {{ $t('common.edit') }}
              </VBtn>
            </div>
          </div>
        </VCardText>
      </VCard>

      <!-- Tabs -->
      <VCard>
        <VTabs
          v-model="currentTab"
          class="v-tabs-pill"
        >
          <VTab
            v-for="(tab, i) in tabs"
            :key="i"
          >
            <VIcon
              :icon="tab.icon"
              size="20"
              class="me-2"
            />
            {{ tab.title }}
          </VTab>
        </VTabs>

        <VDivider />

        <VCardText>
          <VWindow v-model="currentTab">
            <VWindowItem :value="0">
              <EmployeeProfile
                :employee="employee"
                @updated="store.fetchEmployee(route.params.id).then(() => toast(t('employees.updated'), 'success'))"
              />
            </VWindowItem>
            <VWindowItem :value="1">
              <EmployeeContract :employee="employee" />
            </VWindowItem>
            <VWindowItem :value="2">
              <EmployeeVariables :employee="employee" />
            </VWindowItem>
            <VWindowItem :value="3">
              <EmployeeDocuments :employee="employee" />
            </VWindowItem>
            <VWindowItem :value="4">
              <EmployeeActivity :employee="employee" />
            </VWindowItem>
          </VWindow>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
