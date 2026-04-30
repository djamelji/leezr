<script setup>
import CatalogModules from '../modules/_CatalogModules.vue'
import CatalogJobdomains from '../jobdomains/_CatalogJobdomains.vue'
import CatalogFields from './_CatalogFields.vue'
import CatalogDocuments from '../documents/_CatalogDocuments.vue'
import CatalogPlans from './_CatalogPlans.vue'

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

const activeTab = computed({
  get: () => route.params.tab,
  set: val => router.replace({ name: 'platform-catalog-tab', params: { tab: val } }),
})

const tabs = computed(() => [
  { title: t('platformCatalog.tabs.modules'), icon: 'tabler-puzzle', tab: 'modules' },
  { title: t('platformCatalog.tabs.jobdomains'), icon: 'tabler-briefcase', tab: 'jobdomains' },
  { title: t('platformCatalog.tabs.fields'), icon: 'tabler-forms', tab: 'fields' },
  { title: t('platformCatalog.tabs.documents'), icon: 'tabler-file-text', tab: 'documents' },
  { title: t('platformCatalog.tabs.plans'), icon: 'tabler-chart-bar', tab: 'plans' },
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
