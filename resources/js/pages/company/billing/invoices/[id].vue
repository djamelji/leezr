<script setup>
definePage({
  meta: {
    surface: 'structure',
    module: 'core.billing',
    navActiveLink: 'company-billing-tab',
    permission: 'billing.manage',
  },
})

import BrandLogo from '@/components/BrandLogo.vue'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { $api } from '@/utils/api'
import { invoiceStatusColor } from '@/utils/billing'
import { formatDate, formatDateTime } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useCompanyBillingStore()

const invoice = computed(() => store.invoiceDetail)
const snap = computed(() => invoice.value?.billing_snapshot || {})

// Market locale for date formatting (e.g. "fr-FR")
const marketLocale = computed(() => snap.value?.market_locale || 'fr-FR')

const { isLoading, isError, error, retry } = useAsyncAction(async () => {
  await store.fetchInvoiceDetail(route.params.id)
  if (!store.invoiceDetail) throw new Error(t('companyBilling.invoiceDetail.notFound'))
}, { immediate: true })

// ── Helpers ──
const isPastDue = computed(() => {
  if (!invoice.value?.due_at) return false

  return new Date(invoice.value.due_at) < new Date()
})

const statusColor = computed(() => {
  const status = invoice.value?.status
  if (status === 'open' && isPastDue.value) return 'error'

  return invoiceStatusColor(status)
})

const statusLabel = computed(() => {
  const status = invoice.value?.status
  if (status === 'open' && isPastDue.value) return t('companyBilling.invoiceDetail.statusPastDue')
  if (status === 'open') return t('companyBilling.invoiceDetail.statusOpen')

  const key = `companyBilling.invoiceDetail.status${status?.charAt(0).toUpperCase()}${status?.slice(1)}`

  return t(key, status)
})

const typeLabel = type => {
  const map = {
    plan_change: 'typePlanChange',
    proration: 'typeProration',
    credit: 'typeCredit',
    charge: 'typeCharge',
    addon: 'typeAddon',
    renewal: 'typeRenewal',
  }
  const key = map[type]

  return key ? t(`companyBilling.invoiceDetail.${key}`) : type
}

const paymentStatusLabel = status => {
  const map = { succeeded: 'paymentSucceeded', failed: 'paymentFailed', pending: 'paymentPending' }
  const key = map[status]

  return key ? t(`companyBilling.invoiceDetail.${key}`) : status
}

const taxPercent = computed(() => {
  if (!invoice.value?.tax_rate_bps) return 0

  return (invoice.value.tax_rate_bps / 100).toFixed(1)
})

const fmt = (amount, currency) => formatMoney(amount, { currency: currency || invoice.value?.currency })

// ── ADR-632 (M9) : présentation consolidée V2 ──
// V2 = une facture consolidée rendue par sections (abonnement / modules / proratas / ajustements /
// remises). Aucun recalcul côté Vue : montants (cents) issus de la ventilation analytique persistée.
const isV2 = computed(() => Number(invoice.value?.billing_model_version) === 2)

// Index des InvoiceLine par id → libellé historique + quantité (les analytics ne portent pas le texte).
const lineById = computed(() => {
  const m = {}
  for (const l of (invoice.value?.lines || [])) m[l.id] = l

  return m
})

const v2Sections = computed(() => invoice.value?.consolidated_sections?.sections || [])

const V2_SECTION_ORDER = ['subscription', 'modules', 'proratas', 'adjustments', 'discounts']

const v2SectionsSorted = computed(() =>
  [...v2Sections.value].sort((a, b) => V2_SECTION_ORDER.indexOf(a.key) - V2_SECTION_ORDER.indexOf(b.key)),
)

const openSections = ref([])

watch(v2Sections, s => { openSections.value = (s || []).map(x => x.key) }, { immediate: true })

