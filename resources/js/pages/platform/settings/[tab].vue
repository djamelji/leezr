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
import AppPageHeader from '@/components/app/AppPageHeader.vue'

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

// ADR-860 (L7) — 8 settings at one level → 5 groups (same pattern as Billing, ADR-842) ; same pages, same addresses,
// same permissions. The header says where the user is (« Paramètres › group ») and what the page sets.
const GROUPS = [
  { key: 'identity', icon: 'tabler-palette', pages: ['general', 'theme'] },
  { key: 'security', icon: 'tabler-shield-lock', pages: ['sessions', 'maintenance'] },
  { key: 'billing', icon: 'tabler-receipt-2', pages: ['billing', 'notifications'] },
  { key: 'international', icon: 'tabler-world', pages: ['international'] },
  { key: 'advanced', icon: 'tabler-flag', pages: ['feature-flags'] },
]
const { te } = useI18n()
const groups = computed(() => GROUPS
  .map(g => ({ ...g, title: t(`l7.settings.group.${g.key}`), items: g.pages.map(k => tabs.value.find(x => x.tab === k)).filter(Boolean) }))
  .filter(g => g.items.length))

const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: () => route.params.tab,
})
const activeGroup = computed(() => groups.value.find(g => g.items.some(x => x.tab === activeTab.value)) || null)
const activePage = computed(() => tabs.value.find(x => x.tab === activeTab.value) || null)
const eyebrow = computed(() => [t('l7.settings.family'), activeGroup.value && activeGroup.value.items.length > 1 ? activeGroup.value.title : null].filter(Boolean).join(' › '))
const hint = computed(() => (activePage.value && te(`l7.settings.hint.${activePage.value.tab}`) ? t(`l7.settings.hint.${activePage.value.tab}`) : ''))
watchEffect(() => { if (activePage.value) document.title = `${activePage.value.title} · ${t('l7.settings.family')} · Leezr` })
</script>

<template>
  <div>
    <AppPageHeader
      :eyebrow="eyebrow"
      :title="activePage?.title || t('l7.settings.family')"
      :description="hint"
      data-test="settings-header"
    />
    <!-- groups (primary) → pages of the group (secondary) -->
    <VTabs
      :model-value="activeGroup?.key"
      class="v-tabs-pill"
      :mandatory="false"
      show-arrows
      data-test="settings-groups"
    >
      <VTab
        v-for="g in groups"
        :key="g.key"
        :value="g.key"
        :to="{ name: 'platform-settings-tab', params: { tab: g.items[0].tab } }"
        :data-test="`settings-group-${g.key}`"
      >
        <VIcon
          size="20"
          start
          :icon="g.icon"
        />
        {{ g.title }}
      </VTab>
    </VTabs>
    <VTabs
      v-if="activeGroup && activeGroup.items.length > 1"
      v-model="activeTab"
      density="compact"
      class="mt-3"
      :mandatory="false"
      show-arrows
      data-test="settings-pages"
    >
      <VTab
        v-for="item in activeGroup.items"
        :key="item.tab"
        :value="item.tab"
        :to="{ name: 'platform-settings-tab', params: { tab: item.tab } }"
        :data-test="`settings-page-${item.tab}`"
      >
        <VIcon
          size="18"
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
