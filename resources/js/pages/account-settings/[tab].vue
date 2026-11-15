<script setup>
import AccountSettingsAccount from '@/views/pages/account-settings/AccountSettingsAccount.vue'
import AccountSettingsSecurity from '@/views/pages/account-settings/AccountSettingsSecurity.vue'

const { t } = useI18n()
const route = useRoute('account-settings-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

// ADR-769 (M7 LOT 3) : « Mes documents » (et « Mes bulletins ») vivent dans Mon espace — un seul
// endroit. L'ancien lien `/account-settings/documents` (notifications, e-mails) y REDIRIGE ; les
// paramètres du compte gardent l'identité, la sécurité et les préférences.
if (route.params.tab === 'documents')
  router.replace({ name: 'company-my-space-tab', params: { tab: 'documents' } })

// ADR-787 : « Préférences » ne contenait que le thème et la langue, déjà réglables depuis la barre du
// haut (mêmes préférences enregistrées). L'onglet disparaît ; les anciens liens ouvrent le compte.
if (route.params.tab === 'preferences')
  router.replace({ name: 'account-settings-tab', params: { tab: 'account' } })

const tabs = computed(() => {
  const items = [
    {
      title: t('accountSettings.account'),
      icon: 'tabler-users',
      tab: 'account',
    },
    {
      title: t('accountSettings.security'),
      icon: 'tabler-lock',
      tab: 'security',
    },
  ]

  return items
})

definePage({ meta: { navActiveLink: 'account-settings-tab' } })
</script>

<template>
  <div>
    <VTabs
      v-model="activeTab"
      class="v-tabs-pill"
    >
      <VTab
        v-for="item in tabs"
        :key="item.icon"
        :value="item.tab"
        :to="{ name: 'account-settings-tab', params: { tab: item.tab } }"
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
        <AccountSettingsAccount />
      </VWindowItem>

      <VWindowItem value="security">
        <AccountSettingsSecurity />
      </VWindowItem>

    </VWindow>
  </div>
</template>
