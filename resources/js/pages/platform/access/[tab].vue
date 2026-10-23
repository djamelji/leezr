<script setup>
/**
 * Platform Access Management — Tabbed master page (Users + Roles)
 * Pattern: settings/[tab].vue
 * ADR-380
 */
import PlatformLogsTab from './_PlatformLogsTab.vue'
import RolesTab from './_RolesTab.vue'
import UsersTab from './_UsersTab.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

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
const auth = usePlatformAuthStore()

// ADR-598 LOT C: access tabs filtered by effective permission.
const tabs = computed(() => [
  { title: t('platformUsers.title'), icon: 'tabler-user-shield', tab: 'users', permission: 'manage_platform_users' },
  { title: t('platformRoles.title'), icon: 'tabler-shield-lock', tab: 'roles', permission: 'manage_roles' },
  { title: t('audit.platformTab'), icon: 'tabler-file-search', tab: 'logs', permission: 'view_audit_logs' },
].filter(item => !item.permission || auth.hasPermission(item.permission)))

const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: () => route.params.tab,
})
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
