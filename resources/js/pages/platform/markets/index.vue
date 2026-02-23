<script setup>
import { usePlatformMarketsStore } from '@/modules/platform-admin/markets/markets.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveLink: 'platform-markets',
  },
})

const { t } = useI18n()
const router = useRouter()
const marketsStore = usePlatformMarketsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const fxLoading = ref(false)
const fxRefreshing = ref(false)

// Create dialog
const isCreateDialogOpen = ref(false)
const createLoading = ref(false)

const createForm = ref({
  key: '',
  name: '',
  currency: 'EUR',
  locale: 'fr-FR',
  timezone: 'Europe/Paris',
  dial_code: '+33',
})

const headers = [
  { title: t('markets.key'), key: 'key', width: '80px' },
  { title: t('markets.name'), key: 'name' },
  { title: t('markets.currency'), key: 'currency', width: '100px' },
  { title: t('markets.locale'), key: 'locale', width: '120px' },
  { title: t('markets.timezone'), key: 'timezone' },
  { title: t('markets.isDefault'), key: 'is_default', width: '100px', align: 'center' },
  { title: t('markets.isActive'), key: 'is_active', width: '100px', align: 'center' },
  { title: t('markets.companiesCount'), key: 'companies_count', width: '120px', align: 'center' },
  { title: t('common.actions'), key: 'actions', sortable: false, width: '100px', align: 'center' },
]

const fxHeaders = [
  { title: t('fxRates.baseCurrency'), key: 'base_currency', width: '120px' },
  { title: t('fxRates.targetCurrency'), key: 'target_currency', width: '120px' },
  { title: t('fxRates.rate'), key: 'rate' },
  { title: t('fxRates.lastUpdated'), key: 'fetched_at' },
]

onMounted(async () => {
  try {
    await Promise.all([
      marketsStore.fetchMarkets(),
      marketsStore.fetchFxRates().then(() => { fxLoading.value = false }).catch(() => { fxLoading.value = false }),
    ])
  }
  finally {
    isLoading.value = false
  }
})

const handleRefreshFx = async () => {
  fxRefreshing.value = true

  try {
    await marketsStore.refreshFxRates()
    toast(t('fxRates.refreshing'), 'success')

    // Refetch after a short delay to show updated data
    setTimeout(async () => {
      await marketsStore.fetchFxRates()
      fxRefreshing.value = false
    }, 2000)
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
    fxRefreshing.value = false
  }
}

const openCreateDialog = () => {
  createForm.value = {
    key: '',
    name: '',
    currency: 'EUR',
    locale: 'fr-FR',
    timezone: 'Europe/Paris',
    dial_code: '+33',
  }
  isCreateDialogOpen.value = true
}

const handleCreate = async () => {
  createLoading.value = true

  try {
    const data = await marketsStore.createMarket({ ...createForm.value })

    toast(data.message || t('markets.created'), 'success')
    isCreateDialogOpen.value = false
    router.push({ name: 'platform-markets-key', params: { key: data.market.key } })
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    createLoading.value = false
  }
}

