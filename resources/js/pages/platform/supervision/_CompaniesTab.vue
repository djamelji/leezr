<script setup>
/**
 * Companies Tab — Faithfully adapted from Vuexy UserList.vue preset
 * Pattern: resources/ui/presets/apps/roles/UserList.vue
 * ADR-381
 */
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const router = useRouter()
const companiesStore = usePlatformCompaniesStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const isLoading = ref(true)
const actionLoading = ref(null)

// Preset pattern: filters + pagination state
const searchQuery = ref('')
const statusFilter = ref()
const planFilter = ref()
const itemsPerPage = ref(10)
const page = ref(1)
const sortBy = ref()
const orderBy = ref()
const selectedRows = ref([])
const searchTimeout = ref(null)

const updateOptions = options => {
  sortBy.value = options.sortBy[0]?.key
  orderBy.value = options.sortBy[0]?.order
}

const statusOptions = [
  { title: 'Active', value: 'active' },
  { title: 'Suspended', value: 'suspended' },
]

const planFilterOptions = computed(() =>
  companiesStore.plans.map(p => ({ title: p.name, value: p.key })),
)

const filters = computed(() => {
  const f = {}
  if (searchQuery.value) f.search = searchQuery.value
  if (statusFilter.value) f.status = statusFilter.value
  if (planFilter.value) f.plan_key = planFilter.value

  return f
})

const fetchWithFilters = async (p = 1) => {
  isLoading.value = true
  try {
    await companiesStore.fetchCompanies(p, filters.value)
  }
  finally {
    isLoading.value = false
  }
}

// Debounced search
watch(searchQuery, () => {
  clearTimeout(searchTimeout.value)
  searchTimeout.value = setTimeout(() => {
    page.value = 1
    fetchWithFilters()
  }, 400)
})

watch([statusFilter, planFilter], () => {
  page.value = 1
  fetchWithFilters()
})

onMounted(async () => {
  try {
    await Promise.all([
      companiesStore.fetchCompanies(1),
      companiesStore.fetchPlans(),
    ])
  }
  finally {
    isLoading.value = false
  }
})

