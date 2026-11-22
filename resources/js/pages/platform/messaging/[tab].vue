<script setup>
import AppTabFamily from '@/components/app/AppTabFamily.vue'
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
    permission: 'manage_email', // ADR-801 : tous les onglets
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

// ADR-861 (L7.7) — 7 tabs → 3 groups : what arrives, what Leezr sends, whether it is delivered
const GROUPS = [
  { key: 'inbox', icon: 'tabler-inbox', pages: ['inbox'] },
  { key: 'content', icon: 'tabler-template', pages: ['templates', 'orchestration', 'notifications'] },
  { key: 'delivery', icon: 'tabler-send', pages: ['logs', 'health', 'settings'] },
]
const groups = computed(() => GROUPS.map(g => ({ ...g, title: t(`l7.messaging.group.${g.key}`), items: g.pages.map(k => tabs.value.find(x => x.tab === k)).filter(Boolean) })))
</script>

<template>
  <div>
    <AppTabFamily
      :family="t('platformMessaging.title')"
      :groups="groups"
      route-name="platform-messaging-tab"
      :active="activeTab"
      hint-prefix="l7.messaging.hint"
      test="messaging"
    />

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
