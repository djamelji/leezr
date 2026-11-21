<script setup>
// ADR-834 — what a creation operator (`create_companies` without `view_companies`) sees under Companies :
// the companies HE created, with their start-of-service stage, and the « New company » action. Nothing else.
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { STAGE_COLORS, STAGE_ICONS } from '@/modules/platform-admin/companies/companyCreationRules'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const store = usePlatformCompaniesStore()

const companies = ref([])
const loading = ref(true)

const headers = computed(() => [
  { title: t('common.name'), key: 'name' },
  { title: t('platformCompanyCreation.list.stage'), key: 'stage', sortable: false },
  { title: t('platformCompanyCreation.list.createdAt'), key: 'created_at', width: '160px' },
  { title: t('common.actions'), key: 'actions', align: 'center', width: '100px', sortable: false },
])

onMounted(async () => {
  try {
    companies.value = (await store.fetchMyCreatedCompanies()).companies
  }
  finally {
    loading.value = false
  }
})
</script>

<template>
  <VCard>
    <VCardItem>
      <VCardTitle>{{ t('platformCompanyCreation.list.title') }}</VCardTitle>
      <VCardSubtitle>{{ t('platformCompanyCreation.list.subtitle') }}</VCardSubtitle>
      <template #append>
        <VBtn
          prepend-icon="tabler-plus"
          :to="{ name: 'platform-companies-create' }"
          data-testid="new-company"
        >
          {{ t('platformCompanyCreation.newCompany') }}
        </VBtn>
      </template>
    </VCardItem>

    <VDataTable
      :headers="headers"
      :items="companies"
      :loading="loading"
      :no-data-text="t('platformCompanyCreation.list.empty')"
    >
      <template #item.name="{ item }">
        <RouterLink
          :to="{ name: 'platform-companies-id', params: { id: item.id, tab: 'onboarding' } }"
          class="font-weight-medium"
        >
          {{ item.name }}
        </RouterLink>
      </template>
      <template #item.stage="{ item }">
        <VChip
          size="small"
          :color="STAGE_COLORS[item.stage]"
          :prepend-icon="STAGE_ICONS[item.stage]"
          variant="tonal"
        >
          {{ t(`platformCompanyCreation.stages.${item.stage}`) }}
        </VChip>
      </template>
      <template #item.created_at="{ item }">
        {{ formatDate(item.created_at) }}
      </template>
      <template #item.actions="{ item }">
        <IconBtn
          :to="{ name: 'platform-companies-id', params: { id: item.id, tab: 'onboarding' } }"
          :aria-label="t('platformCompanyCreation.list.open')"
        >
          <VIcon icon="tabler-eye" />
        </IconBtn>
      </template>
    </VDataTable>
  </VCard>
</template>
