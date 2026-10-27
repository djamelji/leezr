<script setup>
definePage({
  meta: {
    surface: 'structure',
    module: 'core.billing',
    navActiveLink: 'company-billing-tab',
    permission: 'billing.manage',
  },
})

import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { formatDate } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const router = useRouter()
const store = useCompanyBillingStore()

const preview = computed(() => store.consolidatedPreview)

const { isLoading, isError, error } = useAsyncAction(async () => {
  await store.fetchConsolidatedPreview()
}, { immediate: true })

const fmt = (cents, currency) => formatMoney(cents, { currency: currency || preview.value?.currency || 'EUR' })

const sectionLabel = key => t(`companyBilling.invoiceDetail.sections.${key}`, key)

const rowPeriod = row => row.period_known
  ? `${formatDate(row.period_start)} – ${formatDate(row.period_end)}`
  : t('companyBilling.consolidatedPreview.periodToConfirm')

const goToInvoices = () => router.push('/company/billing/invoices')
const openInvoice = id => router.push(`/company/billing/invoices/${id}`)
</script>

<template>
  <div>
    <VSkeletonLoader
      v-if="isLoading"
      type="card, table"
    />

    <ErrorState
      v-else-if="isError"
      :message="error"
    />

    <template v-else-if="preview">
      <!-- Non-accounting simulation warning (ADR-632) -->
      <VAlert
        type="warning"
        variant="tonal"
        prominent
        class="mb-4"
        :icon="false"
      >
        <div class="d-flex align-center flex-wrap gap-2">
          <VIcon
            icon="tabler-eye"
            class="me-1"
          />
          <strong>{{ t('companyBilling.consolidatedPreview.warningTitle') }}</strong>
          <span class="text-body-2">— {{ t('companyBilling.consolidatedPreview.warningBody') }}</span>
        </div>
      </VAlert>

      <VCard class="mb-4">
        <VCardTitle class="d-flex align-center flex-wrap gap-2">
          <VIcon
            icon="tabler-layers-intersect"
            class="me-2"
          />
          {{ t('companyBilling.consolidatedPreview.title') }}
          <VChip
            size="small"
            color="warning"
            variant="tonal"
          >
            {{ t('companyBilling.consolidatedPreview.badge') }}
          </VChip>
          <VSpacer />
          <VBtn
            variant="tonal"
            size="small"
            prepend-icon="tabler-arrow-left"
            @click="goToInvoices"
          >
            {{ t('companyBilling.consolidatedPreview.backToInvoices') }}
          </VBtn>
        </VCardTitle>

        <VCardText>
          <p class="text-body-2 text-medium-emphasis mb-4">
            {{ preview.company_name }} — {{ t('companyBilling.consolidatedPreview.sourceNote') }}
          </p>

          <VAlert
            v-if="preview.multi_currency"
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('companyBilling.consolidatedPreview.multiCurrency', { currency: preview.currency }) }}
          </VAlert>

          <!-- Sections by prestation family -->
          <div
            v-for="section in preview.sections"
            :key="section.key"
            class="mb-5"
          >
            <h6 class="text-h6 mb-2">
              {{ sectionLabel(section.key) }}
            </h6>
            <VTable
              density="compact"
              class="border rounded text-high-emphasis preview-table"
            >
              <thead>
                <tr>
                  <th scope="col">
                    {{ t('companyBilling.consolidatedPreview.prestation') }}
                  </th>
                  <th scope="col">
                    {{ t('companyBilling.consolidatedPreview.invoiceRef') }}
                  </th>
                  <th scope="col">
                    {{ t('companyBilling.invoiceDetail.servicePeriod') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('companyBilling.invoiceDetail.ht') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('companyBilling.invoiceDetail.vat') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('companyBilling.invoiceDetail.ttc') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="row in section.rows"
                  :key="row.invoice_id"
                >
                  <td>
                    {{ row.prestation_label }}
                    <VChip
                      v-if="row.is_annexe"
                      size="x-small"
                      color="secondary"
                      variant="tonal"
                      class="ms-1"
                    >
                      {{ t('companyBilling.consolidatedPreview.annexe') }}
                    </VChip>
                  </td>
                  <td>
                    <a
                      href="#"
                      class="text-primary text-decoration-none"
                      @click.prevent="openInvoice(row.invoice_id)"
                    >{{ row.invoice_ref }}</a>
                  </td>
                  <td
                    class="text-no-wrap text-body-2"
                    :class="{ 'text-disabled fst-italic': !row.period_known }"
                  >
                    {{ rowPeriod(row) }}
                  </td>
                  <td class="text-end">
                    {{ fmt(row.ht_cents) }}
                  </td>
                  <td class="text-end">
                    {{ fmt(row.tax_cents) }}
                  </td>
                  <td class="text-end">
                    {{ fmt(row.ttc_cents) }}
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="font-weight-medium">
                  <td
                    colspan="3"
                    class="text-end"
                  >
                    {{ t('companyBilling.invoiceDetail.sectionSubtotal') }}
                  </td>
                  <td class="text-end">
                    {{ fmt(section.subtotal_ht_cents) }}
                  </td>
                  <td class="text-end">
                    {{ fmt(section.subtotal_tax_cents) }}
                  </td>
                  <td class="text-end">
                    {{ fmt(section.subtotal_ttc_cents) }}
                  </td>
                </tr>
              </tfoot>
            </VTable>
          </div>

          <div
            v-if="!preview.sections.length"
            class="text-center text-disabled py-4"
          >
            {{ t('companyBilling.consolidatedPreview.noData') }}
          </div>

          <VDivider class="my-4" />

          <!-- Existing-invoices total (real receivables only) -->
          <div class="d-flex justify-end">
            <table class="w-auto">
              <tbody>
                <tr>
                  <td class="pe-8 text-body-2">
                    {{ t('companyBilling.consolidatedPreview.existingTotalHt') }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ fmt(preview.existing_total.ht_cents) }}
                  </td>
                </tr>
                <tr>
                  <td class="pe-8 text-body-2">
                    {{ t('companyBilling.invoiceDetail.vat') }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ fmt(preview.existing_total.tax_cents) }}
                  </td>
                </tr>
                <tr>
                  <td class="pe-8 font-weight-bold">
                    {{ t('companyBilling.consolidatedPreview.existingTotalTtc') }}
                  </td>
                  <td class="text-end font-weight-bold">
                    {{ fmt(preview.existing_total.ttc_cents) }}
                  </td>
                </tr>
                <tr v-if="preview.existing_total.paid_cents">
                  <td class="pe-8 text-body-2 text-success">
                    {{ t('companyBilling.consolidatedPreview.paid') }}
                  </td>
                  <td class="text-end text-success">
                    {{ fmt(preview.existing_total.paid_cents) }}
                  </td>
                </tr>
                <tr>
                  <td class="pe-8 text-body-2">
                    {{ t('companyBilling.consolidatedPreview.due') }}
                  </td>
                  <td
                    class="text-end font-weight-medium"
                    :class="preview.existing_total.due_cents > 0 ? 'text-error' : 'text-success'"
                  >
                    {{ fmt(preview.existing_total.due_cents) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </VCardText>
      </VCard>

      <!-- Provisional simulation — NEVER part of the receivables total -->
      <VCard
        v-if="preview.provisional_simulation.length"
        variant="outlined"
        class="mb-4"
      >
        <VCardText>
          <div class="d-flex align-center mb-2">
            <VIcon
              icon="tabler-flask"
              color="info"
              class="me-2"
            />
            <strong>{{ t('companyBilling.consolidatedPreview.provisionalTitle') }}</strong>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-3">
            {{ t('companyBilling.consolidatedPreview.provisionalNote') }}
          </p>
          <div
            v-for="sim in preview.provisional_simulation"
            :key="sim.plan_key"
            class="d-flex align-center justify-space-between py-1"
          >
            <span>
              {{ sim.label }}
              <VChip
                size="x-small"
                color="info"
                variant="tonal"
                class="ms-1"
              >
                {{ t('companyBilling.consolidatedPreview.notBilled') }}
              </VChip>
            </span>
            <span class="text-medium-emphasis">
              {{ fmt(sim.ht_cents_indicative, sim.currency) }} {{ t('companyBilling.consolidatedPreview.htIndicative') }}
            </span>
          </div>
        </VCardText>
      </VCard>
    </template>
  </div>
</template>
