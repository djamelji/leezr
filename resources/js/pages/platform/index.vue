<script setup>
const { t } = useI18n()

import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { usePlatformUsersStore } from '@/modules/platform-admin/users/users.store'
import { usePlatformRolesStore } from '@/modules/platform-admin/roles/roles.store'
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useDashboardStore } from '@/modules/platform-admin/dashboard/dashboard.store'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.dashboard',
  },
})

const companiesStore = usePlatformCompaniesStore()
const usersStore = usePlatformUsersStore()
const rolesStore = usePlatformRolesStore()
const settingsStore = usePlatformSettingsStore()
const dashboardStore = useDashboardStore()

// ── Stats (optional — fail-safe, never blocks dashboard) ──
const isStatsLoading = ref(true)
const statsError = ref(null)

const stats = ref({
  companies: 0,
  platformUsers: 0,
  companyUsers: 0,
  roles: 0,
  modules: 0,
})

async function loadStats() {
  try {
    const results = await Promise.allSettled([
      companiesStore.fetchCompanies(),
      usersStore.fetchPlatformUsers(),
      usersStore.fetchCompanyUsers(),
      rolesStore.fetchRoles(),
      settingsStore.fetchModules(),
    ])

    // Count failures for user feedback
    const failures = results.filter(r => r.status === 'rejected')

    if (failures.length) {
      statsError.value = t('platformDashboard.statsPartialError', { count: failures.length })
    }

    // Read whatever succeeded — stores are already updated by their own actions
    stats.value = {
      companies: companiesStore.companiesPagination?.total ?? 0,
      platformUsers: usersStore.platformUsersPagination?.total ?? 0,
      companyUsers: usersStore.companyUsersPagination?.total ?? 0,
      roles: rolesStore.roles?.length ?? 0,
      modules: settingsStore.modules?.length ?? 0,
    }
  }
  catch (err) {
    statsError.value = err?.message || t('platformDashboard.statsError')
  }
  finally {
    isStatsLoading.value = false
  }
}

// ── Cockpit: attention + health (ADR-441) ──
const hasCritical = computed(() =>
  dashboardStore.attentionItems.some(i => i.severity === 'critical'),
)

const criticalCount = computed(() =>
  dashboardStore.attentionItems.filter(i => i.severity === 'critical').reduce((sum, i) => sum + i.count, 0),
)

const severityColor = severity => {
  const map = { critical: 'error', warning: 'warning', info: 'info' }

  return map[severity] || 'secondary'
}

const healthStatusColor = status => {
  const map = { ok: 'success', warning: 'warning', critical: 'error', unknown: 'secondary' }

  return map[status] || 'secondary'
}

const healthStatusIcon = status => {
  const map = {
    ok: 'tabler-check',
    warning: 'tabler-alert-circle',
    critical: 'tabler-alert-triangle',
    unknown: 'tabler-help',
  }

  return map[status] || 'tabler-help'
}

const healthBadgesList = computed(() => {
  const badges = dashboardStore.healthBadges
  if (!badges || !Object.keys(badges).length) return []

  return Object.values(badges)
})

// ── Mount: three independent flows ──
onMounted(() => {
  // 1. Cockpit (attention + health) — hero
  dashboardStore.loadCockpit()

  // 2. Stats — optional, non-blocking
  loadStats()

  // 3. Dashboard engine — critical, independent
  dashboardStore.loadDashboard()
})

const cards = computed(() => [
  {
    title: t('Companies'),
    value: stats.value.companies,
    icon: 'tabler-building',
    color: 'primary',
    to: { name: 'platform-companies' },
  },
  {
    title: t('Platform Users'),
    value: stats.value.platformUsers,
    icon: 'tabler-user-shield',
    color: 'error',
    to: { name: 'platform-access-tab', params: { tab: 'users' } },
  },
  {
    title: t('Company Users'),
    value: stats.value.companyUsers,
    icon: 'tabler-users-group',
    color: 'info',
    to: { name: 'platform-companies' },
  },
  {
    title: t('Roles'),
    value: stats.value.roles,
    icon: 'tabler-shield-lock',
    color: 'success',
    to: { name: 'platform-access-tab', params: { tab: 'roles' } },
  },
  {
    title: t('Modules'),
    value: stats.value.modules,
    icon: 'tabler-puzzle',
    color: 'warning',
    to: { name: 'platform-modules' },
  },
])

