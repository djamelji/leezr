<script setup>
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatDate } from '@/utils/datetime'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_companies',
  },
})

const { t } = useI18n()
const router = useRouter()
const companiesStore = usePlatformCompaniesStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const actionLoading = ref(null)

const onRowClick = (_event, { item }) => {
  router.push({ name: 'platform-companies-id', params: { id: item.id } })
}

onMounted(async () => {
  try {
    await Promise.all([
      companiesStore.fetchCompanies(),
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
  if (!confirm(t('companies.confirmSuspend', { name: company.name })))
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
  isLoading.value = true

  try {
    await companiesStore.fetchCompanies(page)
  }
  finally {
    isLoading.value = false
  }
}

const fmtDate = dateStr => {
  if (!dateStr)
    return '—'

  return formatDate(dateStr)
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-building"
          class="me-2"
        />
        {{ t('companies.title') }}
      </VCardTitle>
      <VCardSubtitle>
        {{ t('companies.subtitle') }}
      </VCardSubtitle>

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
            {{ t('companies.noCompanies') }}
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
  </div>
</template>
