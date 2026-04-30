<script setup>
import EmailInboxTab from '../email/_EmailInboxTab.vue'
import EmailTemplatesTab from '../email/_EmailTemplatesTab.vue'
import EmailOrchestrationTab from '../email/_EmailOrchestrationTab.vue'
import EmailSettingsTab from '../email/_EmailSettingsTab.vue'
import EmailLogsTab from '../email/_EmailLogsTab.vue'
import EmailHealthTab from '../email/_EmailHealthTab.vue'
import MessagingNotifications from './_MessagingNotifications.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.email',
    navActiveLink: 'platform-messaging-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-messaging-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: val => router.replace({ name: 'platform-messaging-tab', params: { tab: val } }),
})

const tabs = computed(() => [
  { title: t('platformMessaging.tabs.inbox'), icon: 'tabler-inbox', tab: 'inbox' },
  { title: t('platformMessaging.tabs.templates'), icon: 'tabler-template', tab: 'templates' },
  { title: t('platformMessaging.tabs.orchestration'), icon: 'tabler-robot', tab: 'orchestration' },
  { title: t('platformMessaging.tabs.logs'), icon: 'tabler-list', tab: 'logs' },
  { title: t('platformMessaging.tabs.health'), icon: 'tabler-heartbeat', tab: 'health' },
  { title: t('platformMessaging.tabs.settings'), icon: 'tabler-settings', tab: 'settings' },
  { title: t('platformMessaging.tabs.notifications'), icon: 'tabler-bell', tab: 'notifications' },
])
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('platformMessaging.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('platformMessaging.subtitle') }}
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
        :to="{ name: 'platform-messaging-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="inbox">
        <EmailInboxTab />
      </VWindowItem>

      <VWindowItem value="templates">
        <EmailTemplatesTab />
      </VWindowItem>

      <VWindowItem value="orchestration">
        <EmailOrchestrationTab />
      </VWindowItem>

      <VWindowItem value="logs">
        <EmailLogsTab />
      </VWindowItem>

      <VWindowItem value="health">
        <EmailHealthTab />
      </VWindowItem>

      <VWindowItem value="settings">
        <EmailSettingsTab />
      </VWindowItem>

      <VWindowItem value="notifications">
        <MessagingNotifications />
      </VWindowItem>
    </VWindow>
  </div>
</template>
