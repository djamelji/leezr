<script setup>
// ADR-855 (Leezr 5.0, L1) — Platform cockpit V35. SAME engine as before (ADR-749/746) : one permissioned
// « attention » feed, one widget grid (DashboardGrid, drag / resize / personal layout saved), the catalog drawer.
// New : a typographic hero built from real data, the attention feed as a priority panel, the V35 default
// composition (store), the « recommended layout » action and size-aware widgets (views/platform-dashboard/cockpit).
import { useDashboardStore } from '@/modules/platform-admin/dashboard/dashboard.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'
import AppPageHero from '@/components/app/AppPageHero.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'

// Sub-component — no definePage — hub: platform/dashboard/[tab].vue
const { t } = useI18n()
const dashboardStore = useDashboardStore()
const auth = usePlatformAuthStore()

onMounted(() => {
  if (!auth.hasPermission('view_dashboard')) return
  dashboardStore.loadCockpit()
  dashboardStore.loadDashboard()
})

// ── Hero : real context only ──
const firstName = computed(() => auth.user?.first_name || auth.user?.name?.split(' ')[0] || 'Leezr')

const greeting = computed(() => {
  const h = new Date().getHours()

  return h < 5 ? t('cockpit.hero.evening') : h < 12 ? t('cockpit.hero.morning') : h < 18 ? t('cockpit.hero.afternoon') : t('cockpit.hero.evening')
})

const today = new Date().toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
const attention = computed(() => dashboardStore.attentionItems)
const attentionCount = computed(() => attention.value.reduce((s, i) => s + (i.count || 0), 0))
const critical = computed(() => attention.value.filter(i => i.severity === 'critical').length)

const subtitle = computed(() => {
  if (dashboardStore.cockpitLoading) return t('cockpit.hero.loading')
  if (!attention.value.length) return t('cockpit.hero.allClear')

  return t('cockpit.hero.attention', { n: attention.value.length }, attention.value.length)
})

const heroChips = computed(() => [
  { icon: 'tabler-calendar', text: today.charAt(0).toUpperCase() + today.slice(1), color: 'secondary' },
  ...(critical.value ? [{ icon: 'tabler-alert-triangle', text: t('cockpit.hero.critical', { n: critical.value }, critical.value), color: 'error' }] : []),
])

const heroActions = computed(() => [
  auth.hasPermission('create_companies') && { label: t('cockpit.hero.newCompany'), icon: 'tabler-building-plus', to: { name: 'platform-companies-create' }, primary: true },
  auth.hasPermission('view_billing') && { label: t('cockpit.hero.billing'), icon: 'tabler-receipt', to: { name: 'platform-billing-tab', params: { tab: 'overview' } } },
  auth.hasPermission('view_system_health') && { label: t('cockpit.hero.operations'), icon: 'tabler-activity-heartbeat', to: { name: 'platform-operations-tab', params: { tab: 'health' } } },
].filter(Boolean).slice(0, 2))

const SEV = { critical: 'error', warning: 'warning', info: 'info' }

// ── Engine ──
const showCatalogDrawer = ref(false)
const confirmReset = ref(false)
const resetting = ref(false)
const hasDashboardWidgets = computed(() => dashboardStore.layout.length > 0)

const availableWidgets = computed(() => {
  const inLayout = new Set(dashboardStore.layout.map(i => i.key))

  return dashboardStore.catalog.filter(w => !inLayout.has(w.key))
})

const addWidgetFromCatalog = widget => {
  dashboardStore.addWidget(widget)
  showCatalogDrawer.value = false
}

const saveAndResolve = async () => {
  try {
    await dashboardStore.saveLayout()
    await dashboardStore.resolveWidgets()
  }
  catch {
    // saveError is set by the engine — UI shows snackbar
  }
}

const applyRecommended = async () => {
  resetting.value = true
  try {
    await dashboardStore.resetToRecommended()
  }
  catch {
    // saveError shown by the snackbar
  }
  finally {
    resetting.value = false
    confirmReset.value = false
  }
}

// ADR-856 — a widget's « Retry » re-resolves the dashboard data (same engine)
const onWidgetRetry = () => { dashboardStore.resolveWidgets() }

onMounted(() => window.addEventListener('lzr-dashboard-retry', onWidgetRetry))
onBeforeUnmount(() => window.removeEventListener('lzr-dashboard-retry', onWidgetRetry))
</script>