// ── Dashboard Engine (D4e.3) ──
const showCatalogDrawer = ref(false)
const hasDashboardWidgets = computed(() => dashboardStore.layout.length > 0)

// Catalog: widgets not already in layout
const availableWidgets = computed(() => {
  const layoutKeys = new Set(dashboardStore.layout.map(i => i.key))

  return dashboardStore.catalog.filter(w => !layoutKeys.has(w.key))
})

// Widget scope management
const widgetScopeCompanyIds = ref({})

const onScopeChange = (key, scope) => {
  const companyId = scope === 'company' ? (widgetScopeCompanyIds.value[key] || null) : null

  dashboardStore.updateWidgetScope(key, scope, companyId)
}

const onCompanyIdChange = (key, companyId) => {
  widgetScopeCompanyIds.value[key] = companyId
  dashboardStore.updateWidgetScope(key, 'company', companyId)
}

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
</script>

<template>
  <div>
    <h4 class="text-h4 mb-6">
      {{ t('platformDashboard.title') }}
    </h4>

    <!-- ═══ ATTENTION REQUIRED (ADR-441) ═══ -->
    <template v-if="dashboardStore.cockpitLoading">
      <VCard
        flat
        class="mb-6"
      >
        <VCardText>
          <VSkeletonLoader
            type="heading"
            class="mb-3"
          />
          <VRow>
            <VCol
              v-for="n in 3"
              :key="n"
              cols="12"
              sm="6"
              md="4"
            >
              <VSkeletonLoader type="card" />
            </VCol>
          </VRow>
        </VCardText>
      </VCard>
    </template>

    <template v-else>
      <!-- Attention items exist -->
      <template v-if="dashboardStore.attentionItems.length > 0">
        <!-- Critical banner -->
        <VAlert
          v-if="hasCritical"
          type="error"
          variant="tonal"
          icon="tabler-alert-triangle"
          class="mb-4"
        >
          {{ t('platformDashboard.attentionBanner', { count: criticalCount }) }}
        </VAlert>

        <h5 class="text-h5 mb-4">
          {{ t('platformDashboard.attentionTitle') }}
        </h5>

        <VRow class="card-grid card-grid-xs mb-6">
          <VCol
            v-for="item in dashboardStore.attentionItems"
            :key="item.type"
            cols="12"
            sm="6"
            md="4"
            lg="3"
          >
            <VCard>
              <VCardText class="d-flex align-center gap-x-4">
                <VAvatar
                  :color="severityColor(item.severity)"
                  variant="tonal"
                  size="44"
                  rounded
                >
                  <VIcon
                    :icon="item.icon"
                    size="28"
                  />
                </VAvatar>
                <div class="flex-grow-1">
                  <h4 class="text-h4">
                    {{ item.count }}
                  </h4>
                  <p class="text-body-2 mb-0 text-medium-emphasis">
                    {{ t(`platformDashboard.${item.type}`) }}
                  </p>
                </div>
                <VBtn
                  :to="item.route"
                  variant="tonal"
                  :color="severityColor(item.severity)"
                  size="small"
                >
                  {{ t('platformDashboard.viewAction') }}
                </VBtn>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </template>

      <!-- No attention items — all clear -->
      <VAlert
        v-else
        type="success"
        variant="tonal"
        icon="tabler-circle-check"
        density="compact"
        class="mb-6"
      >
        {{ t('platformDashboard.noAttention') }}
      </VAlert>

      <!-- ═══ SYSTEM HEALTH (ADR-441) ═══ -->
      <VCard
        flat
        class="mb-6"
      >
        <VCardText class="d-flex align-center flex-wrap gap-3">
          <span class="text-body-1 font-weight-medium text-high-emphasis me-2">
            {{ t('platformDashboard.healthTitle') }}
          </span>
          <VTooltip
            v-for="badge in healthBadgesList"
            :key="badge.label"
            :text="badge.detail"
            location="bottom"
          >
            <template #activator="{ props: tooltipProps }">
              <VChip
                v-bind="tooltipProps"
                :color="healthStatusColor(badge.status)"
                :prepend-icon="healthStatusIcon(badge.status)"
                variant="tonal"
                size="default"
                label
              >
                {{ badge.label }}
              </VChip>
            </template>
          </VTooltip>
        </VCardText>
      </VCard>
    </template>

    <!-- ═══ Stats Error ═══ -->
    <VAlert
      v-if="statsError"
      type="warning"
      variant="tonal"
      class="mb-4"
      closable
    >
      {{ statsError }}
    </VAlert>

    <!-- ═══ KPI STAT CARDS ═══ -->
    <VRow class="card-grid card-grid-xs">
      <VCol
        v-for="card in cards"
        :key="card.title"
        cols="12"
        sm="6"
        md="4"
      >
        <VCard :loading="isStatsLoading">
          <VCardText class="d-flex align-center gap-x-4">
            <VAvatar
              :color="card.color"
              variant="tonal"
              size="44"
              rounded
            >
              <VIcon
                :icon="card.icon"
                size="28"
              />
            </VAvatar>
            <div>
              <p class="text-body-1 mb-0 text-high-emphasis font-weight-medium">
                {{ card.title }}
              </p>
              <h4 class="text-h4">
                {{ isStatsLoading ? '—' : card.value }}
              </h4>
            </div>
            <VSpacer />
            <VBtn
              :to="card.to"
              variant="tonal"
              :color="card.color"
              size="small"
            >
              {{ t('common.view') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ═══ Dashboard Host (ADR-198 — stable grid position) ═══ -->
    <DashboardHostContainer>
      <template #toolbar>
        <div class="d-flex align-center mt-8 mb-4">
          <h5 class="text-h5">
            {{ t('platformDashboard.engine.widgetsTitle') }}
          </h5>
          <VSpacer />
          <VBtn
            v-if="dashboardStore.isDirty"
            variant="tonal"
            color="success"
            size="small"
            class="me-2"
            @click="saveAndResolve"
          >
            <VIcon
              start
              icon="tabler-device-floppy"
              size="18"
            />
            {{ t('platformDashboard.engine.saveLayout') }}
          </VBtn>
          <VBtn
            variant="tonal"
            size="small"
            @click="showCatalogDrawer = true"
          >
            <VIcon
              start
              icon="tabler-plus"
              size="18"
            />
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
        @update:layout="dashboardStore.updateLayout($event)"
      />

      <div
        v-else-if="!dashboardStore.isLoading"
        class="text-center pa-8 text-disabled"
      >
        {{ t('platformDashboard.engine.noWidgets') }}
      </div>
    </DashboardHostContainer>

    <!-- ═══ Catalog Drawer ═══ -->
    <Teleport to="body">
    <VNavigationDrawer
      v-model="showCatalogDrawer"
      temporary
      location="end"
      width="360"
    >
      <VCardTitle class="pa-4">
        {{ t('platformDashboard.engine.catalogTitle') }}
      </VCardTitle>
      <VDivider />
      <VList v-if="availableWidgets.length">
        <VListItem
          v-for="widget in availableWidgets"
          :key="widget.key"
          @click="addWidgetFromCatalog(widget)"
        >
          <VListItemTitle>{{ t(widget.label_key) }}</VListItemTitle>
          <VListItemSubtitle>{{ t(widget.description_key) }}</VListItemSubtitle>
          <template #append>
            <VChip
              size="x-small"
              variant="tonal"
              :color="widget.scope === 'both' ? 'info' : widget.scope === 'global' ? 'success' : 'warning'"
            >
              {{ widget.scope }}
            </VChip>
          </template>
        </VListItem>
      </VList>
      <VCardText
        v-else
        class="text-center text-disabled"
      >
        {{ t('platformDashboard.engine.allWidgetsAdded') }}
      </VCardText>
    </VNavigationDrawer>
    </Teleport>

    <!-- ═══ Save Error ═══ -->
    <VSnackbar
      :model-value="!!dashboardStore.saveError"
      color="error"
      :timeout="5000"
    >
      {{ dashboardStore.saveError }}
    </VSnackbar>
  </div>
</template>
