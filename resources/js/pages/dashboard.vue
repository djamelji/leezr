<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyDashboardStore } from '@/modules/company/dashboard/dashboard.store'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'
import OnboardingWidget from './company/dashboard/_OnboardingWidget.vue'
import QuickActionsBar from './company/dashboard/_QuickActionsBar.vue'

definePage({
  meta: {
    module: 'core.dashboard',
  },
})

const { t } = useI18n()
const auth = useAuthStore()
const dashboardStore = useCompanyDashboardStore()
const complianceStore = useCompanyComplianceStore()

const canEdit = computed(() => auth.isAdministrative)
const companyName = computed(() => auth.currentCompany?.name || '')
const userName = computed(() => auth.user?.first_name || auth.user?.display_name || '')

onMounted(() => {
  // Fire-and-forget — grid mounts as soon as layout resolves (ADR-198)
  // Compliance widgets now come from backend catalog (ADR-327)
  dashboardStore.loadDashboard().catch(err => {
    console.error('[Dashboard] loadDashboard FAILED:', err)
  })
  complianceStore.fetchCompliance()
})

// ── Dashboard Engine ──
const showCatalogDrawer = ref(false)
const hasDashboardWidgets = computed(() => dashboardStore.layout.length > 0)

const availableWidgets = computed(() => {
  const layoutKeys = new Set(dashboardStore.layout.map(i => i.key))

  return dashboardStore.catalog.filter(w => !layoutKeys.has(w.key))
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

// ── Suggestions ──
const pendingSuggestions = computed(() =>
  dashboardStore.suggestions.filter(s => s.status === 'pending'),
)

const acceptSuggestion = suggestion => {
  const catalogEntry = dashboardStore.catalog.find(w => w.key === suggestion.widget_key)
  if (catalogEntry) {
    dashboardStore.addWidget(catalogEntry)
  }
}
</script>

<template>
  <div>
    <!-- ═══ Welcome Header ═══ -->
    <div class="mb-6">
      <h4 class="text-h4">
        {{ t('dashboard.welcome', { name: userName }) }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('dashboard.welcomeSubtitle') }}
        <span v-if="companyName">
          — <span class="font-weight-medium text-high-emphasis">{{ companyName }}</span>
        </span>
      </p>
    </div>

    <!-- ═══ Suggestions Banner ═══ -->
    <VAlert
      v-if="pendingSuggestions.length"
      type="info"
      variant="tonal"
      class="mb-6"
      closable
    >
      <template #text>
        <div class="d-flex align-center flex-wrap gap-2">
          <span>{{ t('companyDashboard.suggestionsAvailable', { count: pendingSuggestions.length }) }}</span>
          <VBtn
            v-for="suggestion in pendingSuggestions"
            :key="suggestion.widget_key"
            variant="tonal"
            size="small"
            color="primary"
            class="me-1"
            @click="acceptSuggestion(suggestion)"
          >
            {{ t('companyDashboard.suggestionsApprove') }}
            {{ suggestion.widget_key }}
          </VBtn>
        </div>
      </template>
    </VAlert>

    <!-- ═══ Onboarding (ADR-383 — outside grid, height adapts to content) ═══ -->
    <OnboardingWidget />

    <!-- ═══ Quick Actions (P0-1 — contextual guidance) ═══ -->
    <QuickActionsBar />

    <!-- ═══ First Visit Cards (shown until user adds widgets) ═══ -->
    <VRow
      v-if="!hasDashboardWidgets && !dashboardStore.isLoading"
      class="mb-6"
    >
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="text-center pa-6">
            <VAvatar
              color="primary"
              variant="tonal"
              size="56"
              class="mb-3"
            >
              <VIcon
                icon="tabler-user-check"
                size="28"
              />
            </VAvatar>
            <h6 class="text-h6 mb-1">
              {{ t('dashboard.firstVisit.profile') }}
            </h6>
            <p class="text-body-2 text-disabled mb-3">
              {{ t('dashboard.firstVisit.profileDesc') }}
            </p>
            <VBtn
              variant="tonal"
              color="primary"
              size="small"
              to="/company/profile/overview"
            >
              {{ t('dashboard.firstVisit.profileCta') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="text-center pa-6">
            <VAvatar
              color="success"
              variant="tonal"
              size="56"
              class="mb-3"
            >
              <VIcon
                icon="tabler-file-check"
                size="28"
              />
            </VAvatar>
            <h6 class="text-h6 mb-1">
              {{ t('dashboard.firstVisit.documents') }}
            </h6>
            <p class="text-body-2 text-disabled mb-3">
              {{ t('dashboard.firstVisit.documentsDesc') }}
            </p>
            <VBtn
              variant="tonal"
              color="success"
              size="small"
              :to="{ name: 'company-documents-tab', params: { tab: 'overview' } }"
            >
              {{ t('dashboard.firstVisit.documentsCta') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        md="4"
      >
        <VCard>
          <VCardText class="text-center pa-6">
            <VAvatar
              color="info"
              variant="tonal"
              size="56"
              class="mb-3"
            >
              <VIcon
                icon="tabler-users"
                size="28"
              />
            </VAvatar>
            <h6 class="text-h6 mb-1">
              {{ t('dashboard.firstVisit.members') }}
            </h6>
            <p class="text-body-2 text-disabled mb-3">
              {{ t('dashboard.firstVisit.membersDesc') }}
            </p>
            <VBtn
              variant="tonal"
              color="info"
              size="small"
              to="/company/members"
            >
              {{ t('dashboard.firstVisit.membersCta') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ═══ Dashboard Host (ADR-198 — stable grid position) ═══ -->
    <DashboardHostContainer>
      <template #toolbar>
        <div
          v-if="canEdit && hasDashboardWidgets"
          class="d-flex align-center mb-4"
        >
          <h5 class="text-h5">
            {{ t('companyDashboard.widgetsTitle') }}
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
            {{ t('companyDashboard.saveLayout') }}
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
            {{ t('companyDashboard.addWidget') }}
          </VBtn>
        </div>
      </template>

      <!-- ═══ Dashboard Grid ═══ -->
      <DashboardGrid
        v-if="hasDashboardWidgets"
        :layout="dashboardStore.layout"
        :widget-data="dashboardStore.widgetData"
        :widget-errors="dashboardStore.widgetErrors"
        :catalog="dashboardStore.catalog"
        :loading="dashboardStore.dataLoading"
        :editable="canEdit"
        @update:layout="dashboardStore.updateLayout($event)"
      />

      <!-- ═══ Empty State ═══ -->
      <VCard
        v-else-if="!dashboardStore.isLoading"
        variant="flat"
        class="text-center pa-12"
      >
        <VAvatar
          color="primary"
          variant="tonal"
          size="80"
          class="mb-4"
        >
          <VIcon
            icon="tabler-layout-dashboard"
            size="40"
          />
        </VAvatar>
        <h5 class="text-h5 mb-2">
          {{ t('companyDashboard.noWidgets') }}
        </h5>
        <p class="text-body-1 text-disabled mb-4">
          {{ t('companyDashboard.emptyStateHint') }}
        </p>
        <VBtn
          v-if="canEdit"
          variant="tonal"
          color="primary"
          @click="showCatalogDrawer = true"
        >
          <VIcon
            start
            icon="tabler-plus"
            size="18"
          />
          {{ t('companyDashboard.addWidget') }}
        </VBtn>
      </VCard>

      <!-- ═══ Loading State ═══ -->
      <div
        v-if="dashboardStore.isLoading"
        class="d-flex justify-center pa-8"
      >
        <VProgressCircular indeterminate />
      </div>
    </DashboardHostContainer>

    <!-- ═══ Save Error ═══ -->
    <VSnackbar
      :model-value="!!dashboardStore.saveError"
      color="error"
      :timeout="5000"
    >
      {{ dashboardStore.saveError }}
    </VSnackbar>

    <!-- ═══ Catalog Drawer ═══ -->
    <VNavigationDrawer
      v-model="showCatalogDrawer"
      temporary
      location="end"
      width="360"
    >
      <VCardTitle class="pa-4">
        {{ t('companyDashboard.catalogTitle') }}
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
        </VListItem>
      </VList>
      <VCardText
        v-else
        class="text-center text-disabled"
      >
        {{ t('companyDashboard.allWidgetsAdded') }}
      </VCardText>
    </VNavigationDrawer>
  </div>
</template>
