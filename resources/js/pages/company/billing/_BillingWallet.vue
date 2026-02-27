<script setup>
import { useCompanyBillingStore } from '@/modules/company/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = useCompanyBillingStore()

const isLoading = ref(true)

onMounted(async () => {
  try {
    await store.fetchWallet()
  }
  finally {
    isLoading.value = false
  }
})

const headers = computed(() => [
  { title: t('companyBilling.walletDate'), key: 'created_at' },
  { title: t('companyBilling.walletType'), key: 'type', width: '120px' },
  { title: t('companyBilling.walletDescription'), key: 'description' },
  { title: t('companyBilling.walletAmount'), key: 'amount', align: 'end' },
  { title: t('companyBilling.walletBalanceAfter'), key: 'balance_after', align: 'end' },
])

const typeColor = type => {
  return type === 'credit' ? 'success' : 'error'
}

const typeLabel = type => {
  return type === 'credit'
    ? t('companyBilling.walletCredit')
    : t('companyBilling.walletDebit')
}

const formatDate = dateStr => {
  if (!dateStr) return '—'

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
</script>

<template>
  <div>
    <!-- Balance Card -->
    <VCard class="mb-6">
      <VCardText class="d-flex align-center gap-4 pa-6">
        <VAvatar
          size="48"
          variant="tonal"
          color="primary"
        >
          <VIcon
            icon="tabler-wallet"
            size="28"
          />
        </VAvatar>
        <div>
          <p class="text-body-2 text-disabled mb-0">
            {{ t('companyBilling.walletBalance') }}
          </p>
          <VSkeletonLoader
            v-if="isLoading"
            type="text"
            width="120"
          />
          <h4
            v-else
            class="text-h4"
          >
            {{ formatMoney(store.wallet.balance, { currency: store.wallet.currency }) }}
          </h4>
        </div>
      </VCardText>
    </VCard>

    <!-- Transactions -->
    <VCard>
      <VCardTitle>
        <VIcon
          icon="tabler-list"
          class="me-2"
        />
        {{ t('companyBilling.walletTransactions') }}
      </VCardTitle>

      <VCardText class="pa-0">
        <VSkeletonLoader
          v-if="isLoading"
          type="table"
        />

        <div
          v-else-if="store.wallet.transactions.length === 0"
          class="text-center pa-6 text-disabled"
        >
          <VIcon
            icon="tabler-wallet-off"
            size="48"
            class="mb-2"
          />
          <p class="text-body-1">
            {{ t('companyBilling.walletNoTransactions') }}
          </p>
        </div>

        <VDataTable
          v-else
          :headers="headers"
          :items="store.wallet.transactions"
          :items-per-page="-1"
          hide-default-footer
        >
          <template #item.created_at="{ item }">
            {{ formatDate(item.created_at) }}
          </template>

          <template #item.type="{ item }">
            <VChip
              :color="typeColor(item.type)"
              size="small"
            >
              {{ typeLabel(item.type) }}
            </VChip>
          </template>

          <template #item.description="{ item }">
            {{ item.description || item.source_type }}
          </template>

          <template #item.amount="{ item }">
            <span
              class="font-weight-medium"
              :class="item.type === 'credit' ? 'text-success' : 'text-error'"
            >
              {{ item.type === 'credit' ? '+' : '−' }}{{ formatMoney(Math.abs(item.amount)) }}
            </span>
          </template>

          <template #item.balance_after="{ item }">
            {{ formatMoney(item.balance_after) }}
          </template>
        </VDataTable>
      </VCardText>
    </VCard>
  </div>
</template>
