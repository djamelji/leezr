<script setup>
/**
 * ADR-271: Enhanced platform companies list with search, filters, KPI cards.
 */
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { useAppToast } from '@/composables/useAppToast'
import { useConfirm } from '@/composables/useConfirm'
import { formatDate } from '@/utils/datetime'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.companies',
    permission: 'manage_companies',
  },
})

const { t } = useI18n()
const router = useRouter()
const companiesStore = usePlatformCompaniesStore()
const { toast } = useAppToast()
const { confirm, ConfirmDialogComponent } = useConfirm()
const isLoading = ref(true)
const actionLoading = ref(null)

// Search & Filters
const searchQuery = ref('')
const statusFilter = ref('')
const planFilter = ref('')
const searchTimeout = ref(null)

const statusItems = computed(() => [
  { title: t('companies.allStatuses'), value: '' },
  { title: t('common.active'), value: 'active' },
  { title: t('companies.suspended'), value: 'suspended' },
])

const planItems = computed(() => [
  { title: t('companies.allPlans'), value: '' },
  ...companiesStore.plans.map(p => ({ title: p.name, value: p.key })),
])

const filters = computed(() => {
  const f = {}
  if (searchQuery.value) f.search = searchQuery.value
  if (statusFilter.value) f.status = statusFilter.value
  if (planFilter.value) f.plan_key = planFilter.value

  return f
})

const fetchWithFilters = async (page = 1) => {
  isLoading.value = true
  try {
    await companiesStore.fetchCompanies(page, filters.value)
  }
  finally {
    isLoading.value = false
  }
}

// Debounced search
watch(searchQuery, () => {
  clearTimeout(searchTimeout.value)
  searchTimeout.value = setTimeout(() => fetchWithFilters(), 400)
})

watch([statusFilter, planFilter], () => fetchWithFilters())

const onRowClick = (_event, { item }) => {
  router.push({ name: 'platform-companies-id', params: { id: item.id } })
}

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

const headers = computed(() => [
  { title: t('common.name'), key: 'name' },
  { title: t('common.slug'), key: 'slug' },
  { title: t('common.status'), key: 'status', align: 'center', width: '120px' },
  { title: t('Plan'), key: 'plan_key', align: 'center', width: '160px', sortable: false },
  { title: t('companies.members'), key: 'memberships_count', align: 'center', width: '100px' },
  { title: t('common.created'), key: 'created_at', width: '140px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '160px', sortable: false },
])

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

const onPageChange = async page => {
  await fetchWithFilters(page)
}

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

const fmtDate = dateStr => {
  if (!dateStr)
    return '—'

  return formatDate(dateStr)
}
</script>

<template>
  <div>
    <!-- KPI Cards -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
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
      <VCardTitle class="d-flex align-center">
        <VIcon icon="tabler-building" class="me-2" />
        {{ t('companies.title') }}
      </VCardTitle>
      <VCardSubtitle>
        {{ t('companies.subtitle') }}
      </VCardSubtitle>

      <!-- Search & Filters -->
      <VCardText>
        <VRow>
          <VCol cols="12" md="6">
            <AppTextField
              v-model="searchQuery"
              :placeholder="t('companies.searchPlaceholder')"
              prepend-inner-icon="tabler-search"
              clearable
              hide-details
            />
          </VCol>
          <VCol cols="6" md="3">
            <AppSelect
              v-model="statusFilter"
              :items="statusItems"
              hide-details
            />
          </VCol>
          <VCol cols="6" md="3">
            <AppSelect
              v-model="planFilter"
              :items="planItems"
              hide-details
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDataTable
        :headers="headers"
        :items="companiesStore.companies"
        :loading="isLoading"
        :items-per-page="-1"
        hide-default-footer
        hover
        class="cursor-pointer"
        @click:row="onRowClick"
      >
        <!-- Status -->
        <template #item.status="{ item }">
          <VChip
            :color="item.status === 'active' ? 'success' : 'error'"
            size="small"
          >
            {{ item.status }}
          </VChip>
        </template>

        <!-- Plan -->
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
          {{ item.memberships_count ?? '—' }}
        </template>

        <!-- Created at -->
        <template #item.created_at="{ item }">
          {{ fmtDate(item.created_at) }}
        </template>

        <!-- Actions -->
        <template #item.actions="{ item }">
          <VBtn
            v-if="item.status === 'active'"
            color="warning"
            variant="tonal"
            size="small"
            :loading="actionLoading === item.id"
            @click.stop="suspend(item)"
          >
            {{ t('companies.suspend') }}
          </VBtn>
          <VBtn
            v-else
            color="success"
            variant="tonal"
            size="small"
            :loading="actionLoading === item.id"
            @click.stop="reactivate(item)"
          >
            {{ t('companies.reactivate') }}
          </VBtn>
        </template>

        <!-- Empty state -->
        <template #no-data>
          <div class="text-center pa-4 text-disabled">
            {{ searchQuery || statusFilter || planFilter ? t('companies.noSearchResults') : t('companies.noCompanies') }}
          </div>
        </template>
      </VDataTable>

      <!-- Pagination -->
      <VCardText
        v-if="companiesStore.companiesPagination.last_page > 1"
        class="d-flex justify-center"
      >
        <VPagination
          :model-value="companiesStore.companiesPagination.current_page"
          :length="companiesStore.companiesPagination.last_page"
          @update:model-value="onPageChange"
        />
      </VCardText>
    </VCard>

    <ConfirmDialogComponent />
  </div>
</template>
