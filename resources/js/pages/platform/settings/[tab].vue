<script setup>
import SettingsBilling from './_SettingsBilling.vue'
import SettingsGeneral from './_SettingsGeneral.vue'
import SettingsMaintenance from './_SettingsMaintenance.vue'
import SettingsNotifications from './_SettingsNotifications.vue'
import SettingsSession from './_SettingsSession.vue'
import SettingsTheme from './_SettingsTheme.vue'
import SettingsInternational from './_SettingsInternational.vue'
import SettingsFeatureFlags from './_SettingsFeatureFlags.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.settings',
    anyPermission: ['manage_theme_settings', 'manage_session_settings', 'manage_maintenance', 'manage_billing', 'manage_notifications', 'manage_markets', 'manage_translations'], // ADR-801
    navActiveLink: 'platform-settings-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-settings-tab')
const auth = usePlatformAuthStore()

// ADR-801 (P0-2) — tabs filtered by the permission their API requires (same pattern as access/catalog).
const tabs = computed(() => [
  { title: t('platformSettings.tabs.general'), icon: 'tabler-settings', tab: 'general', permission: 'manage_theme_settings' },
  { title: t('platformSettings.tabs.theme'), icon: 'tabler-palette', tab: 'theme', permission: 'manage_theme_settings' },
  { title: t('platformSettings.tabs.sessions'), icon: 'tabler-clock-shield', tab: 'sessions', permission: 'manage_session_settings' },
  { title: t('platformSettings.tabs.maintenance'), icon: 'tabler-barrier-block', tab: 'maintenance', permission: 'manage_maintenance' },
  { title: t('platformSettings.tabs.billing'), icon: 'tabler-receipt-2', tab: 'billing', permission: 'manage_billing' },
  { title: t('platformSettings.tabs.notifications'), icon: 'tabler-bell-cog', tab: 'notifications', permission: 'manage_notifications' },
  { title: t('platformSettings.tabs.international'), icon: 'tabler-world', tab: 'international', permissionAny: ['manage_markets', 'manage_translations'] },
  { title: t('platformSettings.tabs.featureFlags'), icon: 'tabler-flag', tab: 'feature-flags', permission: 'manage_theme_settings' },
].filter(item => item.permissionAny ? item.permissionAny.some(p => auth.hasPermission(p)) : auth.hasPermission(item.permission)))

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
        :to="{ name: 'platform-settings-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="general">
        <SettingsGeneral />
      </VWindowItem>

      <VWindowItem value="theme">
        <SettingsTheme />
      </VWindowItem>

      <VWindowItem value="sessions">
        <SettingsSession />
      </VWindowItem>

      <VWindowItem value="maintenance">
        <SettingsMaintenance />
      </VWindowItem>

      <VWindowItem value="billing">
        <SettingsBilling />
      </VWindowItem>

      <VWindowItem value="notifications">
        <SettingsNotifications />
      </VWindowItem>

      <VWindowItem value="international">
        <SettingsInternational />
      </VWindowItem>

      <VWindowItem value="feature-flags">
        <SettingsFeatureFlags />
      </VWindowItem>
    </VWindow>
  </div>
</template>
