<script setup>
import BrandLogo from '@/components/BrandLogo.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.billing', permission: 'view_billing' } })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)
const error = ref(false)

const invoice = computed(() => store.invoiceDetail)
const snap = computed(() => invoice.value?.billing_snapshot || {})
const marketLocale = computed(() => snap.value?.market_locale || 'fr-FR')

const load = async () => {
  isLoading.value = true
  error.value = false
  try {
    await store.fetchInvoiceDetail(route.params.id)
    if (!store.invoiceDetail) {
      error.value = true
    }
  }
  catch {
    error.value = true
  }
  finally {
    isLoading.value = false
  }
}

onMounted(load)

// ── Helpers ──
const isPastDue = computed(() => {
  if (!invoice.value?.due_at) return false

  return new Date(invoice.value.due_at) < new Date()
})

const statusColor = computed(() => {
  const status = invoice.value?.status
  if (status === 'open' && isPastDue.value) return 'error'

  const colors = { draft: 'secondary', open: 'warning', overdue: 'error', paid: 'success', voided: 'secondary', uncollectible: 'error' }

  return colors[status] || 'secondary'
})

const statusLabel = computed(() => {
  const status = invoice.value?.status
  if (status === 'open' && isPastDue.value) return t('platformBilling.invoiceDetail.statusPastDue')
  if (status === 'open') return t('platformBilling.invoiceDetail.statusOpen')

  const key = `platformBilling.invoiceDetail.status${status?.charAt(0).toUpperCase()}${status?.slice(1)}`

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

  return key ? t(`platformBilling.invoiceDetail.${key}`) : type
}

const paymentStatusLabel = status => {
  const map = { succeeded: 'paymentSucceeded', failed: 'paymentFailed', pending: 'paymentPending' }
  const key = map[status]

  return key ? t(`platformBilling.invoiceDetail.${key}`) : status
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(marketLocale.value, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

const formatDateTime = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(marketLocale.value, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

const taxPercent = computed(() => {
  if (!invoice.value?.tax_rate_bps) return 0

  return (invoice.value.tax_rate_bps / 100).toFixed(2)
})

const fmt = (amount, currency) => formatMoney(amount, { currency: currency || invoice.value?.currency })

const printInvoice = () => {
  window.print()
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
    <VAlert
      v-else-if="error"
      type="error"
      variant="tonal"
      class="mb-4"
    >
      <VAlertTitle>{{ t('platformBilling.invoiceDetail.notFound') }}</VAlertTitle>
      <p class="mb-4">
        {{ t('platformBilling.invoiceDetail.notFoundDesc') }}
      </p>
      <VBtn
        variant="tonal"
        color="primary"
        @click="router.push('/platform/billing')"
      >
        {{ t('platformBilling.invoiceDetail.backToList') }}
      </VBtn>
    </VAlert>

    <!-- Content -->
    <template v-else-if="invoice">
      <VRow>
        <!-- Main Content (9 cols) -->
        <VCol
          cols="12"
          md="9"
        >
          <VCard class="invoice-preview-wrapper pa-4 pa-sm-8">
            <!-- Header -->
            <div class="invoice-header-preview d-flex flex-wrap justify-space-between flex-column flex-sm-row print-row gap-4 mb-4">
              <!-- Left: Logo + Company -->
              <div>
                <BrandLogo size="lg" />
                <div
                  v-if="invoice.company"
                  class="mt-2"
                >
                  <span class="text-body-1 font-weight-medium">{{ invoice.company.name }}</span>
                  <span class="text-disabled ms-2">{{ invoice.company.slug }}</span>
                </div>
              </div>

              <!-- Right: Invoice meta -->
              <div class="text-sm-end">
                <h6 class="font-weight-medium text-lg mb-2">
                  {{ invoice.number || `#${invoice.id}` }}
                </h6>
                <VChip
                  :color="statusColor"
                  size="small"
                  class="mb-2"
                >
                  {{ statusLabel }}
                </VChip>
                <div class="text-body-2">
                  <div>{{ t('platformBilling.invoiceDetail.issuedAt') }} : {{ formatDate(invoice.issued_at) }}</div>
                  <div>{{ t('platformBilling.invoiceDetail.dueAt') }} : {{ formatDate(invoice.due_at) }}</div>
                  <div v-if="invoice.paid_at">
                    {{ t('platformBilling.invoiceDetail.paidAt') }} : {{ formatDate(invoice.paid_at) }}
                  </div>
                  <div v-if="invoice.voided_at">
                    {{ t('platformBilling.invoiceDetail.voidedAt') }} : {{ formatDate(invoice.voided_at) }}
                  </div>
                </div>
              </div>
            </div>

            <VDivider class="mb-4" />

            <!-- Invoice To + Billing Details -->
            <VRow class="print-row mb-4">
              <VCol class="text-no-wrap">
                <h6 class="text-h6 mb-2">
                  {{ t('platformBilling.invoiceDetail.invoiceTo') }}
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
                  {{ t('platformBilling.invoiceDetail.vatNumber') }} : {{ snap.vat_number }}
                </p>
                <p
                  v-if="snap.siret"
                  class="mb-0 text-body-2"
                >
                  {{ t('platformBilling.invoiceDetail.siret') }} : {{ snap.siret }}
                </p>
              </VCol>

              <VCol class="text-no-wrap">
                <h6 class="text-h6 mb-2">
                  {{ t('platformBilling.invoiceDetail.billingDetails') }}
                </h6>
                <table class="text-body-2">
                  <tbody>
                    <tr v-if="invoice.period_start && invoice.period_end">
                      <td class="pe-4 text-disabled">
                        {{ t('platformBilling.invoiceDetail.period') }}
                      </td>
                      <td>{{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }}</td>
                    </tr>
                    <tr>
                      <td class="pe-4 text-disabled">
                        {{ t('platformBilling.invoiceDetail.dueAt') }}
                      </td>
                      <td>{{ formatDate(invoice.due_at) }}</td>
                    </tr>
                    <tr v-if="snap.market_name">
                      <td class="pe-4 text-disabled">
                        {{ t('platformBilling.invoiceDetail.market') }}
                      </td>
                      <td>{{ snap.market_name }}</td>
                    </tr>
                    <tr v-if="snap.legal_status_name">
                      <td class="pe-4 text-disabled">
                        {{ t('platformBilling.invoiceDetail.legalStatus') }}
                      </td>
                      <td>{{ snap.legal_status_name }}</td>
                    </tr>
                  </tbody>
                </table>
              </VCol>
            </VRow>

            <!-- Line Items Table -->
            <VTable class="invoice-preview-table border text-high-emphasis overflow-hidden mb-4">
              <thead>
                <tr>
                  <th scope="col">
                    {{ t('platformBilling.invoiceDetail.lineDescription') }}
                  </th>
                  <th scope="col">
                    {{ t('platformBilling.invoiceDetail.lineType') }}
                  </th>
                  <th
                    scope="col"
                    class="text-center"
                  >
                    {{ t('platformBilling.invoiceDetail.lineQty') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('platformBilling.invoiceDetail.lineUnitPrice') }}
                  </th>
                  <th
                    scope="col"
                    class="text-end"
                  >
                    {{ t('platformBilling.invoiceDetail.lineTotal') }}
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

            <!-- Totals + Note -->
            <div class="d-flex justify-space-between flex-column flex-sm-row print-row">
              <div class="mb-2">
                <template v-if="invoice.notes">
                  <h6 class="text-h6 mb-1">
                    {{ t('platformBilling.invoiceDetail.notes') }} :
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
                        {{ t('platformBilling.invoiceDetail.subtotal') }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.subtotal) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.tax_amount">
                      <td class="pe-16 text-body-2">
                        {{ t('platformBilling.invoiceDetail.tax', { rate: taxPercent }) }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.tax_amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.wallet_credit_applied">
                      <td class="pe-16 text-body-2">
                        {{ t('platformBilling.invoiceDetail.walletCredit') }}
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
                        {{ t('platformBilling.invoiceDetail.total') }}
                      </td>
                      <td class="text-end font-weight-bold">
                        {{ fmt(invoice.amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.amount_due !== invoice.amount">
                      <td class="pe-16 text-body-2">
                        {{ t('platformBilling.invoiceDetail.amountDue') }}
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

            <!-- Dunning info -->
            <template v-if="invoice.retry_count > 0">
              <VDivider class="my-4 border-dashed" />
              <div class="d-flex gap-4">
                <VChip
                  color="warning"
                  size="small"
                >
                  {{ t('platformBilling.invoiceDetail.retryCount') }} : {{ invoice.retry_count }}
                </VChip>
                <VChip
                  v-if="invoice.next_retry_at"
                  color="info"
                  size="small"
                >
                  {{ t('platformBilling.invoiceDetail.nextRetry') }} : {{ formatDateTime(invoice.next_retry_at) }}
                </VChip>
              </div>
            </template>
          </VCard>

          <!-- Payments -->
          <VCard class="mt-4 mb-4">
            <VCardTitle>
              <VIcon
                icon="tabler-cash"
                class="me-2"
              />
              {{ t('platformBilling.invoiceDetail.payments') }}
            </VCardTitle>
            <VCardText class="pa-0">
              <div
                v-if="!invoice.payments?.length"
                class="text-center pa-4 text-disabled"
              >
                {{ t('platformBilling.invoiceDetail.noPayments') }}
              </div>
              <VTable
                v-else
                density="compact"
              >
                <thead>
                  <tr>
                    <th>{{ t('platformBilling.invoiceDetail.paymentAmount') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.paymentStatus') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.paymentProvider') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.paymentProviderId') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.paymentDate') }}</th>
                  </tr>
                </thead>
                <tbody>
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
                    <td>
                      <code
                        v-if="p.provider_payment_id"
                        class="text-caption"
                      >{{ p.provider_payment_id }}</code>
                      <span
                        v-else
                        class="text-disabled"
                      >—</span>
                    </td>
                    <td>{{ formatDateTime(p.created_at) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>

          <!-- Credit Notes -->
          <VCard class="mb-4">
            <VCardTitle>
              <VIcon
                icon="tabler-credit-card-refund"
                class="me-2"
              />
              {{ t('platformBilling.invoiceDetail.creditNotes') }}
            </VCardTitle>
            <VCardText class="pa-0">
              <div
                v-if="!invoice.credit_notes?.length"
                class="text-center pa-4 text-disabled"
              >
                {{ t('platformBilling.invoiceDetail.noCreditNotes') }}
              </div>
              <VTable
                v-else
                density="compact"
              >
                <thead>
                  <tr>
                    <th>{{ t('platformBilling.invoiceDetail.cnNumber') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.cnAmount') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.cnStatus') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.cnReason') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.cnIssuedAt') }}</th>
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

          <!-- Ledger Entries -->
          <VCard>
            <VCardTitle>
              <VIcon
                icon="tabler-receipt"
                class="me-2"
              />
              {{ t('platformBilling.invoiceDetail.ledgerEntries') }}
            </VCardTitle>
            <VCardText class="pa-0">
              <div
                v-if="!invoice.ledger_entries?.length"
                class="text-center pa-4 text-disabled"
              >
                {{ t('platformBilling.invoiceDetail.noLedgerEntries') }}
              </div>
              <VTable
                v-else
                density="compact"
              >
                <thead>
                  <tr>
                    <th>{{ t('platformBilling.invoiceDetail.ledgerType') }}</th>
                    <th>{{ t('platformBilling.invoiceDetail.ledgerAccount') }}</th>
                    <th class="text-end">
                      {{ t('platformBilling.invoiceDetail.ledgerDebit') }}
                    </th>
                    <th class="text-end">
                      {{ t('platformBilling.invoiceDetail.ledgerCredit') }}
                    </th>
                    <th>{{ t('platformBilling.invoiceDetail.ledgerDate') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="e in (invoice.ledger_entries || [])"
                    :key="e.id"
                  >
                    <td>
                      <VChip
                        size="x-small"
                        color="secondary"
                      >
                        {{ e.entry_type }}
                      </VChip>
                    </td>
                    <td>
                      <code class="text-caption">{{ e.account_code }}</code>
                    </td>
                    <td class="text-end">
                      {{ Number(e.debit) > 0 ? Number(e.debit).toFixed(2) : '—' }}
                    </td>
                    <td class="text-end">
                      {{ Number(e.credit) > 0 ? Number(e.credit).toFixed(2) : '—' }}
                    </td>
                    <td>{{ formatDateTime(e.recorded_at) }}</td>
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
              <VBtn
                block
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-arrow-left"
                class="mb-4"
                @click="router.push('/platform/billing')"
              >
                {{ t('platformBilling.invoiceDetail.backToList') }}
              </VBtn>

              <VBtn
                block
                variant="tonal"
                color="secondary"
                prepend-icon="tabler-printer"
                @click="printInvoice"
              >
                {{ t('platformBilling.invoiceDetail.print') }}
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
