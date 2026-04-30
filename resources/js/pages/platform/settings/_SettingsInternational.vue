<script setup>
/**
 * International settings — formerly platform/international/[tab].vue
 * Sub-tabs are now LOCAL ref (not URL-driven) since International is nested inside Settings hub.
 */
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import TabMarkets from '../international/_TabMarkets.vue'
import TabLanguages from '../international/_TabLanguages.vue'
import TabTranslations from '../international/_TabTranslations.vue'
import TabFxRates from '../international/_TabFxRates.vue'

const { t } = useI18n()
const platformAuth = usePlatformAuthStore()

const activeTab = ref('markets')

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
