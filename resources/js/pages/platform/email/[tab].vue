<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import EmailInboxTab from './_EmailInboxTab.vue'
import EmailLogsTab from './_EmailLogsTab.vue'
import EmailTemplatesTab from './_EmailTemplatesTab.vue'
import EmailOrchestrationTab from './_EmailOrchestrationTab.vue'
import EmailSettingsTab from './_EmailSettingsTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.email',
    navActiveKey: 'platform-messaging',
  },
})

const { t } = useI18n()
const route = useRoute()
const currentTab = computed(() => route.params.tab || 'inbox')

const tabs = [
  { value: 'inbox', title: t('email.inbox'), icon: 'tabler-inbox' },
  { value: 'templates', title: t('email.templates'), icon: 'tabler-template' },
  { value: 'automations', title: t('email.automations'), icon: 'tabler-robot' },
  { value: 'settings', title: t('email.settings'), icon: 'tabler-settings' },
  { value: 'logs', title: t('email.logs'), icon: 'tabler-list' },
]
</script>

<template>
  <div>
    <VTabs
      :model-value="currentTab"
      class="mb-6"
    >
      <VTab
        v-for="tab in tabs"
        :key="tab.value"
        :value="tab.value"
        :to="{ name: 'platform-email-tab', params: { tab: tab.value } }"
      >
        <VIcon :icon="tab.icon" size="20" class="me-2" />
        {{ tab.title }}
      </VTab>
    </VTabs>

    <EmailInboxTab v-if="currentTab === 'inbox'" />
    <EmailTemplatesTab v-else-if="currentTab === 'templates'" />
    <EmailOrchestrationTab v-else-if="currentTab === 'automations'" />
    <EmailSettingsTab v-else-if="currentTab === 'settings'" />
    <EmailLogsTab v-else-if="currentTab === 'logs'" />
  </div>
</template>
