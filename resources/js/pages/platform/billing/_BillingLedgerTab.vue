<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatDate } from '@/utils/datetime'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

// ── Company selector ───────────────────────────────────
const companyId = ref('')

// ── Trial Balance ──────────────────────────────────────
const balanceLoading = ref(false)

const loadBalance = async () => {
  if (!companyId.value) return
  balanceLoading.value = true
  try {
    await store.fetchTrialBalance(companyId.value)
  }
  finally {
    balanceLoading.value = false
  }
}

const accountLabels = {
  AR: 'Accounts Receivable',
  CASH: 'Cash',
  REVENUE: 'Revenue',
  REFUND: 'Refunds',
  BAD_DEBT: 'Bad Debt',
}

const balanceAccounts = computed(() => {
  const b = store.trialBalance
  if (!b || !b.accounts) return []

  return b.accounts.map(a => ({
    ...a,
    label: accountLabels[a.account_code] || a.account_code,
  }))
})

const balanceHeaders = [
  { title: t('platformBilling.ledger.accountCode'), key: 'account_code', sortable: true },
  { title: t('platformBilling.ledger.accountLabel'), key: 'label', sortable: false },
  { title: t('platformBilling.ledger.debit'), key: 'total_debit', sortable: true, align: 'end' },
  { title: t('platformBilling.ledger.credit'), key: 'total_credit', sortable: true, align: 'end' },
  { title: t('platformBilling.ledger.balance'), key: 'balance', sortable: true, align: 'end' },
]

// ── Ledger entries ─────────────────────────────────────
const correlationFilter = ref('')
const entryTypeFilter = ref('')
const ledgerLoading = ref(false)

const entryTypes = [
  { title: t('platformBilling.ledger.allTypes'), value: '' },
  { title: t('platformBilling.ledger.typeInvoiceIssued'), value: 'invoice_issued' },
  { title: t('platformBilling.ledger.typePaymentReceived'), value: 'payment_received' },
  { title: t('platformBilling.ledger.typeRefundIssued'), value: 'refund_issued' },
  { title: t('platformBilling.ledger.typeWriteoff'), value: 'writeoff' },
  { title: t('platformBilling.ledger.typeAdjustment'), value: 'adjustment' },
]

const loadLedger = async (page = 1) => {
  if (!companyId.value) return
  ledgerLoading.value = true
  try {
    await store.fetchLedgerEntries({
      company_id: companyId.value,
      correlation_id: correlationFilter.value || undefined,
      entry_type: entryTypeFilter.value || undefined,
      page,
    })
  }
  finally {
    ledgerLoading.value = false
  }
}

const ledgerHeaders = computed(() => [
  { title: t('platformBilling.ledger.recordedAt'), key: 'recorded_at', width: '160px' },
  { title: t('platformBilling.ledger.entryType'), key: 'entry_type', width: '120px' },
  { title: t('platformBilling.ledger.accountCode'), key: 'account_code', width: '130px' },
  { title: t('platformBilling.ledger.debit'), key: 'debit', align: 'end', width: '120px' },
  { title: t('platformBilling.ledger.credit'), key: 'credit', align: 'end', width: '120px' },
  { title: t('platformBilling.ledger.currency'), key: 'currency', width: '80px' },
  { title: t('platformBilling.ledger.referenceType'), key: 'reference_type', width: '120px' },
  { title: t('platformBilling.ledger.referenceId'), key: 'reference_id', width: '100px' },
  { title: t('platformBilling.ledger.correlationId'), key: 'correlation_id', width: '140px' },
  { title: '', key: 'data-table-expand', width: '50px' },
])

const expandedRows = ref([])

const onLedgerPageChange = page => {
  loadLedger(page)
}

// ── Load on company change ─────────────────────────────
watch(companyId, val => {
  if (val) {
    loadBalance()
    loadLedger()
  }
})

// ── Helpers ────────────────────────────────────────────

const formatAmount = (amount, currency) => {
  if (amount == null) return '—'
  const val = Number(amount)
  if (val === 0) return '—'

  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency: currency || 'EUR',
    minimumFractionDigits: 2,
  }).format(val / 100)
}

const entryTypeColor = type => {
  const map = {
    invoice_issued: 'primary',
    payment_received: 'success',
    refund_issued: 'warning',
    writeoff: 'error',
    adjustment: 'info',
  }

  return map[type] || 'default'
}
</script>

