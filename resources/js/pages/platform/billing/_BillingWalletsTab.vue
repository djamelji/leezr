<script setup>
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()

const isLoading = ref(true)

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.walletBalance'), key: 'cached_balance', align: 'end' },
  { title: t('platformBilling.walletCurrency'), key: 'currency', width: '100px' },
])

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllWallets({ page })
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-wallet"
        class="me-2"
      />
      {{ t('platformBilling.tabs.wallets') }}
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.allWallets.length === 0"
        type="table"
      />

      <div
        v-else-if="store.allWallets.length === 0 && !isLoading"
        class="text-center pa-6 text-disabled"
      >
        <VIcon
          icon="tabler-wallet-off"
          size="48"
          class="mb-2"
        />
        <p class="text-body-1">
          {{ t('platformBilling.noWallets') }}
        </p>
      </div>

      <VDataTable
        v-else
        :headers="headers"
        :items="store.allWallets"
        :loading="isLoading"
        :items-per-page="store.allWalletsPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <span class="font-weight-medium">
            {{ item.company?.name || '—' }}
          </span>
        </template>

        <template #item.cached_balance="{ item }">
          <span
            class="font-weight-medium"
            :class="item.cached_balance > 0 ? 'text-success' : ''"
          >
            {{ formatMoney(item.cached_balance, { currency: item.currency }) }}
          </span>
        </template>

        <template #item.currency="{ item }">
          {{ item.currency }}
        </template>

        <template #bottom>
          <VDivider />
          <div class="d-flex align-center justify-space-between flex-wrap gap-3 pa-4">
            <span class="text-body-2 text-disabled">
              {{ t('platformBilling.walletCount', { count: store.allWalletsPagination.total }) }}
            </span>
            <VPagination
              v-if="store.allWalletsPagination.last_page > 1"
              :model-value="store.allWalletsPagination.current_page"
              :length="store.allWalletsPagination.last_page"
              :total-visible="5"
              @update:model-value="load"
            />
          </div>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>
</template>
