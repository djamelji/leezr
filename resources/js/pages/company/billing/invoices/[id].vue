<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { $api } from '@/utils/api'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useCompanyBillingStore()

const isLoading = ref(true)
const error = ref(false)

const invoice = computed(() => store.invoiceDetail)

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
const statusColor = status => {
  const colors = { draft: 'secondary', open: 'info', overdue: 'error', paid: 'success', voided: 'warning' }

  return colors[status] || 'secondary'
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

const formatDateTime = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
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

const downloadPdf = async () => {
  if (!invoice.value) return
  try {
    const blob = await $api(`/billing/invoices/${invoice.value.id}/pdf`, {
      responseType: 'blob',
    })

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `invoice-${invoice.value.number || invoice.value.id}.html`
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

// ── Retry payment ──
const { toast } = useAppToast()
const isRetrying = ref(false)

const retryPayment = async () => {
  isRetrying.value = true
  try {
    const data = await store.retryInvoice(invoice.value.id)

    toast(data?.message || t('companyBilling.invoiceDetail.retrySuccess'), data?.result === 'paid' ? 'success' : 'info')
    await store.fetchInvoiceDetail(route.params.id)
  }
  catch {
    toast(t('companyBilling.invoiceDetail.retryFailed'), 'error')
  }
  finally {
    isRetrying.value = false
  }
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
      class="mb-6"
    >
      <VAlertTitle>{{ t('companyBilling.invoiceDetail.notFound') }}</VAlertTitle>
      <p class="mb-4">
        {{ t('companyBilling.invoiceDetail.notFoundDesc') }}
      </p>
      <VBtn
        variant="tonal"
        color="primary"
        @click="router.push('/company/billing/invoices')"
      >
        {{ t('companyBilling.invoiceDetail.backToBilling') }}
      </VBtn>
    </VAlert>

    <!-- Content -->
    <template v-else-if="invoice">
      <VRow>
        <!-- ═══ Main Content (9 cols) ═══ -->
        <VCol
          cols="12"
          md="9"
        >
          <VCard class="pa-6 pa-sm-12 mb-6">
            <!-- Header -->
            <div class="d-flex flex-wrap justify-space-between flex-column flex-sm-row bg-var-theme-background gap-6 rounded pa-6 mb-6">
              <div>
                <h4 class="text-h4 mb-2">
                  {{ invoice.number || `#${invoice.id}` }}
                </h4>
              </div>
              <div class="text-end">
                <VChip
                  :color="statusColor(invoice.status)"
                  size="large"
                  class="mb-4"
                >
                  {{ invoice.status }}
                </VChip>
                <div class="text-body-2">
                  <div>{{ t('companyBilling.invoiceDetail.issuedAt') }}: {{ formatDate(invoice.issued_at) }}</div>
                  <div>{{ t('companyBilling.invoiceDetail.dueAt') }}: {{ formatDate(invoice.due_at) }}</div>
                  <div v-if="invoice.paid_at">
                    {{ t('companyBilling.invoiceDetail.paidAt') }}: {{ formatDate(invoice.paid_at) }}
                  </div>
                  <div v-if="invoice.voided_at">
                    {{ t('companyBilling.invoiceDetail.voidedAt') }}: {{ formatDate(invoice.voided_at) }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Period -->
            <div
              v-if="invoice.period_start && invoice.period_end"
              class="mb-6"
            >
              <span class="text-body-2 text-disabled">{{ t('companyBilling.invoiceDetail.period') }}:</span>
              <span class="ms-2">{{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }}</span>
            </div>

            <!-- Line Items -->
            <h6 class="text-h6 mb-4">
              {{ t('companyBilling.invoiceDetail.lineItems') }}
            </h6>
            <VTable
              class="border text-high-emphasis overflow-hidden mb-6"
              density="compact"
            >
              <thead>
                <tr>
                  <th>{{ t('companyBilling.invoiceDetail.lineDescription') }}</th>
                  <th>{{ t('companyBilling.invoiceDetail.lineType') }}</th>
                  <th class="text-center">
                    {{ t('companyBilling.invoiceDetail.lineQty') }}
                  </th>
                  <th class="text-end">
                    {{ t('companyBilling.invoiceDetail.lineUnitPrice') }}
                  </th>
                  <th class="text-end">
                    {{ t('companyBilling.invoiceDetail.lineTotal') }}
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="line in invoice.lines"
                  :key="line.id"
                >
                  <td>{{ line.description }}</td>
                  <td>
                    <VChip
                      size="x-small"
                      color="secondary"
                    >
                      {{ line.type }}
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

            <!-- Totals -->
            <div class="d-flex justify-end">
              <div style="min-inline-size: 280px;">
                <table class="w-100">
                  <tbody>
                    <tr>
                      <td class="pe-8 text-body-2">
                        {{ t('companyBilling.invoiceDetail.subtotal') }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.subtotal) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.tax_amount">
                      <td class="pe-8 text-body-2">
                        {{ t('companyBilling.invoiceDetail.tax', { rate: taxPercent }) }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.tax_amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.wallet_credit_applied">
                      <td class="pe-8 text-body-2">
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
                      <td class="pe-8 text-body-1 font-weight-medium">
                        {{ t('companyBilling.invoiceDetail.total') }}
                      </td>
                      <td class="text-end text-body-1 font-weight-bold">
                        {{ fmt(invoice.amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.amount_due !== invoice.amount">
                      <td class="pe-8 text-body-2">
                        {{ t('companyBilling.invoiceDetail.amountDue') }}
                      </td>
                      <td class="text-end font-weight-medium" :class="invoice.amount_due > 0 ? 'text-error' : 'text-success'">
                        {{ fmt(invoice.amount_due) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Notes -->
            <template v-if="invoice.notes">
              <VDivider class="my-6 border-dashed" />
              <p class="mb-0">
                <span class="text-high-emphasis font-weight-medium me-1">{{ t('companyBilling.invoiceDetail.notes') }}:</span>
                <span>{{ invoice.notes }}</span>
              </p>
            </template>
          </VCard>

          <!-- ═══ Payments ═══ -->
          <VCard class="mb-6">
            <VCardTitle>
              <VIcon
                icon="tabler-cash"
                class="me-2"
              />
              {{ t('companyBilling.invoiceDetail.payments') }}
            </VCardTitle>
            <VCardText class="pa-0">
              <div
                v-if="!invoice.payments?.length"
                class="text-center pa-6 text-disabled"
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
                        {{ p.status }}
                      </VChip>
                    </td>
                    <td>{{ p.provider }}</td>
                    <td>{{ formatDateTime(p.created_at) }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VCardText>
          </VCard>

          <!-- ═══ Credit Notes ═══ -->
          <VCard>
            <VCardTitle>
              <VIcon
                icon="tabler-credit-card-refund"
                class="me-2"
              />
              {{ t('companyBilling.invoiceDetail.creditNotes') }}
            </VCardTitle>
            <VCardText class="pa-0">
              <div
                v-if="!invoice.credit_notes?.length"
                class="text-center pa-6 text-disabled"
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
        </VCol>

        <!-- ═══ Sidebar (3 cols) ═══ -->
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
                @click="router.push('/company/billing/invoices')"
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

              <VBtn
                v-if="invoice.status === 'overdue' && invoice.amount_due > 0"
                block
                color="error"
                prepend-icon="tabler-credit-card"
                class="mt-4"
                :loading="isRetrying"
                @click="retryPayment"
              >
                {{ t('companyBilling.invoiceDetail.retryPayment') }}
              </VBtn>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style lang="scss">
@media print {
  .v-navigation-drawer,
  .layout-vertical-nav,
  .app-customizer-toggler,
  .layout-footer,
  .layout-navbar,
  .layout-navbar-and-nav-container {
    display: none;
  }

  .layout-content-wrapper {
    padding-inline-start: 0 !important;
  }

  .d-print-none {
    display: none !important;
  }
}
</style>