<template>
  <div>
    <!-- Company selector -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="4"
          >
            <AppTextField
              v-model="companyId"
              :label="t('platformBilling.ledger.companyId')"
              type="number"
              :placeholder="t('platformBilling.ledger.companyIdPlaceholder')"
              density="compact"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- ═══ Trial Balance ═══ -->
    <VCard class="mb-6">
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-scale"
          class="me-2"
        />
        {{ t('platformBilling.ledger.trialBalance') }}
        <VSpacer />
        <VBtn
          v-if="companyId"
          size="small"
          variant="tonal"
          color="primary"
          :loading="balanceLoading"
          @click="loadBalance"
        >
          <VIcon
            icon="tabler-refresh"
            class="me-1"
          />
          {{ t('platformBilling.ledger.refresh') }}
        </VBtn>
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.ledger.selectCompanyFirst') }}
        </div>
        <template v-else>
          <div
            v-if="store.trialBalance.currency"
            class="d-flex gap-4 mb-4"
          >
            <VChip
              size="small"
              color="info"
            >
              {{ store.trialBalance.currency }}
            </VChip>
          </div>
          <VDataTable
            :headers="balanceHeaders"
            :items="balanceAccounts"
            :loading="balanceLoading"
            density="compact"
            :items-per-page="-1"
            hide-default-footer
          >
            <template #item.total_debit="{ item }">
              <span class="text-success font-weight-medium">
                {{ formatAmount(item.total_debit, store.trialBalance.currency) }}
              </span>
            </template>
            <template #item.total_credit="{ item }">
              <span class="text-error font-weight-medium">
                {{ formatAmount(item.total_credit, store.trialBalance.currency) }}
              </span>
            </template>
            <template #item.balance="{ item }">
              <span class="font-weight-bold">
                {{ formatAmount(item.balance, store.trialBalance.currency) }}
              </span>
            </template>
            <template #bottom>
              <tr
                v-if="balanceAccounts.length"
                class="v-data-table__tr"
              >
                <td
                  colspan="2"
                  class="font-weight-bold"
                >
                  {{ t('platformBilling.ledger.totals') }}
                </td>
                <td class="text-end font-weight-bold text-success">
                  {{ formatAmount(store.trialBalance.total_debit, store.trialBalance.currency) }}
                </td>
                <td class="text-end font-weight-bold text-error">
                  {{ formatAmount(store.trialBalance.total_credit, store.trialBalance.currency) }}
                </td>
                <td class="text-end font-weight-bold">
                  {{ formatAmount(store.trialBalance.net_balance, store.trialBalance.currency) }}
                </td>
              </tr>
            </template>
          </VDataTable>
        </template>
      </VCardText>
    </VCard>

    <!-- ═══ Ledger Explorer ═══ -->
    <VCard>
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-book"
          class="me-2"
        />
        {{ t('platformBilling.ledger.ledgerExplorer') }}
      </VCardTitle>
      <VCardText>
        <div
          v-if="!companyId"
          class="text-center pa-4 text-disabled"
        >
          {{ t('platformBilling.ledger.selectCompanyFirst') }}
        </div>
        <template v-else>
          <!-- Filters -->
          <VRow class="mb-4">
            <VCol
              cols="12"
              md="4"
            >
              <AppTextField
                v-model="correlationFilter"
                :label="t('platformBilling.ledger.correlationId')"
                :placeholder="t('platformBilling.ledger.filterByCorrelation')"
                density="compact"
                clearable
                @update:model-value="loadLedger(1)"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
            >
              <AppSelect
                v-model="entryTypeFilter"
                :label="t('platformBilling.ledger.entryType')"
                :items="entryTypes"
                density="compact"
                clearable
                @update:model-value="loadLedger(1)"
              />
            </VCol>
            <VCol
              cols="12"
              md="4"
              class="d-flex align-end"
            >
              <VBtn
                variant="tonal"
                color="primary"
                :loading="ledgerLoading"
                @click="loadLedger(1)"
              >
                <VIcon
                  icon="tabler-search"
                  class="me-1"
                />
                {{ t('platformBilling.ledger.search') }}
              </VBtn>
            </VCol>
          </VRow>

          <!-- Results -->
          <VDataTable
            v-model:expanded="expandedRows"
            :headers="ledgerHeaders"
            :items="store.ledgerEntries"
            :loading="ledgerLoading"
            density="compact"
            :items-per-page="store.ledgerPagination.per_page"
            show-expand
            item-value="id"
          >
            <template #item.recorded_at="{ item }">
              {{ formatDate(item.recorded_at) }}
            </template>
            <template #item.entry_type="{ item }">
              <VChip
                :color="entryTypeColor(item.entry_type)"
                size="small"
              >
                {{ item.entry_type }}
              </VChip>
            </template>
            <template #item.debit="{ item }">
              <span
                v-if="item.debit > 0"
                class="text-success font-weight-medium"
              >
                {{ formatAmount(item.debit, item.currency) }}
              </span>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </template>
            <template #item.credit="{ item }">
              <span
                v-if="item.credit > 0"
                class="text-error font-weight-medium"
              >
                {{ formatAmount(item.credit, item.currency) }}
              </span>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </template>
            <template #item.correlation_id="{ item }">
              <code
                v-if="item.correlation_id"
                class="text-caption"
              >{{ item.correlation_id.slice(0, 12) }}…</code>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </template>
            <template #expanded-row="{ columns, item }">
              <tr>
                <td :colspan="columns.length">
                  <div class="pa-4">
                    <h6 class="text-subtitle-2 mb-2">
                      {{ t('platformBilling.ledger.metadata') }}
                    </h6>
                    <pre class="text-caption bg-surface pa-3 rounded" style="max-height: 200px; overflow: auto;">{{ JSON.stringify(item.metadata || {}, null, 2) }}</pre>
                    <div
                      v-if="item.correlation_id"
                      class="mt-2"
                    >
                      <span class="text-subtitle-2">{{ t('platformBilling.ledger.fullCorrelationId') }}:</span>
                      <code class="ms-2 text-caption">{{ item.correlation_id }}</code>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <template #bottom>
              <VDivider />
              <div class="d-flex align-center justify-space-between pa-4">
                <span class="text-body-2 text-disabled">
                  {{ t('platformBilling.ledger.totalEntries', { total: store.ledgerPagination.total }) }}
                </span>
                <VPagination
                  v-if="store.ledgerPagination.last_page > 1"
                  :model-value="store.ledgerPagination.current_page"
                  :length="store.ledgerPagination.last_page"
                  :total-visible="5"
                  density="compact"
                  @update:model-value="onLedgerPageChange"
                />
              </div>
            </template>
          </VDataTable>
        </template>
      </VCardText>
    </VCard>
  </div>
</template>
