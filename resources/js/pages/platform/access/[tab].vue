<script setup>
import AppTabFamily from '@/components/app/AppTabFamily.vue'
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
    anyPermission: ['manage_platform_users', 'manage_roles', 'view_audit_logs'], // ADR-801 : union des onglets
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

// ADR-861 (L7.7) — one family « Accès Platform » : who, with which rights, and the trace of what they did
const groups = computed(() => [{ key: 'access', icon: 'tabler-user-shield', title: t('l7.access.family'), items: tabs.value }])
</script>

<template>
  <div>
    <AppTabFamily
      :family="t('l7.access.family')"
      :groups="groups"
      route-name="platform-access-tab"
      :active="activeTab"
      hint-prefix="l7.access.hint"
      test="access"
    />

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
