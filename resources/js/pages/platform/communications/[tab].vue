<script setup>
import CommunicationsSupport from './_CommunicationsSupport.vue'
import CommunicationsDocumentation from './_CommunicationsDocumentation.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.support',
    navActiveLink: 'platform-communications-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-communications-tab')
const router = useRouter()
const auth = usePlatformAuthStore()

// ADR-598 LOT C: communications tabs filtered by effective permission.
const tabs = computed(() => [
  { title: t('platformCommunications.tabs.support'), icon: 'tabler-message-circle-cog', tab: 'support', permission: 'manage_support' },
  { title: t('platformCommunications.tabs.documentation'), icon: 'tabler-book', tab: 'documentation', permission: 'manage_documentation' },
].filter(item => !item.permission || auth.hasPermission(item.permission)))

const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: val => router.replace({ name: 'platform-communications-tab', params: { tab: val } }),
})
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('platformCommunications.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformCommunications.subtitle') }}
        </p>
      </div>
    </div>

    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-communications-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="support">
        <CommunicationsSupport />
      </VWindowItem>

      <VWindowItem value="documentation">
        <CommunicationsDocumentation />
      </VWindowItem>
    </VWindow>
  </div>
</template>
