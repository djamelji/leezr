<script setup>
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
  const colors = { draft: 'secondary', open: 'info', overdue: 'error', paid: 'success', voided: 'warning', uncollectible: 'error' }

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
      class="mb-6"
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
                <div
                  v-if="invoice.company"
                  class="text-body-1"
                >
                  <h6 class="text-h6 font-weight-regular">
                    {{ invoice.company.name }}
                  </h6>
                  <span class="text-disabled">{{ invoice.company.slug }}</span>
                </div>
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
                  <div>{{ t('platformBilling.invoiceDetail.issuedAt') }}: {{ formatDate(invoice.issued_at) }}</div>
                  <div>{{ t('platformBilling.invoiceDetail.dueAt') }}: {{ formatDate(invoice.due_at) }}</div>
                  <div v-if="invoice.paid_at">
                    {{ t('platformBilling.invoiceDetail.paidAt') }}: {{ formatDate(invoice.paid_at) }}
                  </div>
                  <div v-if="invoice.voided_at">
                    {{ t('platformBilling.invoiceDetail.voidedAt') }}: {{ formatDate(invoice.voided_at) }}
                  </div>
                </div>
              </div>
            </div>

            <!-- Period -->
            <div
              v-if="invoice.period_start && invoice.period_end"
              class="mb-6"
            >
              <span class="text-body-2 text-disabled">{{ t('platformBilling.invoiceDetail.period') }}:</span>
              <span class="ms-2">{{ formatDate(invoice.period_start) }} – {{ formatDate(invoice.period_end) }}</span>
            </div>

            <!-- Line Items -->
            <h6 class="text-h6 mb-4">
              {{ t('platformBilling.invoiceDetail.lineItems') }}
            </h6>
            <VTable
              class="border text-high-emphasis overflow-hidden mb-6"
              density="compact"
            >
              <thead>
                <tr>
                  <th>{{ t('platformBilling.invoiceDetail.lineDescription') }}</th>
                  <th>{{ t('platformBilling.invoiceDetail.lineType') }}</th>
                  <th class="text-center">
                    {{ t('platformBilling.invoiceDetail.lineQty') }}
                  </th>
                  <th class="text-end">
                    {{ t('platformBilling.invoiceDetail.lineUnitPrice') }}
                  </th>
                  <th class="text-end">
                    {{ t('platformBilling.invoiceDetail.lineTotal') }}
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
                        {{ t('platformBilling.invoiceDetail.subtotal') }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.subtotal) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.tax_amount">
                      <td class="pe-8 text-body-2">
                        {{ t('platformBilling.invoiceDetail.tax', { rate: taxPercent }) }}
                      </td>
                      <td class="text-end font-weight-medium">
                        {{ fmt(invoice.tax_amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.wallet_credit_applied">
                      <td class="pe-8 text-body-2">
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
                      <td class="pe-8 text-body-1 font-weight-medium">
                        {{ t('platformBilling.invoiceDetail.total') }}
                      </td>
                      <td class="text-end text-body-1 font-weight-bold">
                        {{ fmt(invoice.amount) }}
                      </td>
                    </tr>
                    <tr v-if="invoice.amount_due !== invoice.amount">
                      <td class="pe-8 text-body-2">
                        {{ t('platformBilling.invoiceDetail.amountDue') }}
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
                <span class="text-high-emphasis font-weight-medium me-1">{{ t('platformBilling.invoiceDetail.notes') }}:</span>
                <span>{{ invoice.notes }}</span>
              </p>
            </template>

            <!-- Dunning info -->
            <template v-if="invoice.retry_count > 0">
              <VDivider class="my-6 border-dashed" />
              <div class="d-flex gap-4">
                <VChip
                  color="warning"
                  size="small"
                >
                  {{ t('platformBilling.invoiceDetail.retryCount') }}: {{ invoice.retry_count }}
                </VChip>
                <VChip
                  v-if="invoice.next_retry_at"
                  color="info"
                  size="small"
                >
                  {{ t('platformBilling.invoiceDetail.nextRetry') }}: {{ formatDateTime(invoice.next_retry_at) }}
                </VChip>
              </div>
            </template>
          </VCard>

          <!-- ═══ Payments ═══ -->
          <VCard class="mb-6">
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
                class="text-center pa-6 text-disabled"
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
                        {{ p.status }}
                      </VChip>
                    </td>
                    <td>{{ p.provider }}</td>
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

          <!-- ═══ Credit Notes ═══ -->
          <VCard class="mb-6">
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
                class="text-center pa-6 text-disabled"
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

          <!-- ═══ Ledger Entries ═══ -->
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
                class="text-center pa-6 text-disabled"
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
