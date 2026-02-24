<script setup>
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import TabMarkets from './_TabMarkets.vue'
import TabLanguages from './_TabLanguages.vue'
import TabTranslations from './_TabTranslations.vue'
import TabFxRates from './_TabFxRates.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.markets',
    navActiveLink: 'platform-international-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-international-tab')
const platformAuth = usePlatformAuthStore()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

// Permission-filtered tabs
const allTabs = [
  { title: 'international.tabs.markets', icon: 'tabler-world', tab: 'markets', permission: 'manage_markets' },
  { title: 'international.tabs.languages', icon: 'tabler-language', tab: 'languages', permission: 'manage_markets' },
  { title: 'international.tabs.translations', icon: 'tabler-table', tab: 'translations', permission: 'manage_translations' },
  { title: 'international.tabs.fxRates', icon: 'tabler-currency-dollar', tab: 'fx-rates', permission: 'manage_markets' },
]

const visibleTabs = computed(() =>
  allTabs.filter(tab => platformAuth.hasPermission(tab.permission)),
)
</script>

<template>
  <div>
    <h4 class="text-h4 mb-6">
      {{ t('international.title') }}
    </h4>

    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in visibleTabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-international-tab', params: { tab: item.tab } }"
      >
        <VIcon
          size="20"
          start
          :icon="item.icon"
        />
        {{ t(item.title) }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem
        v-if="platformAuth.hasPermission('manage_markets')"
        value="markets"
      >
        <TabMarkets />
      </VWindowItem>

      <VWindowItem
        v-if="platformAuth.hasPermission('manage_markets')"
        value="languages"
      >
        <TabLanguages />
      </VWindowItem>

      <VWindowItem
        v-if="platformAuth.hasPermission('manage_translations')"
        value="translations"
      >
        <TabTranslations />
      </VWindowItem>

      <VWindowItem
        v-if="platformAuth.hasPermission('manage_markets')"
        value="fx-rates"
      >
        <TabFxRates />
      </VWindowItem>
    </VWindow>
  </div>
</template>
