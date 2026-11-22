<script setup>
import CatalogModules from '../modules/_CatalogModules.vue'
import CatalogJobdomains from '../jobdomains/_CatalogJobdomains.vue'
import CatalogFields from './_CatalogFields.vue'
import CatalogDocuments from '../documents/_CatalogDocuments.vue'
import CatalogPlans from './_CatalogPlans.vue'
import CatalogPresets from './_CatalogPresets.vue'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import AppTabFamily from '@/components/app/AppTabFamily.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.modules',
    anyPermission: ['manage_modules', 'manage_jobdomains', 'presets.view', 'manage_field_definitions', 'manage_document_catalog', 'manage_plans', 'view_modules', 'view_jobdomains', 'view_document_catalog'], // ADR-801
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
  { title: t('platformCatalog.tabs.modules'), icon: 'tabler-puzzle', tab: 'modules', permission: ['manage_modules', 'view_modules'] },
  { title: t('platformCatalog.tabs.jobdomains'), icon: 'tabler-briefcase', tab: 'jobdomains', permission: ['manage_jobdomains', 'view_jobdomains'] },
  { title: t('platformCatalog.tabs.presets'), icon: 'tabler-stack-2', tab: 'presets', permission: ['manage_jobdomains', 'presets.view', 'view_jobdomains'] }, // ADR-797/798 : droit complet ou droit fin
  { title: t('platformCatalog.tabs.fields'), icon: 'tabler-forms', tab: 'fields', permission: 'manage_field_definitions' },
  { title: t('platformCatalog.tabs.documents'), icon: 'tabler-file-text', tab: 'documents', permission: ['manage_document_catalog', 'view_document_catalog'] },
  { title: t('platformCatalog.tabs.plans'), icon: 'tabler-chart-bar', tab: 'plans', permission: 'manage_plans' },
].filter(item => !item.permission || [].concat(item.permission).some(p => auth.hasPermission(p))))

// If the current tab is not among the authorized tabs, fall back to the first allowed one.
const activeTab = computed({
  get: () => {
    const allowed = tabs.value.map(tb => tb.tab)

    return allowed.includes(route.params.tab) ? route.params.tab : (allowed[0] ?? route.params.tab)
  },
  set: val => router.replace({ name: 'platform-catalog-tab', params: { tab: val } }),
})

// ADR-861 (L7.7) — 6 tabs → 3 groups : what is sold, which trades, which data is asked
const GROUPS = [
  { key: 'offer', icon: 'tabler-puzzle', pages: ['modules', 'plans'] },
  { key: 'trades', icon: 'tabler-briefcase', pages: ['jobdomains', 'presets'] },
  { key: 'data', icon: 'tabler-forms', pages: ['fields', 'documents'] },
]
const groups = computed(() => GROUPS.map(g => ({ ...g, title: t(`l7.catalog.group.${g.key}`), items: g.pages.map(k => tabs.value.find(x => x.tab === k)).filter(Boolean) })))
const hints = computed(() => ({ plans: t('plans.subtitle') }))
</script>

<template>
  <div>
    <AppTabFamily
      :family="t('l7.catalog.family')"
      :groups="groups"
      route-name="platform-catalog-tab"
      :active="activeTab"
      :hints="hints"
      hint-prefix="l7.catalog.hint"
      test="catalog"
    />

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

      <VWindowItem value="presets">
        <CatalogPresets />
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
