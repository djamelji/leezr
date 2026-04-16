<script setup>
import { formatDate } from '@/utils/datetime'
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { $api } from '@/utils/api'
import { invoiceStatusColor } from '@/utils/billing'
import { formatMoney } from '@/utils/money'
import EmptyState from '@/core/components/EmptyState.vue'
import StatusChip from '@/core/components/StatusChip.vue'

const { t } = useI18n()
const router = useRouter()
const store = useCompanyBillingStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const statusFilter = ref('')

const headers = computed(() => [
  { title: t('companyBilling.invoiceNumber'), key: 'number' },
  { title: t('companyBilling.invoiceStatus'), key: 'status', width: '130px' },
  { title: t('companyBilling.invoicePeriod'), key: 'period', sortable: false },
  { title: t('companyBilling.invoiceAmount'), key: 'amount', align: 'end' },
  { title: t('companyBilling.invoiceAmountDue'), key: 'amount_due', align: 'end' },
  { title: t('companyBilling.invoiceIssuedAt'), key: 'issued_at' },
  { title: t('companyBilling.invoiceActions'), key: 'actions', align: 'center', width: '120px', sortable: false },
])

const statusOptions = computed(() => [
  { title: t('companyBilling.filterAll'), value: '' },
  { title: t('companyBilling.invoiceStatusOpen'), value: 'open' },
  { title: t('companyBilling.invoiceStatusOverdue'), value: 'overdue' },
  { title: t('companyBilling.invoiceStatusPaid'), value: 'paid' },
  { title: t('companyBilling.invoiceStatusVoided'), value: 'voided' },
])

const statusLabel = status => {
  const labels = {
    draft: t('companyBilling.invoiceStatusDraft'),
    open: t('companyBilling.invoiceStatusOpen'),
    overdue: t('companyBilling.invoiceStatusOverdue'),
    paid: t('companyBilling.invoiceStatusPaid'),
    voided: t('companyBilling.invoiceStatusVoided'),
  }

  return labels[status] || status
}

const formatPeriod = item => {
  if (!item.period_start || !item.period_end) return '—'

  return `${formatDate(item.period_start)} – ${formatDate(item.period_end)}`
}

const loadInvoices = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchInvoices({
      page,
      status: statusFilter.value || undefined,
    })
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const onPageChange = page => {
  loadInvoices(page)
}

// ADR-334/336: Redirect to pay page with pre-selected invoice via store (no query param)
const goToPay = invoice => {
  store.setPreSelectedInvoices([invoice.id])
  router.push('/company/billing/pay')
}

const downloadPdf = async invoice => {
  try {
    const blob = await $api(`/billing/invoices/${invoice.id}/pdf`, {
      responseType: 'blob',
    })

    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `${invoice.number || `invoice-${invoice.id}`}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch {
    // Error handled by $api onResponseError
  }
}

onMounted(() => loadInvoices())
watch(statusFilter, () => loadInvoices(1))
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-file-invoice"
        class="me-2"
      />
      {{ t('companyBilling.invoices') }}
      <VSpacer />
      <AppSelect
        v-model="statusFilter"
        :items="statusOptions"
        density="compact"
        style="max-inline-size: 160px;"
      />
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.invoices.length === 0"
        type="table"
      />

      <EmptyState
        v-else-if="store.invoices.length === 0 && !isLoading"
        icon="tabler-file-invoice"
        :title="t('companyBilling.noInvoices')"
        :description="t('companyBilling.noInvoicesDescription')"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="store.invoices"
        :loading="isLoading"
        :items-per-page="store.invoicePagination.per_page"
        hide-default-footer
      >
        <template #item.number="{ item }">
          <RouterLink
            :to="`/company/billing/invoices/${item.id}`"
            class="text-body-1 font-weight-medium text-primary text-decoration-none"
          >
            {{ item.display_number || item.number }}
          </RouterLink>
        </template>

        <template #item.status="{ item }">
          <StatusChip
            :status="item.status"
            domain="invoice"
          >
            {{ statusLabel(item.status) }}
          </StatusChip>
        </template>

        <template #item.period="{ item }">
          {{ formatPeriod(item) }}
        </template>

        <template #item.amount="{ item }">
          {{ formatMoney(item.amount, { currency: item.currency }) }}
        </template>

        <template #item.amount_due="{ item }">
          <span :class="item.amount_due > 0 ? 'text-error' : 'text-success'">
            {{ formatMoney(item.amount_due, { currency: item.currency }) }}
          </span>
        </template>

        <template #item.issued_at="{ item }">
          {{ formatDate(item.issued_at) }}
        </template>

        <template #item.actions="{ item }">
          <div class="d-flex gap-1 justify-center">
            <IconBtn
              size="small"
              :title="t('companyBilling.invoiceDownloadPdf')"
              @click="downloadPdf(item)"
            >
              <VIcon icon="tabler-download" />
            </IconBtn>
            <VBtn
              v-if="['open', 'overdue'].includes(item.status) && item.amount_due > 0"
              v-can="'billing.manage'"
              size="small"
              variant="tonal"
              color="warning"
              @click="goToPay(item)"
            >
              {{ t('companyBilling.retryPayment') }}
            </VBtn>
          </div>
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('companyBilling.invoiceCount', { count: store.invoicePagination.total }) }}
            </span>
            <VPagination
              v-if="store.invoicePagination.last_page > 1"
              :model-value="store.invoicePagination.current_page"
              :length="store.invoicePagination.last_page"
              :total-visible="5"
              @update:model-value="onPageChange"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
