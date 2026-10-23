<script setup>
import CatalogModules from '../modules/_CatalogModules.vue'
import CatalogJobdomains from '../jobdomains/_CatalogJobdomains.vue'
import CatalogFields from './_CatalogFields.vue'
import CatalogDocuments from '../documents/_CatalogDocuments.vue'
import CatalogPlans from './_CatalogPlans.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.modules',
    navActiveLink: 'platform-catalog-tab',
  },
})

const { t } = useI18n()
const route = useRoute('platform-catalog-tab')
const router = useRouter()
const auth = usePlatformAuthStore()

// ADR-598 LOT C: tabs filtered by effective permission — a hub link must not expose tabs the
// user is not authorized for (API is already enforced server-side; this is the UX layer).
const tabs = computed(() => [
  { title: t('platformCatalog.tabs.modules'), icon: 'tabler-puzzle', tab: 'modules', permission: 'manage_modules' },
  { title: t('platformCatalog.tabs.jobdomains'), icon: 'tabler-briefcase', tab: 'jobdomains', permission: 'manage_jobdomains' },
  { title: t('platformCatalog.tabs.fields'), icon: 'tabler-forms', tab: 'fields', permission: 'manage_field_definitions' },
  { title: t('platformCatalog.tabs.documents'), icon: 'tabler-file-text', tab: 'documents', permission: 'manage_document_catalog' },
  { title: t('platformCatalog.tabs.plans'), icon: 'tabler-chart-bar', tab: 'plans', permission: 'manage_plans' },
].filter(item => !item.permission || auth.hasPermission(item.permission)))

// If the current tab is not among the authorized tabs, fall back to the first allowed one.
const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: val => router.replace({ name: 'platform-catalog-tab', params: { tab: val } }),
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
        :to="{ name: 'platform-catalog-tab', params: { tab: item.tab } }"
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
      <VWindowItem value="modules">
        <CatalogModules />
      </VWindowItem>

      <VWindowItem value="jobdomains">
        <CatalogJobdomains />
      </VWindowItem>

      <VWindowItem value="fields">
        <CatalogFields />
      </VWindowItem>

      <VWindowItem value="documents">
        <CatalogDocuments />
      </VWindowItem>

      <VWindowItem value="plans">
        <CatalogPlans />
      </VWindowItem>
    </VWindow>
  </div>
</template>
