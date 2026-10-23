<script setup>
/**
 * AI Operations — formerly platform/ai/[tab].vue
 * Sub-tabs are now LOCAL ref (not URL-driven) since AI is nested inside Operations hub.
 */
import AiOperationsTab from '../ai/_AiOperationsTab.vue'
import AiProvidersTab from '../ai/_AiProvidersTab.vue'
import AiRoutingTab from '../ai/_AiRoutingTab.vue'
import AiSettingsTab from '../ai/_AiSettingsTab.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

const { t } = useI18n()
const router = useRouter()
const auth = usePlatformAuthStore()

const activeTab = ref('providers')

// ADR-601: the AI "Usage" sub-tab is removed (its consumption metrics now live in the consolidated
// "Usage & Consumption" page). A contextual link points there. Providers/operations(health)/routing/
// settings are AI-specific features and stay here.
const tabs = computed(() => [
  { title: t('platformAi.tabs.providers'), icon: 'tabler-server-cog', tab: 'providers' },
  { title: t('platformAi.tabs.operations'), icon: 'tabler-activity', tab: 'operations' },
  { title: t('platformAi.tabs.routing'), icon: 'tabler-route', tab: 'routing' },
  { title: t('platformAi.tabs.settings'), icon: 'tabler-settings', tab: 'settings' },
])

const goToUsageConsumption = () => router.push({ name: 'platform-operations-tab', params: { tab: 'usage' } })
</script>

<template>
  <div>
    <!-- ADR-601: contextual link to the consolidated AI usage section -->
    <VAlert
      v-if="auth.hasPermission('view_ai')"
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
      data-test="ai-usage-link"
    >
      <div class="d-flex align-center justify-space-between flex-wrap gap-2">
        <span>{{ t('platformUsage.aiLinkHint') }}</span>
        <VBtn size="small" variant="tonal" color="info" append-icon="tabler-arrow-right" @click="goToUsageConsumption">
          {{ t('platformUsage.title') }}
        </VBtn>
      </div>
    </VAlert>

    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
      >
        <VIcon
          size="20"
          start
          :icon="item.icon"
        />
        {{ item.title }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="activeTab"
      class="mt-6 disable-tab-transition"
      :touch="false"
    >
      <VWindowItem value="providers">
        <AiProvidersTab />
      </VWindowItem>

      <VWindowItem value="operations">
        <AiOperationsTab />
      </VWindowItem>

      <VWindowItem value="routing">
        <AiRoutingTab />
      </VWindowItem>

      <VWindowItem value="settings">
        <AiSettingsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
