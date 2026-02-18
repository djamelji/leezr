<script setup>
import SettingsGeneral from './_SettingsGeneral.vue'
import SettingsSession from './_SettingsSession.vue'
import SettingsTheme from './_SettingsTheme.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveLink: 'platform-settings-tab',
  },
})

const route = useRoute('platform-settings-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = [
  { title: 'General', icon: 'tabler-settings', tab: 'general' },
  { title: 'Theme', icon: 'tabler-palette', tab: 'theme' },
  { title: 'Sessions', icon: 'tabler-clock-shield', tab: 'sessions' },
]
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
    </VWindow>
  </div>
</template>
