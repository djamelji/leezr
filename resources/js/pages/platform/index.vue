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

const isLoading = ref(true)

const stats = ref({
  companies: 0,
  platformUsers: 0,
  companyUsers: 0,
  roles: 0,
  modules: 0,
})

onMounted(async () => {
  try {
    await Promise.all([
      companiesStore.fetchCompanies(),
      usersStore.fetchPlatformUsers(),
      usersStore.fetchCompanyUsers(),
      rolesStore.fetchRoles(),
      settingsStore.fetchModules(),
    ])

    stats.value = {
      companies: companiesStore.companiesPagination.total,
      platformUsers: usersStore.platformUsersPagination.total,
      companyUsers: usersStore.companyUsersPagination.total,
      roles: rolesStore.roles.length,
      modules: settingsStore.modules.length,
    }
  }
  finally {
    isLoading.value = false
  }

  // Load dashboard engine (widgets)
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
    to: { name: 'platform-users' },
  },
  {
    title: t('Company Users'),
    value: stats.value.companyUsers,
    icon: 'tabler-users-group',
    color: 'info',
    to: { name: 'platform-company-users' },
  },
  {
    title: t('Roles'),
    value: stats.value.roles,
    icon: 'tabler-shield-lock',
    color: 'success',
    to: { name: 'platform-roles' },
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

    <VRow>
      <VCol
        v-for="card in cards"
        :key="card.title"
        cols="12"
        sm="6"
        md="4"
      >
        <VCard :loading="isLoading">
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
                {{ isLoading ? '—' : card.value }}
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