<template>
  <div class="cockpit">
    <VRow class="mb-2">
      <VCol
        cols="12"
        lg="8"
      >
        <AppPageHero
          variant="cockpit"
          :greeting="greeting"
          :name="firstName"
          :subtitle="subtitle"
          :chips="heroChips"
        >
          <template #actions>
            <VBtn
              v-for="a in heroActions"
              :key="a.label"
              :to="a.to"
              :color="a.primary ? 'primary' : undefined"
              :variant="a.primary ? 'flat' : 'tonal'"
              :prepend-icon="a.icon"
            >
              {{ a.label }}
            </VBtn>
          </template>
        </AppPageHero>
      </VCol>

      <!-- Priorités : flux « attention » existant (ADR-749), permissionné serveur, chaque ligne ouvre sa page -->
      <VCol
        cols="12"
        lg="4"
      >
        <VCard
          class="lzr-card lzr-card--raised cockpit-priorities h-100"
          data-test="cockpit-priorities"
        >
          <div class="d-flex align-center gap-3 pa-4 pb-2">
            <VAvatar
              :color="critical ? 'error' : attention.length ? 'warning' : 'success'"
              variant="tonal"
              rounded="lg"
              size="34"
            >
              <VIcon
                :icon="attention.length ? 'tabler-flag-3' : 'tabler-circle-check'"
                size="19"
              />
            </VAvatar>
            <div class="flex-grow-1 min-w-0">
              <div class="text-body-1 font-weight-semibold">
                {{ t('cockpit.priorities.title') }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ attention.length ? t('cockpit.priorities.subtitle', { n: attentionCount }) : t('cockpit.priorities.none') }}
              </div>
            </div>
          </div>
          <div class="px-3 pb-3">
            <template v-if="dashboardStore.cockpitLoading">
              <VSkeletonLoader
                type="list-item-avatar@3"
                class="bg-transparent"
              />
            </template>
            <AppEmptyState
              v-else-if="!attention.length"
              icon="tabler-mood-smile"
              color="success"
              :title="t('cockpit.priorities.clearTitle')"
              :text="t('cockpit.priorities.clearText')"
              compact
            />
            <TransitionGroup
              v-else
              name="lzr-list"
              tag="div"
              class="d-flex flex-column gap-1"
            >
              <RouterLink
                v-for="item in attention"
                :key="item.type"
                :to="item.route"
                class="cockpit-priority"
                :class="`cockpit-priority--${SEV[item.severity] || 'info'}`"
              >
                <VAvatar
                  :color="SEV[item.severity] || 'info'"
                  variant="tonal"
                  size="36"
                  rounded="lg"
                >
                  <VIcon
                    :icon="item.icon"
                    size="19"
                  />
                </VAvatar>
                <div class="flex-grow-1 min-w-0">
                  <div class="text-body-2 font-weight-medium text-truncate">
                    <strong>{{ item.count }}</strong> {{ t(`platformDashboard.${item.type}`) }}
                  </div>
                </div>
                <VIcon
                  icon="tabler-chevron-right"
                  size="18"
                  class="text-disabled"
                />
              </RouterLink>
            </TransitionGroup>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <DashboardHostContainer>
      <template #toolbar>
        <div class="d-flex align-center flex-wrap gap-2 mb-4">
          <div class="me-auto">
            <div class="text-h5 font-weight-bold cockpit-title">
              {{ t('cockpit.grid.title') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('cockpit.grid.subtitle') }}
            </div>
          </div>
          <VBtn
            v-if="dashboardStore.isDirty"
            color="success"
            size="small"
            prepend-icon="tabler-device-floppy"
            data-test="cockpit-save"
            @click="saveAndResolve"
          >
            {{ t('platformDashboard.engine.saveLayout') }}
          </VBtn>
          <VBtn
            variant="text"
            size="small"
            prepend-icon="tabler-layout-dashboard"
            data-test="cockpit-recommended"
            @click="confirmReset = true"
          >
            {{ t('cockpit.grid.recommended') }}
          </VBtn>
          <VBtn
            variant="tonal"
            size="small"
            prepend-icon="tabler-plus"
            data-test="cockpit-add"
            @click="showCatalogDrawer = true"
          >
            {{ t('platformDashboard.engine.addWidget') }}
          </VBtn>
        </div>
      </template>

      <DashboardGrid
        v-if="hasDashboardWidgets"
        :layout="dashboardStore.layout"
        :widget-data="dashboardStore.widgetData"
        :widget-errors="dashboardStore.widgetErrors"
        :catalog="dashboardStore.catalog"
        :loading="dashboardStore.dataLoading"
        :editable="true"
        premium
        @update:layout="dashboardStore.updateLayout($event)"
      />
      <AppEmptyState
        v-else-if="!dashboardStore.isLoading"
        icon="tabler-layout-dashboard"
        :title="t('cockpit.grid.emptyTitle')"
        :text="t('cockpit.grid.emptyText')"
        :action="dashboardStore.catalog.length ? t('cockpit.grid.recommended') : ''"
        @action="applyRecommended"
      />
    </DashboardHostContainer>

    <!-- Catalogue : mêmes widgets permissionnés, présentation par carte -->
    <Teleport to="body">
      <VNavigationDrawer
        v-model="showCatalogDrawer"
        temporary
        location="end"
        width="400"
        class="wf-scroll-drawer"
      >
        <div class="pa-5 pb-3">
          <div class="text-h6">
            {{ t('platformDashboard.engine.catalogTitle') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('cockpit.catalog.subtitle') }}
          </div>
        </div>
        <VDivider />
        <div
          v-if="availableWidgets.length"
          class="pa-4 d-flex flex-column gap-3"
        >
          <button
            v-for="widget in availableWidgets"
            :key="widget.key"
            type="button"
            class="cockpit-catalog-item"
            @click="addWidgetFromCatalog(widget)"
          >
            <VAvatar
              color="primary"
              variant="tonal"
              rounded="lg"
              size="36"
            >
              <VIcon
                icon="tabler-layout-grid-add"
                size="19"
              />
            </VAvatar>
            <div class="flex-grow-1 min-w-0 text-start">
              <div class="font-weight-medium text-truncate">
                {{ t(widget.label_key) }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t(widget.description_key) }}
              </div>
              <div class="text-caption text-disabled mt-1">
                {{ t('cockpit.catalog.size', { w: widget.layout?.default_w, h: widget.layout?.default_h }) }}
              </div>
            </div>
            <VIcon
              icon="tabler-plus"
              size="18"
              class="text-primary"
            />
          </button>
        </div>
        <AppEmptyState
          v-else
          icon="tabler-checks"
          :title="t('platformDashboard.engine.allWidgetsAdded')"
          compact
        />
      </VNavigationDrawer>
    </Teleport>

    <VDialog
      v-model="confirmReset"
      max-width="460"
    >
      <VCard class="pa-6">
        <VAvatar
          color="primary"
          variant="tonal"
          size="48"
          class="mb-3"
        >
          <VIcon icon="tabler-layout-dashboard" />
        </VAvatar>
        <div class="text-h6 mb-1">
          {{ t('cockpit.reset.title') }}
        </div>
        <div class="text-body-2 text-medium-emphasis mb-5">
          {{ t('cockpit.reset.text') }}
        </div>
        <div class="d-flex gap-2 justify-end">
          <VBtn
            variant="text"
            @click="confirmReset = false"
          >
            {{ t('cockpit.reset.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="resetting"
            data-test="cockpit-reset-confirm"
            @click="applyRecommended"
          >
            {{ t('cockpit.reset.confirm') }}
          </VBtn>
        </div>
      </VCard>
    </VDialog>

    <VSnackbar
      :model-value="!!dashboardStore.saveError"
      color="error"
      :timeout="5000"
    >
      {{ dashboardStore.saveError }}
    </VSnackbar>
  </div>
</template>

<style scoped>
.cockpit-title { letter-spacing: -.02em; }
.cockpit-priority { display: flex; align-items: center; gap: .75rem; padding: .5rem .625rem; border-radius: 12px; color: inherit; text-decoration: none; transition: background .18s, transform .18s; }
.cockpit-priority:hover { background: rgba(var(--v-theme-on-surface), .05); transform: translateX(2px); }
.cockpit-priority--error { background: rgba(var(--v-theme-error), .06); }
.cockpit-catalog-item { display: flex; align-items: flex-start; gap: .875rem; padding: .875rem; border-radius: 14px; border: 1px solid rgba(var(--v-border-color), .12); background: rgb(var(--v-theme-surface)); transition: border-color .18s, box-shadow .18s, transform .18s; }
.cockpit-catalog-item:hover { border-color: rgba(var(--v-theme-primary), .4); box-shadow: 0 8px 20px -12px rgba(47, 43, 61, .35); transform: translateY(-1px); }
@media (prefers-reduced-motion: reduce) {
  .cockpit-priority:hover, .cockpit-catalog-item:hover { transform: none; }
}
</style>
