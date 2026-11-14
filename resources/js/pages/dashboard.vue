<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyDashboardStore } from '@/modules/company/dashboard/dashboard.store'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'
import OnboardingWidget from './company/dashboard/_OnboardingWidget.vue'
import QuickActionsBar from './company/dashboard/_QuickActionsBar.vue'
import HomePrioritiesSection from '@/components/dashboard/HomePrioritiesSection.vue'
import { useCan } from '@/composables/useCan'
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'

definePage({
  meta: {
    module: 'core.dashboard',
  },
})

const { t } = useI18n()
const auth = useAuthStore()
const dashboardStore = useCompanyDashboardStore()
const complianceStore = useCompanyComplianceStore()

const { can, canModule } = useCan()

// ADR-776 (UX1) — the home answers three questions, in this order : what needs me (À traiter),
// what can I do now (Actions), how is the company doing (Vue d'ensemble). The widget catalogue
// and the layout tools live behind « Personnaliser » : the default home is usable as is.
const { actions: homeActions } = useHomeActions()
const prioritiesRef = ref(null)
const customizing = ref(false)
const refreshing = ref(false)

const refresh = async () => {
  refreshing.value = true
  try {
    await Promise.allSettled([prioritiesRef.value?.load(), dashboardStore.resolveWidgets()])
  }
  finally {
    refreshing.value = false
  }
}

const stopCustomizing = async () => {
  if (dashboardStore.isDirty) await saveAndResolve()
  customizing.value = false
}

// ADR-751b : un administrateur Company PEUT aussi être salarié. Le WorkspaceResolver le
// route vers /dashboard (sa gestion) ; on lui donne ici un accès EXPLICITE à son espace
// opérationnel /home (pointage, planning, congés, docs, activités) sans quitter la gestion.
// Condition = posséder un enregistrement Employee actif (résolu self-service côté serveur),
// jamais un rôle codé en dur. Le store clock est déjà alimenté par le widget navbar.
const clock = useWorkforceClockStore()
const isEmployee = computed(() => !!clock.employee)
const showMySpace = computed(() => isEmployee.value) // ADR-769 : `clock.employee` n'existe que si le serveur annonce la capacité

const canEdit = computed(() => auth.isAdministrative)
const companyName = computed(() => auth.currentCompany?.name || '')
const userName = computed(() => auth.user?.first_name || auth.user?.display_name || '')

// E2.1 (ADR-691): la compliance documentaire lit /company/documents/compliance (module core.documents
// + documents.view). On NE l'émet que si le viewer détient réellement la capacité → plus de 403 inutile
// (pas de masquage frontend d'erreur : on évite la requête interdite).
const canCompliance = computed(() => canModule('core.documents') && can('documents.view'))

