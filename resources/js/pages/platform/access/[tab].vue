<script setup>
/**
 * Platform Access Management — Tabbed master page (Users + Roles)
 * Pattern: settings/[tab].vue
 * ADR-380
 */
import PlatformLogsTab from './_PlatformLogsTab.vue'
import RolesTab from './_RolesTab.vue'
import UsersTab from './_UsersTab.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.users',
    navActiveLink: 'platform-access-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-access-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = computed(() => [
  { title: t('platformUsers.title'), icon: 'tabler-user-shield', tab: 'users' },
  { title: t('platformRoles.title'), icon: 'tabler-shield-lock', tab: 'roles' },
  { title: t('audit.platformTab'), icon: 'tabler-file-search', tab: 'logs' },
])
</script>

<template>
  <div>
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-access-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="users">
        <UsersTab />
      </VWindowItem>

      <VWindowItem value="roles">
        <RolesTab />
      </VWindowItem>

      <VWindowItem value="logs">
        <PlatformLogsTab />
      </VWindowItem>
    </VWindow>
  </div>
</template>
