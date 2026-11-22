<script setup>
// ADR-862 (L7 closure) — one strip of indicators (no « label: n » chips), the export next to the data it exports,
// a list on a phone instead of an eight-column table cut on the right, rate shown as progress per type.
import { useDisplay } from 'vuetify'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import AppStatStrip from '@/components/app/AppStatStrip.vue'
import { useCompanyDocumentsStore } from '@/modules/company/documents/documents.store'

const { t, locale } = useI18n()
const store = useCompanyDocumentsStore()
const { xs } = useDisplay()

const scopeLabel = scope => (scope === 'company_user' ? t('l7.docs.compliance.scopePerson') : t('l7.docs.compliance.scopeCompany'))

const scopeColor = scope => {
  return scope === 'company_user' ? 'info' : 'primary'
}

const summary = computed(() => store.compliance.summary || {})
const rateTone = rate => (rate >= 80 ? 'success' : rate >= 50 ? 'warning' : 'error')
const stats = computed(() => {
  const s = summary.value

  return [
    { key: 'rate', label: t('l7.docs.compliance.rate'), value: `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(store.complianceRate || 0)} %`, icon: 'tabler-shield-check', tone: rateTone(store.complianceRate), help: t('l7.docs.compliance.rateHelp'), testid: 'compliance-rate' },
    { key: 'valid', label: t('l7.docs.compliance.valid'), value: s.valid ?? 0, icon: 'tabler-circle-check', tone: 'success' },
    { key: 'missing', label: t('l7.docs.compliance.missing'), value: s.missing ?? 0, icon: 'tabler-file-off', tone: s.missing ? 'error' : 'muted' },
    { key: 'renew', label: t('l7.docs.compliance.renew'), value: (s.expiring_soon ?? 0) + (s.expired ?? 0), icon: 'tabler-calendar-exclamation', tone: (s.expiring_soon || s.expired) ? 'warning' : 'muted', help: t('l7.docs.compliance.renewHelp', { expiring: s.expiring_soon ?? 0, expired: s.expired ?? 0 }) },
    { key: 'progress', label: t('l7.docs.compliance.inProgress'), value: (s.pending_validation ?? 0) + (s.requested ?? 0), icon: 'tabler-hourglass', tone: 'info', help: t('l7.docs.compliance.inProgressHelp', { review: s.pending_validation ?? 0, requested: s.requested ?? 0, rejected: s.rejected ?? 0 }) },
  ]
})

// ─── Filters ────────────────────────────────────────────
// ADR-776 : un lien « À traiter » ouvre la conformité déjà filtrée (?status=expired|expiring|missing).
const statusFilter = ref(['missing', 'expiring', 'expired'].includes(useRoute().query.status) ? useRoute().query.status : 'all')
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
  <VCard v-else-if="store.compliance.summary.total === 0">
    <AppEmptyState
      icon="tabler-shield-check"
      :title="t('companyDocuments.emptyState.complianceTitle')"
      :text="t('companyDocuments.emptyState.complianceSubtitle')"
    />
  </VCard>

  <div
    v-else
    data-test="documents-compliance"
  >
    <AppStatStrip
      :items="stats"
      class="mb-6"
    />

    <VCard>
      <VCardItem>
        <VCardTitle>{{ t('l7.docs.compliance.byType') }}</VCardTitle>
        <VCardSubtitle class="text-wrap">
          {{ t('l7.docs.compliance.byTypeHint') }}
        </VCardSubtitle>
        <template
          v-if="!xs"
          #append
        >
          <div class="d-flex align-center gap-2">
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :aria-label="t('l7.docs.compliance.filter')"
              density="compact"
              style="inline-size: 150px;"
            />
            <VBtn
              v-can="'documents.manage'"
              variant="tonal"
              color="secondary"
              prepend-icon="tabler-download"
              data-test="compliance-export"
              @click="exportCsv"
            >
              {{ t('l7.docs.compliance.exportCsv') }}
            </VBtn>
          </div>
        </template>
      </VCardItem>
      <div
        v-if="xs"
        class="d-flex align-center gap-2 px-4 pb-3"
      >
        <AppSelect
          v-model="statusFilter"
          :items="statusOptions"
          :aria-label="t('l7.docs.compliance.filter')"
          density="compact"
          class="flex-grow-1"
        />
        <VBtn
          v-can="'documents.manage'"
          variant="tonal"
          color="secondary"
          icon
          :aria-label="t('l7.docs.compliance.exportCsv')"
          @click="exportCsv"
        >
          <VIcon icon="tabler-download" />
        </VBtn>
      </div>

      <AppEmptyState
        v-if="!filteredTypes.length"
        compact
        icon="tabler-filter-off"
        :title="t('l7.docs.compliance.emptyFilter')"
      />

      <!-- phone : one line per type, what matters first -->
      <VList
        v-else-if="xs"
        class="py-0"
        data-test="compliance-list"
      >
        <template
          v-for="(type, i) in filteredTypes"
          :key="type.code"
        >
          <VDivider v-if="i" />
          <VListItem class="py-3">
            <div class="d-flex align-center justify-space-between gap-2">
              <span class="font-weight-medium text-truncate">{{ t(`documents.type.${type.code}`, type.label) }}</span>
              <VChip
                size="small"
                label
                :color="rateTone(type.rate)"
              >
                {{ new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(type.rate || 0) }} %
              </VChip>
            </div>
            <VProgressLinear
              :model-value="type.rate"
              :color="rateTone(type.rate)"
              rounded
              height="6"
              class="my-2"
            />
            <div class="text-body-2 text-medium-emphasis">
              {{ scopeLabel(type.scope) }} · {{ t('l7.docs.compliance.line', { missing: type.missing, renew: type.expiring_soon + type.expired }) }}
            </div>
          </VListItem>
        </template>
      </VList>

      <VTable
        v-else
        class="text-no-wrap"
      >
        <thead>
          <tr>
            <th>{{ t('companyDocuments.compliance.documentType') }}</th>
            <th>{{ t('companyDocuments.compliance.scope') }}</th>
            <th style="min-inline-size: 220px;">
              {{ t('l7.docs.compliance.valid') }}
            </th>
            <th class="text-end">
              {{ t('l7.docs.compliance.missing') }}
            </th>
            <th class="text-end">
              {{ t('l7.docs.compliance.renew') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="type in filteredTypes"
            :key="type.code"
          >
            <td class="font-weight-medium">
              {{ t(`documents.type.${type.code}`, type.label) }}
            </td>
            <td>
              <VChip
                size="small"
                label
                variant="tonal"
                :color="scopeColor(type.scope)"
              >
                {{ scopeLabel(type.scope) }}
              </VChip>
            </td>
            <td>
              <div class="d-flex align-center gap-3">
                <VProgressLinear
                  :model-value="type.rate"
                  :color="rateTone(type.rate)"
                  rounded
                  height="6"
                  style="max-inline-size: 140px;"
                />
                <span class="text-body-2">{{ t('l7.docs.compliance.present', { valid: type.valid, total: type.total }, type.valid) }}</span>
              </div>
            </td>
            <td
              class="text-end"
              :class="type.missing ? 'text-error font-weight-medium' : 'text-disabled'"
            >
              {{ type.missing }}
            </td>
            <td
              class="text-end"
              :class="(type.expiring_soon + type.expired) ? 'text-warning font-weight-medium' : 'text-disabled'"
            >
              {{ type.expiring_soon + type.expired }}
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
