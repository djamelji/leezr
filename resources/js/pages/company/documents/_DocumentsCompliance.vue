<script setup>
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'

const { t } = useI18n()
const store = useCompanyDocumentsStore()

// ─── Compliance by Type table ───────────────────────────
const typeHeaders = computed(() => [
  { title: t('companyDocuments.compliance.documentType'), key: 'label' },
  { title: t('companyDocuments.compliance.scope'), key: 'scope' },
  { title: t('companyDocuments.compliance.total'), key: 'total' },
  { title: t('companyDocuments.compliance.valid'), key: 'valid' },
  { title: t('companyDocuments.compliance.missing'), key: 'missing' },
  { title: t('companyDocuments.compliance.expiring'), key: 'expiring_soon' },
  { title: t('companyDocuments.compliance.expired'), key: 'expired' },
  { title: t('companyDocuments.compliance.rate'), key: 'rate' },
])

const scopeLabel = scope => {
  return scope === 'company_user'
    ? t('documents.scopeMember')
    : t('documents.scopeCompany')
}

const scopeColor = scope => {
  return scope === 'company_user' ? 'warning' : 'primary'
}

const rateColor = rate => {
  if (rate >= 80) return 'success'
  if (rate >= 50) return 'warning'

  return 'error'
}

// ─── Filters ────────────────────────────────────────────
const statusFilter = ref('all')
const statusOptions = computed(() => [
  { title: t('companyDocuments.compliance.all'), value: 'all' },
  { title: t('companyDocuments.compliance.missing'), value: 'missing' },
  { title: t('companyDocuments.compliance.expiring'), value: 'expiring' },
  { title: t('companyDocuments.compliance.expired'), value: 'expired' },
])

const filteredTypes = computed(() => {
  const types = store.complianceByType
  if (statusFilter.value === 'all') return types
  if (statusFilter.value === 'missing') return types.filter(t => t.missing > 0)
  if (statusFilter.value === 'expiring') return types.filter(t => t.expiring_soon > 0)
  if (statusFilter.value === 'expired') return types.filter(t => t.expired > 0)

  return types
})

// ─── CSV Export (ADR-423) ───────────────────────────────
const exportCsv = () => {
  window.open('/api/company/documents/compliance/export', '_blank')
}
</script>

<template>
  <VSkeletonLoader
    v-if="store.loading.compliance"
    type="card, table"
  />
  <!-- Empty state (ADR-423) -->
  <VCard
    v-else-if="store.compliance.summary.total === 0"
    class="text-center pa-8"
  >
    <VIcon
      icon="tabler-shield-check"
      :size="64"
      color="disabled"
      class="mb-4"
    />
    <h5 class="text-h5 mb-2">
      {{ t('companyDocuments.emptyState.complianceTitle') }}
    </h5>
    <p class="text-body-1 text-medium-emphasis">
      {{ t('companyDocuments.emptyState.complianceSubtitle') }}
    </p>
  </VCard>

  <div v-else>
    <!-- Summary -->
    <VCard>
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="primary"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-shield-check" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companyDocuments.compliance.title') }}</VCardTitle>
        <VCardSubtitle>{{ t('companyDocuments.compliance.hint') }}</VCardSubtitle>
        <template #append>
          <div class="d-flex align-center gap-2">
            <VBtn
              v-can="'documents.manage'"
              variant="tonal"
              color="secondary"
              size="small"
              prepend-icon="tabler-download"
              @click="exportCsv"
            >
              {{ t('companyDocuments.compliance.exportCsv') }}
            </VBtn>
            <VChip
              size="large"
              :color="rateColor(store.complianceRate)"
              class="font-weight-bold"
            >
              {{ store.complianceRate }}%
            </VChip>
          </div>
        </template>
      </VCardItem>
      <VCardText>
        <div class="d-flex flex-wrap gap-3 mb-4">
          <VChip
            variant="tonal"
            color="success"
          >
            <VIcon
              icon="tabler-check"
              size="14"
              start
            />
            {{ t('companyDocuments.compliance.valid') }}: {{ store.compliance.summary.valid }}
          </VChip>
          <VChip
            variant="tonal"
            color="error"
          >
            <VIcon
              icon="tabler-file-off"
              size="14"
              start
            />
            {{ t('companyDocuments.compliance.missing') }}: {{ store.compliance.summary.missing }}
          </VChip>
          <VChip
            variant="tonal"
            color="warning"
          >
            <VIcon
              icon="tabler-file-alert"
              size="14"
              start
            />
            {{ t('companyDocuments.compliance.expiring') }}: {{ store.compliance.summary.expiring_soon }}
          </VChip>
          <VChip
            variant="tonal"
            color="error"
          >
            <VIcon
              icon="tabler-file-x"
              size="14"
              start
            />
            {{ t('companyDocuments.compliance.expired') }}: {{ store.compliance.summary.expired }}
          </VChip>
        </div>
      </VCardText>
    </VCard>

    <!-- By Type Detail -->
    <VCard class="mt-6">
      <VCardItem>
        <template #prepend>
          <VAvatar
            color="info"
            variant="tonal"
            rounded
          >
            <VIcon icon="tabler-file-analytics" />
          </VAvatar>
        </template>
        <VCardTitle>{{ t('companyDocuments.compliance.byType') }}</VCardTitle>
        <template #append>
          <AppSelect
            v-model="statusFilter"
            :items="statusOptions"
            density="compact"
            style="inline-size: 160px;"
          />
        </template>
      </VCardItem>
      <VCardText>
        <VTable v-if="filteredTypes.length > 0">
          <thead>
            <tr>
              <th
                v-for="h in typeHeaders"
                :key="h.key"
              >
                {{ h.title }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="type in filteredTypes"
              :key="type.code"
            >
              <td>
                {{ t(`documents.type.${type.code}`, type.label) }}
              </td>
              <td>
                <VChip
                  size="x-small"
                  :color="scopeColor(type.scope)"
                >
                  {{ scopeLabel(type.scope) }}
                </VChip>
              </td>
              <td>{{ type.total }}</td>
              <td class="text-success">
                {{ type.valid }}
              </td>
              <td :class="type.missing > 0 ? 'text-error font-weight-bold' : ''">
                {{ type.missing }}
              </td>
              <td :class="type.expiring_soon > 0 ? 'text-warning font-weight-bold' : ''">
                {{ type.expiring_soon }}
              </td>
              <td :class="type.expired > 0 ? 'text-error font-weight-bold' : ''">
                {{ type.expired }}
              </td>
              <td>
                <VChip
                  size="small"
                  :color="rateColor(type.rate)"
                >
                  {{ type.rate }}%
                </VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
        <VAlert
          v-else
          type="info"
          variant="tonal"
        >
          {{ t('companyDocuments.compliance.noData') }}
        </VAlert>
      </VCardText>
    </VCard>
  </div>
</template>