const handleToggleActive = async market => {
  try {
    const data = await marketsStore.toggleActive(market.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const handleSetDefault = async market => {
  try {
    const data = await marketsStore.setDefault(market.id)

    toast(data.message, 'success')
    await marketsStore.fetchMarkets()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const navigateToMarket = key => {
  router.push({ name: 'platform-markets-key', params: { key } })
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('markets.title') }}
        </h4>
      </div>
      <VBtn
        color="primary"
        prepend-icon="tabler-plus"
        @click="openCreateDialog"
      >
        {{ t('markets.addMarket') }}
      </VBtn>
    </div>

    <!-- Markets Table -->
    <VCard>
      <VDataTable
        :headers="headers"
        :items="marketsStore.markets"
        :loading="isLoading"
        class="text-no-wrap"
        @click:row="(_, { item }) => navigateToMarket(item.key)"
      >
        <template #item.key="{ item }">
          <span class="font-weight-medium">{{ item.key }}</span>
        </template>

        <template #item.is_default="{ item }">
          <VChip
            v-if="item.is_default"
            color="primary"
            size="small"
          >
            {{ t('markets.isDefault') }}
          </VChip>
        </template>

        <template #item.is_active="{ item }">
          <VChip
            :color="item.is_active ? 'success' : 'secondary'"
            size="small"
          >
            {{ item.is_active ? t('common.active') : t('common.inactive') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <VMenu>
            <template #activator="{ props }">
              <VBtn
                v-bind="props"
                icon="tabler-dots-vertical"
                variant="text"
                size="small"
                @click.stop
              />
            </template>
            <VList density="compact">
              <VListItem @click="navigateToMarket(item.key)">
                <template #prepend>
                  <VIcon
                    icon="tabler-edit"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('common.edit') }}</VListItemTitle>
              </VListItem>
              <VListItem
                v-if="!item.is_default"
                @click="handleSetDefault(item)"
              >
                <template #prepend>
                  <VIcon
                    icon="tabler-star"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ t('markets.setDefault') }}</VListItemTitle>
              </VListItem>
              <VListItem @click="handleToggleActive(item)">
                <template #prepend>
                  <VIcon
                    :icon="item.is_active ? 'tabler-eye-off' : 'tabler-eye'"
                    size="20"
                  />
                </template>
                <VListItemTitle>{{ item.is_active ? t('common.deactivate') : t('common.activate') }}</VListItemTitle>
              </VListItem>
            </VList>
          </VMenu>
        </template>

        <template #no-data>
          <div class="text-center pa-8">
            <VIcon
              icon="tabler-world-off"
              size="48"
              color="secondary"
              class="mb-4"
            />
            <p class="text-body-1 text-medium-emphasis">
              {{ t('markets.noMarkets') }}
            </p>
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- FX Rates -->
    <VCard class="mt-6">
      <VCardTitle class="d-flex align-center justify-space-between">
        <span>{{ t('fxRates.title') }}</span>
        <VBtn
          size="small"
          variant="outlined"
          prepend-icon="tabler-refresh"
          :loading="fxRefreshing"
          @click="handleRefreshFx"
        >
          {{ t('fxRates.refresh') }}
        </VBtn>
      </VCardTitle>
      <VDataTable
        :headers="fxHeaders"
        :items="marketsStore.fxRates"
        :loading="fxLoading"
        class="text-no-wrap"
        density="compact"
      >
        <template #item.rate="{ item }">
          <span class="font-weight-medium">{{ Number(item.rate).toFixed(4) }}</span>
        </template>

        <template #item.fetched_at="{ item }">
          <span class="text-medium-emphasis">{{ item.fetched_at || '—' }}</span>
        </template>

        <template #no-data>
          <div class="text-center pa-4 text-medium-emphasis">
            {{ t('fxRates.noRates') }}
          </div>
        </template>
      </VDataTable>
    </VCard>

    <!-- Create Market Dialog -->
    <VDialog
      v-model="isCreateDialogOpen"
      max-width="500"
    >
      <VCard :title="t('markets.addMarket')">
        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="createForm.key"
                :label="t('markets.key')"
                placeholder="FR"
                hint="ISO country code (e.g. FR, US, GB)"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="createForm.name"
                :label="t('markets.name')"
                placeholder="France"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="createForm.currency"
                :label="t('markets.currency')"
                placeholder="EUR"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="createForm.locale"
                :label="t('markets.locale')"
                placeholder="fr-FR"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="createForm.timezone"
                :label="t('markets.timezone')"
                placeholder="Europe/Paris"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="createForm.dial_code"
                :label="t('markets.dialCode')"
                placeholder="+33"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="isCreateDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="createLoading"
            @click="handleCreate"
          >
            {{ t('common.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
