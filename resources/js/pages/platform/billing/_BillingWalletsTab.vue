<script setup>
import EmptyState from '@/core/components/EmptyState.vue'
import { usePlatformPaymentsStore } from '@/modules/platform-admin/billing/billing.store'
import { formatMoney } from '@/utils/money'

const { t } = useI18n()
const store = usePlatformPaymentsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const search = ref('')

const headers = computed(() => [
  { title: t('platformBilling.company'), key: 'company', sortable: false },
  { title: t('platformBilling.walletBalance'), key: 'cached_balance', align: 'end' },
  { title: t('platformBilling.walletCurrency'), key: 'currency', width: '100px' },
])

const filteredWallets = computed(() => {
  if (!search.value) return store.allWallets
  const q = search.value.toLowerCase()

  return store.allWallets.filter(w =>
    w.company?.name?.toLowerCase().includes(q),
  )
})

const load = async (page = 1) => {
  isLoading.value = true
  try {
    await store.fetchAllWallets({ page })
  }
  catch {
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center">
      <VIcon
        icon="tabler-wallet"
        class="me-2"
      />
      {{ t('platformBilling.tabs.wallets') }}
      <VSpacer />
      <AppTextField
        v-model="search"
        :placeholder="t('common.search')"
        density="compact"
        prepend-inner-icon="tabler-search"
        style="max-inline-size: 220px;"
        clearable
      />
    </VCardTitle>

    <VCardText class="pa-0">
      <VSkeletonLoader
        v-if="isLoading && store.allWallets.length === 0"
        type="table"
      />

      <EmptyState
        v-else-if="store.allWallets.length === 0 && !isLoading"
        icon="tabler-wallet-off"
        :title="t('platformBilling.noWallets')"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="filteredWallets"
        :loading="isLoading"
        :items-per-page="store.allWalletsPagination.per_page"
        hide-default-footer
      >
        <template #item.company="{ item }">
          <RouterLink
            v-if="item.company?.id"
            :to="{ path: `/platform/companies/${item.company.id}`, query: { tab: 'billing' } }"
            class="font-weight-medium text-high-emphasis text-decoration-none"
          >
            {{ item.company.name }}
          </RouterLink>
          <span
            v-else
            class="font-weight-medium"
          >—</span>
        </template>

        <template #item.cached_balance="{ item }">
          <span
            class="font-weight-medium"
            :class="item.cached_balance > 0 ? 'text-success' : item.cached_balance < 0 ? 'text-error' : ''"
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