const sectionLabel = key => t(`companyBilling.invoiceDetail.sections.${key}`, key)
const v2LineDesc = l => lineById.value[l.invoice_line_id]?.description || l.module_key || typeLabel(l.prestation_type)
const v2LineQty = l => lineById.value[l.invoice_line_id]?.quantity ?? 1

const v2LinePeriod = l => (l.service_period_start && l.service_period_end)
  ? `${formatDate(l.service_period_start)} – ${formatDate(l.service_period_end)}`
  : '—'

const canPay = computed(() => {
  if (!invoice.value) return false

  return ['open', 'overdue', 'uncollectible'].includes(invoice.value.status) && invoice.value.amount_due > 0
})

const downloadPdf = async () => {
  if (!invoice.value) return
  try {
    const blob = await $api(`/billing/invoices/${invoice.value.id}/pdf`, {
      responseType: 'blob',
    })

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${invoice.value.number || `invoice-${invoice.value.id}`}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    // Error handled by $api onResponseError
  }
}

const printInvoice = () => {
  window.print()
}

// ADR-334/336: Redirect to pay page with pre-selected invoice via store (no query param)
const payInvoice = () => {
  store.setPreSelectedInvoices([invoice.value.id])
  router.push('/company/billing/pay')
}
</script>

<template>
  <div>
    <!-- Loading -->
    <VSkeletonLoader
      v-if="isLoading"
      type="card, card"
    />

    <!-- Error -->
    <ErrorState
      v-else-if="isError"
      :message="error"
      @retry="retry"
    >
      <VBtn
        variant="tonal"
        color="primary"
        class="mt-2"
        @click="router.push({ name: 'company-billing-tab', params: { tab: 'invoices' } })"
      >
        {{ t('companyBilling.invoiceDetail.backToBilling') }}
      </VBtn>
    </ErrorState>

    <!-- Content -->
    <template v-else-if="invoice">
      <PageBreadcrumbs
        :items="[
          { title: t('companyBilling.title'), to: { name: 'company-billing-tab', params: { tab: 'invoices' } } },
          { title: invoice.display_number || invoice.number },
        ]"
      />
      <VRow>
        <!-- Main Content (9 cols) -->
        <VCol
          cols="12"
          md="9"
        >
          <VCard class="invoice-preview-wrapper pa-4 pa-sm-8">
            <!-- Header -->
            <div class="invoice-header-preview d-flex flex-wrap justify-space-between flex-column flex-sm-row print-row gap-4 mb-4">
              <!-- Left: Logo -->
              <div>
                <BrandLogo size="lg" />
              </div>

              <!-- Right: Invoice meta -->
              <div class="text-sm-end">
                <h6 class="font-weight-medium text-lg mb-2">
                  {{ invoice.display_number || invoice.number || `#${invoice.id}` }}
                </h6>
                <VChip
                  :color="statusColor"
                  size="small"
                  class="mb-2"
                >
                  {{ statusLabel }}
                </VChip>
                <div class="text-body-2">
                  <div>{{ t('companyBilling.invoiceDetail.issuedAt') }} : {{ formatDate(invoice.issued_at) }}</div>
                  <div>{{ t('companyBilling.invoiceDetail.dueAt') }} : {{ formatDate(invoice.due_at) }}</div>
                  <div v-if="invoice.paid_at">
                    {{ t('companyBilling.invoiceDetail.paidAt') }} : {{ formatDate(invoice.paid_at) }}
                  </div>
                </div>
              </div>
            </div>

            <VDivider class="mb-4" />

            <!-- Invoice To + Billing Details -->
            <VRow class="print-row mb-4">
              <VCol class="text-no-wrap">
                <h6 class="text-h6 mb-2">
                  {{ t('companyBilling.invoiceDetail.invoiceTo') }}
                </h6>
                <p class="mb-0 font-weight-medium">
                  {{ snap.company_legal_name || snap.company_name || '—' }}
                </p>
                <p
                  v-if="snap.billing_address"
                  class="mb-0 text-body-2"
                >
                  {{ snap.billing_address }}
                </p>
                <p
                  v-if="snap.billing_email"
                  class="mb-0 text-body-2"
                >
                  {{ snap.billing_email }}
                </p>
                <p
                  v-if="snap.vat_number"
                  class="mb-0 text-body-2"
                >
                  {{ t('companyBilling.invoiceDetail.vatNumber') }} : {{ snap.vat_number }}
                </p>
                <p
                  v-if="snap.siret"
                  class="mb-0 text-body-2"
                >
                  {{ t('companyBilling.invoiceDetail.siret') }} : {{ snap.siret }}
                </p>
              </VCol>

              <VCol class="text-no-wrap">
                <h6 class="text-h6 mb-2">
                  {{ t('companyBilling.invoiceDetail.billingDetails') }}
                </h6>
                <table class="text-body-2">
                  <tbody>
                    <tr v-if="invoice.period_start && invoice.period_end">
                      <td class="pe-4 text-disabled">
                        {{ t('companyBilling.invoiceDetail.period') }}
                      </td>
                      <td>{{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }}</td>
                    </tr>
                    <tr>
                      <td class="pe-4 text-disabled">
                        {{ t('companyBilling.invoiceDetail.dueAt') }}
                      </td>
                      <td>{{ formatDate(invoice.due_at) }}</td>
                    </tr>
                    <tr v-if="snap.market_name">
                      <td class="pe-4 text-disabled">
                        {{ t('companyBilling.invoiceDetail.market') }}
                      </td>
                      <td>{{ snap.market_name }}</td>
                    </tr>
                    <tr v-if="snap.legal_status_name">
                      <td class="pe-4 text-disabled">
                        {{ t('companyBilling.invoiceDetail.legalStatus') }}
                      </td>
                      <td>{{ snap.legal_status_name }}</td>
                    </tr>
                    <tr v-if="invoice.provider">
                      <td class="pe-4 text-disabled">
                        {{ t('companyBilling.invoiceDetail.paymentProvider') }}
                      </td>
                      <td class="text-capitalize">
                        {{ invoice.provider }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </VCol>
            </VRow>

            <!-- Line Items Table (V1) -->
            <VTable
              v-if="!isV2"
              class="invoice-preview-table border text-high-emphasis overflow-hidden mb-4"
            >
              <thead>
                <tr>
                  <th scope="col">
                    {{ t('companyBilling.invoiceDetail.lineDescription') }}
                  </th>
                  <th scope="col">
                    {{ t('companyBilling.invoiceDetail.lineType') }}
                  </th>
                  <th
                    scope="col"
                    class="text-center"
                  >
                    {{ t('companyBilling.invoiceDetail.lineQty') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('companyBilling.invoiceDetail.lineUnitPrice') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('companyBilling.invoiceDetail.lineTotal') }}
                  </th>
                </tr>
              </thead>
              <tbody class="text-base">
                <tr
                  v-for="line in invoice.lines"
                  :key="line.id"
                >
                  <td class="text-no-wrap">
                    {{ line.description }}
                  </td>
                  <td class="text-no-wrap">
                    <VChip
                      size="x-small"
                      color="secondary"
                    >
                      {{ typeLabel(line.type) }}
                    </VChip>
                  </td>
                  <td class="text-center">
                    {{ line.quantity }}
                  </td>
                  <td class="text-end">
                    {{ fmt(line.unit_amount) }}
                  </td>
                  <td class="text-end">
                    {{ fmt(line.amount) }}
                  </td>
                </tr>
              </tbody>
            </VTable>

            <!-- Consolidated sections (V2 — ADR-632) -->
            <div
              v-else
              class="v2-sections mb-4"
            >
              <VExpansionPanels
                v-model="openSections"
                multiple
                variant="accordion"
                class="border rounded"
              >
                <VExpansionPanel
                  v-for="section in v2SectionsSorted"
                  :key="section.key"
                  :value="section.key"
                >
                  <VExpansionPanelTitle>
                    <div class="d-flex align-center justify-space-between w-100 pe-2">
                      <span class="font-weight-medium">
                        {{ sectionLabel(section.key) }}
                        <span class="text-disabled">({{ section.lines.length }})</span>
                      </span>
                      <span class="font-weight-medium">{{ fmt(section.subtotal_ttc_cents) }}</span>
                    </div>
                  </VExpansionPanelTitle>
                  <VExpansionPanelText>
                    <VTable
                      density="compact"
                      class="v2-section-table text-high-emphasis"
                    >
                      <thead>
                        <tr>
                          <th scope="col">
                            {{ t('companyBilling.invoiceDetail.lineDescription') }}
                          </th>
                          <th scope="col">
                            {{ t('companyBilling.invoiceDetail.servicePeriod') }}
                          </th>
                          <th
                            scope="col"
                            class="text-center"
                          >
                            {{ t('companyBilling.invoiceDetail.lineQty') }}
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
                          v-for="(l, idx) in section.lines"
                          :key="l.invoice_line_id || idx"
                        >
                          <td>
                            {{ v2LineDesc(l) }}
                            <VChip
                              v-if="l.billable_event_id"
                              size="x-small"
                              color="info"
                              variant="tonal"
                              class="ms-1"
                            >
                              {{ t('companyBilling.invoiceDetail.prorataBadge') }}
                            </VChip>
                          </td>
                          <td class="text-no-wrap text-body-2">
                            {{ v2LinePeriod(l) }}
                          </td>
                          <td class="text-center">
                            {{ v2LineQty(l) }}
                          </td>
                          <td class="text-end">
                            {{ fmt(l.ht_cents) }}
                          </td>
                          <td class="text-end">
                            {{ fmt(l.tax_cents) }}
                          </td>
                          <td class="text-end">
                            {{ fmt(l.ttc_cents) }}
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
                  </VExpansionPanelText>
                </VExpansionPanel>
              </VExpansionPanels>
            </div>

            <!-- Totals + Note -->
            <div class="d-flex justify-space-between flex-column flex-sm-row print-row">
              <div class="mb-2">
                <template v-if="invoice.notes">
                  <h6 class="text-h6 mb-1">
                    {{ t('companyBilling.invoiceDetail.notes') }} :
                  </h6>
                  <p class="text-body-2">
                    {{ invoice.notes }}
                  </p>
                </template>
              </div>

              <div>
                <table class="w-100">
                  <tbody>
                    <tr>
                      <td class="pe-16 text-body-2">
                        {{ t('companyBilling.invoiceDetail.subtotal') }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.subtotal) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.tax_amount">
                      <td class="pe-16 text-body-2">
                        {{ t('companyBilling.invoiceDetail.tax', { rate: taxPercent }) }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.tax_amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.tax_exemption_reason">
                      <td
                        colspan="2"
                        class="py-1"
                      >
                        <VChip
                          color="info"
                          variant="tonal"
                          size="small"
                        >
                          {{ t('billing.tax_exemption.' + invoice.tax_exemption_reason) }}
                        </VChip>
                      </td>
                    </tr>
                    <tr v-if="invoice.wallet_credit_applied">
                      <td class="pe-16 text-body-2">
                        {{ t('companyBilling.invoiceDetail.walletCredit') }}
                      </td>
                      <td class="text-end font-weight-medium text-success">
                        -{{ fmt(invoice.wallet_credit_applied) }}
                      </td>
                    </tr>
                  </tbody>
                </table>

                <VDivider class="my-2" />

                <table class="w-100">
                  <tbody>
                    <tr>
                      <td class="pe-16 font-weight-medium">
                        {{ t('companyBilling.invoiceDetail.total') }}
                      </td>
                      <td class="text-end font-weight-bold">
                        {{ fmt(invoice.amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.amount_due !== invoice.amount">
                      <td class="pe-16 text-body-2">
                        {{ t('companyBilling.invoiceDetail.amountDue') }}
                      </td>
                      <td
                        class="text-end font-weight-medium"
                        :class="invoice.amount_due > 0 ? 'text-error' : 'text-success'"
                      >
                        {{ fmt(invoice.amount_due) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </VCard>

          <!-- Payments -->
          <VCard class="mt-4 mb-4">
            <VCardTitle>
              <VIcon
                icon="tabler-cash"
                class="me-2"
              />
              {{ t('companyBilling.invoiceDetail.payments') }}
            </VCardTitle>
            <VCardText>
              <div
                v-if="!invoice.payments?.length && !invoice.wallet_credit_applied"
                class="text-center text-disabled"
              >
                {{ t('companyBilling.invoiceDetail.noPayments') }}
              </div>
              <VTable
                v-else
                density="compact"
              >
                <thead>
                  <tr>
                    <th>{{ t('companyBilling.invoiceDetail.paymentAmount') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.paymentStatus') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.paymentProvider') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.paymentDate') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <!-- ADR-334: Wallet credit as payment entry -->
                  <tr v-if="invoice.wallet_credit_applied > 0">
                    <td>{{ fmt(invoice.wallet_credit_applied) }}</td>
                    <td>
                      <VChip
                        color="success"
                        size="small"
                      >
                        {{ paymentStatusLabel('succeeded') }}
                      </VChip>
                    </td>
                    <td>{{ t('companyBilling.invoiceDetail.walletCredit') }}</td>
                    <td>{{ invoice.paid_at ? formatDateTime(invoice.paid_at) : formatDateTime(invoice.finalized_at) }}</td>
                  </tr>
                  <!-- ADR-335: Wallet credit FIFO breakdown -->
                  <tr
                    v-for="(src, idx) in (invoice.wallet_credit_sources || [])"
                    :key="`wallet-src-${idx}`"
                  >
                    <td class="ps-6 text-body-2 text-disabled">
                      {{ fmt(src.amount) }}
                    </td>
                    <td class="text-body-2 text-disabled" />
                    <td class="text-body-2 text-disabled">
                      {{ src.description }}
                    </td>
                    <td class="text-body-2 text-disabled">
                      {{ src.created_at ? formatDateTime(src.created_at) : '' }}
                    </td>
                  </tr>
                  <tr
                    v-for="p in (invoice.payments || [])"
                    :key="p.id"
                  >
                    <td>{{ fmt(p.amount, p.currency) }}</td>
                    <td>
                      <VChip
                        :color="p.status === 'succeeded' ? 'success' : p.status === 'failed' ? 'error' : 'warning'"
                        size="small"
                      >
                        {{ paymentStatusLabel(p.status) }}
                      </VChip>
                    </td>
                    <td class="text-capitalize">
                      {{ p.provider }}
                    </td>
                    <td>{{ formatDateTime(p.created_at) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>

          <!-- Credit Notes -->
          <VCard>
            <VCardTitle>
              <VIcon
                icon="tabler-credit-card-refund"
                class="me-2"
              />
              {{ t('companyBilling.invoiceDetail.creditNotes') }}
            </VCardTitle>
            <VCardText>
              <div
                v-if="!invoice.credit_notes?.length"
                class="text-center text-disabled"
              >
                {{ t('companyBilling.invoiceDetail.noCreditNotes') }}
              </div>
              <VTable
                v-else
                density="compact"
              >
                <thead>
                  <tr>
                    <th>{{ t('companyBilling.invoiceDetail.cnNumber') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.cnAmount') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.cnStatus') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.cnReason') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.cnIssuedAt') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="cn in (invoice.credit_notes || [])"
                    :key="cn.id"
                  >
                    <td class="font-weight-medium">
                      {{ cn.number }}
                    </td>
                    <td>{{ fmt(cn.amount) }}</td>
                    <td>
                      <VChip
                        :color="cn.status === 'applied' ? 'success' : 'warning'"
                        size="small"
                      >
                        {{ cn.status }}
                      </VChip>
                    </td>
                    <td>{{ cn.reason || '—' }}</td>
                    <td>{{ formatDate(cn.issued_at) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>

          <!-- Annexe reference -->
          <VAlert
            v-if="invoice.is_annexe && invoice.parent_number"
            type="info"
            variant="tonal"
            class="mt-4"
          >
            {{ t('companyBilling.invoiceDetail.annexeOf') }} :
            <router-link :to="{ name: '/company/billing/invoices/[id]', params: { id: invoice.parent_invoice_id } }">
              {{ invoice.parent_number }}
            </router-link>
          </VAlert>

          <!-- Annexes list -->
          <VCard
            v-if="invoice.annexes?.length"
            class="mt-4"
          >
            <VCardTitle>
              <VIcon
                icon="tabler-file-plus"
                class="me-2"
              />
              {{ t('companyBilling.invoiceDetail.annexes') }}
            </VCardTitle>
            <VCardText>
              <VTable density="compact">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>{{ t('companyBilling.invoiceDetail.annexeAmount') }}</th>
                    <th>{{ t('companyBilling.invoiceDetail.annexeStatus') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="annexe in invoice.annexes"
                    :key="annexe.id"
                  >
                    <td>
                      <router-link :to="{ name: '/company/billing/invoices/[id]', params: { id: annexe.id } }">
                        {{ annexe.display_number }}
                      </router-link>
                    </td>
                    <td>{{ fmt(annexe.amount) }}</td>
                    <td>
                      <VChip
                        :color="annexe.status === 'paid' ? 'success' : 'warning'"
                        size="small"
                      >
                        {{ annexe.status }}
                      </VChip>
                    </td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>
        </VCol>

        <!-- Sidebar (3 cols) -->
        <VCol
          cols="12"
          md="3"
          class="d-print-none"
        >
          <VCard>
            <VCardText>
              <!-- Pay button — open or overdue with amount_due > 0 -->
              <VBtn
                v-if="canPay"
                v-can="'billing.manage'"
                block
                color="primary"
                prepend-icon="tabler-credit-card"
                class="mb-4"
                @click="payInvoice"
              >
                {{ t('companyBilling.invoiceDetail.payInvoice') }}
              </VBtn>

              <VBtn
                block
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-arrow-left"
                class="mb-4"
                @click="router.push({ name: 'company-billing-tab', params: { tab: 'invoices' } })"
              >
                {{ t('companyBilling.invoiceDetail.backToBilling') }}
              </VBtn>

              <VBtn
                block
                color="secondary"
                variant="tonal"
                prepend-icon="tabler-download"
                class="mb-4"
                @click="downloadPdf"
              >
                {{ t('companyBilling.invoiceDetail.download') }}
              </VBtn>

              <VBtn
                block
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-printer"
                @click="printInvoice"
              >
                {{ t('companyBilling.invoiceDetail.print') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style lang="scss">
.invoice-preview-table {
  --v-table-header-color: var(--v-theme-surface);

  &.v-table .v-table__wrapper table thead tr th {
    border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)) !important;
  }
}

@media print {
  .v-theme--dark {
    --v-theme-surface: 255, 255, 255;
    --v-theme-on-surface: 47, 43, 61;
    --v-theme-on-background: 47, 43, 61;
  }

  body {
    background: none !important;
  }

  .invoice-header-preview,
  .invoice-preview-wrapper {
    padding: 0 !important;
  }

  .v-navigation-drawer,
  .layout-vertical-nav,
  .app-customizer-toggler,
  .layout-footer,
  .layout-navbar,
  .layout-navbar-and-nav-container {
    display: none;
  }

  .v-card {
    box-shadow: none !important;

    .print-row {
      flex-direction: row !important;
    }
  }

  .layout-content-wrapper {
    padding-inline-start: 0 !important;
  }

  .v-table__wrapper {
    overflow: hidden !important;
  }

  .d-print-none {
    display: none !important;
  }
}
</style>