// Preset pattern: headers
const headers = computed(() => [
  { title: t('common.name'), key: 'name' },
  { title: t('common.slug'), key: 'slug' },
  { title: t('common.status'), key: 'status', align: 'center', width: '120px' },
  { title: t('Plan'), key: 'plan_key', align: 'center', width: '160px', sortable: false },
  { title: t('companies.members'), key: 'memberships_count', align: 'center', width: '100px' },
  { title: t('common.created'), key: 'created_at', width: '140px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '120px', sortable: false },
])

// Preset pattern: resolveStatusVariant
const resolveStatusVariant = status => {
  if (status === 'active') return 'success'
  if (status === 'suspended') return 'error'

  return 'primary'
}

const suspend = async company => {
  const ok = await confirm({
    question: t('companies.confirmSuspend', { name: company.name }),
    confirmTitle: t('common.actionConfirmed'),
    confirmMsg: t('companies.companySuspended'),
    cancelTitle: t('common.actionCancelled'),
    cancelMsg: t('common.operationCancelled'),
  })
  if (!ok)
    return

  actionLoading.value = company.id

  try {
    await companiesStore.suspendCompany(company.id)
    toast(t('companies.companySuspended'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companies.failedToSuspend'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const reactivate = async company => {
  actionLoading.value = company.id

  try {
    await companiesStore.reactivateCompany(company.id)
    toast(t('companies.companyReactivated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companies.failedToReactivate'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const changePlan = async (company, planKey) => {
  if (planKey === company.plan_key)
    return

  actionLoading.value = company.id

  try {
    await companiesStore.updateCompanyPlan(company.id, planKey)
    toast(t('companies.planUpdated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companies.failedToUpdatePlan'), 'error')
  }
  finally {
    actionLoading.value = null
  }
}

const fmtDate = dateStr => {
  if (!dateStr)
    return '\u2014'

  return formatDate(dateStr)
}

const totalCompanies = computed(() => companiesStore.companiesPagination.total || companiesStore.companies.length)

const kpiCards = computed(() => [
  {
    title: t('companies.stats.total'),
    value: companiesStore.stats.total,
    color: 'primary',
    icon: 'tabler-building',
  },
  {
    title: t('companies.stats.active'),
    value: companiesStore.stats.total_active,
    color: 'success',
    icon: 'tabler-check',
  },
  {
    title: t('companies.stats.suspended'),
    value: companiesStore.stats.total_suspended,
    color: 'error',
    icon: 'tabler-ban',
  },
])
</script>

<template>
  <section>
    <!-- KPI Cards -->
    <VCard class="mb-6">
      <VCardText>
        <VRow class="card-grid card-grid-xs">
          <VCol
            v-for="card in kpiCards"
            :key="card.title"
            cols="6"
            md="4"
          >
            <VCard
              flat
              border
              class="text-center pa-4"
            >
              <VAvatar
                size="42"
                variant="tonal"
                :color="card.color"
                class="mb-2"
              >
                <VIcon :icon="card.icon" />
              </VAvatar>
              <h4 class="text-h5 font-weight-bold">
                {{ card.value }}
              </h4>
              <span class="text-body-2 text-disabled">{{ card.title }}</span>
            </VCard>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Companies Table -->
    <VCard>
      <!-- Preset pattern: filter bar -->
      <VCardText class="d-flex flex-wrap gap-4">
        <div class="d-flex gap-2 align-center">
          <p class="text-body-1 mb-0">
            {{ t('common.show') }}
          </p>
          <AppSelect
            :model-value="itemsPerPage"
            :items="[
              { value: 10, title: '10' },
              { value: 25, title: '25' },
              { value: 50, title: '50' },
              { value: 100, title: '100' },
              { value: -1, title: t('common.all') },
            ]"
            style="inline-size: 5.5rem;"
            @update:model-value="itemsPerPage = parseInt($event, 10)"
          />
        </div>

        <VSpacer />

        <div class="d-flex align-center flex-wrap gap-4">
          <!-- Search -->
          <AppTextField
            v-model="searchQuery"
            :placeholder="t('companies.searchPlaceholder')"
            style="inline-size: 15.625rem;"
          />

          <!-- Status filter -->
          <AppSelect
            v-model="statusFilter"
            :placeholder="t('companies.filterByStatus')"
            :items="statusOptions"
            clearable
            clear-icon="tabler-x"
            style="inline-size: 10rem;"
          />

          <!-- Plan filter -->
          <AppSelect
            v-model="planFilter"
            :placeholder="t('companies.filterByPlan')"
            :items="planFilterOptions"
            clearable
            clear-icon="tabler-x"
            style="inline-size: 10rem;"
          />
        </div>
      </VCardText>

      <VDivider />

      <!-- Preset pattern: VDataTableServer -->
      <VDataTableServer
        v-model:items-per-page="itemsPerPage"
        v-model:model-value="selectedRows"
        v-model:page="page"
        :items-per-page-options="[
          { value: 10, title: '10' },
          { value: 20, title: '20' },
          { value: 50, title: '50' },
          { value: -1, title: '$vuetify.dataFooter.itemsPerPageAll' },
        ]"
        :items="companiesStore.companies"
        :items-length="totalCompanies"
        :headers="headers"
        :loading="isLoading"
        class="text-no-wrap"
        show-select
        @update:options="updateOptions"
      >
        <!-- Preset pattern: Name column -->
        <template #item.name="{ item }">
          <div class="d-flex align-center gap-x-4">
            <VAvatar
              size="34"
              variant="tonal"
              color="primary"
            >
              <VIcon icon="tabler-building" />
            </VAvatar>
            <div class="d-flex flex-column">
              <h6 class="text-base">
                <RouterLink
                  :to="{ name: 'platform-companies-id', params: { id: item.id } }"
                  class="font-weight-medium text-link"
                >
                  {{ item.name }}
                </RouterLink>
              </h6>
              <div class="text-sm">
                {{ item.slug }}
              </div>
            </div>
          </div>
        </template>

        <!-- Hide slug column in favor of Name sub-text -->
        <template #item.slug />

        <!-- Preset pattern: Status column (chip label) -->
        <template #item.status="{ item }">
          <VChip
            :color="resolveStatusVariant(item.status)"
            size="small"
            label
            class="text-capitalize"
          >
            {{ item.status }}
          </VChip>
        </template>

        <!-- Plan column (inline select) -->
        <template #item.plan_key="{ item }">
          <div @click.stop>
            <VSelect
              :model-value="item.plan_key"
              :items="companiesStore.plans"
              item-title="name"
              item-value="key"
              density="compact"
              variant="outlined"
              hide-details
              :loading="actionLoading === item.id"
              @update:model-value="changePlan(item, $event)"
            />
          </div>
        </template>

        <!-- Members count -->
        <template #item.memberships_count="{ item }">
          {{ item.memberships_count ?? '\u2014' }}
        </template>

        <!-- Created at -->
        <template #item.created_at="{ item }">
          {{ fmtDate(item.created_at) }}
        </template>

        <!-- Preset pattern: Actions (suspend/reactivate + eye + dots menu) -->
        <template #item.actions="{ item }">
          <IconBtn
            v-if="item.status === 'active'"
            :loading="actionLoading === item.id"
            @click="suspend(item)"
          >
            <VIcon
              icon="tabler-ban"
              color="warning"
            />
          </IconBtn>
          <IconBtn
            v-else
            :loading="actionLoading === item.id"
            @click="reactivate(item)"
          >
            <VIcon
              icon="tabler-check"
              color="success"
            />
          </IconBtn>

          <IconBtn @click="router.push({ name: 'platform-companies-id', params: { id: item.id } })">
            <VIcon icon="tabler-eye" />
          </IconBtn>

          <VBtn
            icon
            variant="text"
            color="medium-emphasis"
          >
            <VIcon icon="tabler-dots-vertical" />
            <VMenu activator="parent">
              <VList>
                <VListItem :to="{ name: 'platform-companies-id', params: { id: item.id } }">
                  <template #prepend>
                    <VIcon icon="tabler-eye" />
                  </template>
                  <VListItemTitle>{{ t('common.view') }}</VListItemTitle>
                </VListItem>

                <VListItem
                  v-if="item.status === 'active'"
                  @click="suspend(item)"
                >
                  <template #prepend>
                    <VIcon icon="tabler-ban" />
                  </template>
                  <VListItemTitle>{{ t('companies.suspend') }}</VListItemTitle>
                </VListItem>
                <VListItem
                  v-else
                  @click="reactivate(item)"
                >
                  <template #prepend>
                    <VIcon icon="tabler-check" />
                  </template>
                  <VListItemTitle>{{ t('companies.reactivate') }}</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </VBtn>
        </template>

        <!-- Preset pattern: TablePagination in #bottom -->
        <template #bottom>
          <TablePagination
            v-model:page="page"
            :items-per-page="itemsPerPage"
            :total-items="totalCompanies"
          />
        </template>
      </VDataTableServer>
    </VCard>

    <ConfirmDialogComponent />
  </section>
</template>