onMounted(() => {
  // Fire-and-forget — grid mounts as soon as layout resolves (ADR-198)
  // Compliance widgets now come from backend catalog (ADR-327)
  dashboardStore.loadDashboard().catch(err => {
    console.error('[Dashboard] loadDashboard FAILED:', err)
  })
  if (canCompliance.value)
    complianceStore.fetchCompliance()
  // Résout « suis-je salarié ? » pour proposer l'accès à l'espace opérationnel (idempotent :
  // le widget navbar peut l'avoir déjà fait ; firstOrFail → 404 silencieux si non-salarié).
  // ADR-769 : le store décide (capacités serveur) — aucun appel Workforce sans module ni fiche liée.
  if (!clock.employee)
    clock.fetchProfile()
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
    <!-- ═══ Header ═══ -->
    <div class="d-flex align-center flex-wrap gap-3 mb-6">
      <div class="flex-grow-1">
        <h4 class="text-h4">
          {{ t('dashboard.welcome', { name: userName }) }}
        </h4>
        <p
          v-if="companyName"
          class="text-body-1 text-medium-emphasis mb-0"
        >
          {{ companyName }}
        </p>
      </div>
      <!-- ADR-751b / ADR-776 : accès contextuel à l'espace opérationnel d'un gestionnaire aussi salarié -->
      <VBtn
        v-if="showMySpace"
        :to="{ name: 'company-workforce-me' }"
        variant="text"
        prepend-icon="tabler-calendar-time"
        data-test="home-my-activity"
      >
        {{ t('nav.company.workforce-me') }}
      </VBtn>
      <IconBtn
        :loading="refreshing"
        :aria-label="t('common.refresh')"
        :title="t('common.refresh')"
        data-test="home-refresh"
        @click="refresh"
      >
        <VIcon icon="tabler-refresh" />
      </IconBtn>
    </div>

    <!-- ═══ Onboarding (ADR-383 — self-hides once done or dismissed) ═══ -->
    <OnboardingWidget />

    <!-- ═══ À traiter + Actions ═══ -->
    <VRow class="mb-2">
      <VCol
        v-if="homeActions.length"
        cols="12"
        md="4"
        class="order-first order-md-last"
      >
        <QuickActionsBar />
      </VCol>
      <VCol
        cols="12"
        :md="homeActions.length ? 8 : 12"
      >
        <HomePrioritiesSection ref="prioritiesRef" />
      </VCol>
    </VRow>

    <!-- ═══ Dashboard Host (ADR-198 — stable grid position) ═══ -->
    <DashboardHostContainer>
      <template #toolbar>
        <div class="d-flex align-center flex-wrap gap-2 mb-4">
          <h5 class="text-h5">
            {{ t('home.sections.overview') }}
          </h5>
          <VSpacer />
          <template v-if="canEdit && customizing">
            <VBtn
              variant="tonal"
              size="small"
              prepend-icon="tabler-plus"
              data-test="home-add-widget"
              @click="showCatalogDrawer = true"
            >
              {{ t('companyDashboard.addWidget') }}
            </VBtn>
            <VBtn
              color="primary"
              size="small"
              data-test="home-customize-done"
              @click="stopCustomizing"
            >
              {{ t('home.customizeDone') }}
            </VBtn>
          </template>
          <VBtn
            v-else-if="canEdit"
            variant="text"
            size="small"
            prepend-icon="tabler-adjustments"
            data-test="home-customize"
            @click="customizing = true"
          >
            {{ t('home.customize') }}
          </VBtn>
        </div>
        <VAlert
          v-if="customizing && pendingSuggestions.length"
          type="info"
          variant="tonal"
          density="compact"
          class="mb-4"
        >
          <div class="d-flex align-center flex-wrap gap-2">
            <span>{{ t('companyDashboard.suggestionsAvailable', { count: pendingSuggestions.length }) }}</span>
            <VBtn
              v-for="suggestion in pendingSuggestions"
              :key="suggestion.widget_key"
              variant="text"
              size="small"
              @click="acceptSuggestion(suggestion)"
            >
              {{ t('companyDashboard.suggestionsApprove') }} {{ suggestion.widget_key }}
            </VBtn>
          </div>
        </VAlert>
      </template>

      <!-- ═══ Dashboard Grid ═══ -->
      <DashboardGrid
        v-if="hasDashboardWidgets"
        :row-height="60"
        :layout="dashboardStore.layout"
        :widget-data="dashboardStore.widgetData"
        :widget-errors="dashboardStore.widgetErrors"
        :catalog="dashboardStore.catalog"
        :loading="dashboardStore.dataLoading"
        :editable="canEdit && customizing"
        @update:layout="dashboardStore.updateLayout($event)"
      />

      <!-- ═══ Empty State ═══ -->
      <div
        v-else-if="!dashboardStore.isLoading"
        class="text-body-1 text-medium-emphasis py-4"
        data-test="home-overview-empty"
      >
        {{ canEdit ? t('home.overviewEmptyEditable') : t('home.overviewEmpty') }}
      </div>

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
