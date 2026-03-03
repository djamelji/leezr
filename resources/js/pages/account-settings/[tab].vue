<script setup>
import AccountSettingsAccount from '@/views/pages/account-settings/AccountSettingsAccount.vue'
import AccountSettingsPreferences from '@/views/pages/account-settings/AccountSettingsPreferences.vue'
import AccountSettingsSecurity from '@/views/pages/account-settings/AccountSettingsSecurity.vue'
import AccountSettingsDocuments from '@/views/pages/account-settings/AccountSettingsDocuments.vue'
import { $api } from '@/utils/api'

const { t } = useI18n()
const route = useRoute('account-settings-tab')
const router = useRouter()

const activeTab = computed({
  get: () => route.params.tab,
  set: () => route.params.tab,
})

// ADR-173: Self-documents — fetch once, share with tab
const selfDocuments = ref([])
const documentsLoaded = ref(false)

const hasDocuments = computed(() => selfDocuments.value.length > 0)

const fetchSelfDocuments = async () => {
  try {
    const data = await $api('/profile/documents')
    selfDocuments.value = data.documents || []
  }
  catch {
    selfDocuments.value = []
  }
  finally {
    documentsLoaded.value = true
  }
}

onMounted(async () => {
  await fetchSelfDocuments()

  // Redirect if direct access to /account-settings/documents but no docs available
  if (route.params.tab === 'documents' && !hasDocuments.value) {
    router.replace({ name: 'account-settings-tab', params: { tab: 'account' } })
  }
})

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
    {
      title: t('accountSettings.preferences'),
      icon: 'tabler-settings',
      tab: 'preferences',
    },
  ]

  // ADR-173: Documents tab visible only if documents available
  if (hasDocuments.value) {
    items.push({
      title: t('accountSettings.documents'),
      icon: 'tabler-file-text',
      tab: 'documents',
    })
  }

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

      <VWindowItem value="preferences">
        <AccountSettingsPreferences />
      </VWindowItem>

      <VWindowItem
        v-if="hasDocuments"
        value="documents"
      >
        <AccountSettingsDocuments
          :documents="selfDocuments"
          @refresh="fetchSelfDocuments"
        />
      </VWindowItem>
    </VWindow>
  </div>
</template>
