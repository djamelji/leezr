<script setup>
import AccountGeneral from './_AccountGeneral.vue'
import AccountSecurity from './_AccountSecurity.vue'
import AccountNotifications from './_AccountNotifications.vue'

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.settings' } })

const { t } = useI18n()
const route = useRoute('platform-account-tab')

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

const tabs = [
  {
    title: t('platformAccount.tabAccount'),
    icon: 'tabler-user',
    tab: 'account',
  },
  {
    title: t('platformAccount.tabSecurity'),
    icon: 'tabler-lock',
    tab: 'security',
  },
  {
    title: t('platformAccount.tabNotifications'),
    icon: 'tabler-bell',
    tab: 'notifications',
  },
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
        :to="{ name: 'platform-account-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="account">
        <AccountGeneral />
      </VWindowItem>

      <VWindowItem value="security">
        <AccountSecurity />
      </VWindowItem>

      <VWindowItem value="notifications">
        <AccountNotifications />
      </VWindowItem>
    </VWindow>
  </div>
</template>
