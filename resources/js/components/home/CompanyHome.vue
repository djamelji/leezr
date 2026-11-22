<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useCompanyDashboardStore } from '@/modules/company/dashboard/dashboard.store'
import { useCompanyComplianceStore } from '@/modules/company/dashboard/compliance.store'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'
import OnboardingWidget from '@/pages/company/dashboard/_OnboardingWidget.vue'
import QuickActionsBar from '@/pages/company/dashboard/_QuickActionsBar.vue'
import HomePrioritiesSection from '@/components/dashboard/HomePrioritiesSection.vue'
import { useCan } from '@/composables/useCan'
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import WorkspaceHero from '@/components/home/WorkspaceHero.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { buildSituation, isQuiet, topPriorities, workspaceFocus } from '@/core/surface-engine/workspaceSituation'

const { t, te } = useI18n()
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
    await Promise.allSettled([prioritiesRef.value?.load(), dashboardStore.resolveWidgets().then(() => dashboardStore.fetchSummary())])
  }
  finally {
    refreshing.value = false
  }
}

// ADR-856 (L4) — back to the recommended layout of the role / JobDomain (confirmation, same engine)
const confirmReset = ref(false)
const resetting = ref(false)

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
const userName = computed(() => auth.user?.first_name || auth.user?.name?.split(' ')?.[0] || auth.user?.display_name || companyName.value)

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

// ── ADR-857 (L3) — V22 workspace : hero situation, priority actions, setup mode ──
const prio = ref({ state: 'loading', priorities: [], partial: false })
const onboarding = ref(null) // { done, total, dismissed } — owner only, from the EXISTING checklist
const showIndicators = ref(false) // a quiet company may still open its (empty) indicators
const focus = computed(() => workspaceFocus(dashboardStore.catalog.map(w => w.key)))
const setup = computed(() => (onboarding.value && onboarding.value.total > 0 && onboarding.value.done < onboarding.value.total ? onboarding.value : null))
const quiet = computed(() => dashboardStore.summaryReady && isQuiet(dashboardStore.summary))

const situation = computed(() => buildSituation({
  data: dashboardStore.summary,
  priorities: prio.value.priorities,
  prioritiesState: prio.value.state,
  prioritiesPartial: prio.value.partial,
  setup: quiet.value ? setup.value : null,
}))

const sentences = computed(() => situation.value.parts.map(p => t(p.key, p.params || {}, p.params?.n ?? 1)))
const heroProgress = computed(() => (situation.value.progress ? { value: situation.value.progress.value, label: t(situation.value.progress.key) } : null))
const heroLoading = computed(() => prio.value.state === 'loading' || !dashboardStore.summaryReady)
const priorityLabel = key => (te(`home.priorities.${key}`) ? t(`home.priorities.${key}`) : key)


// 1–2 actions : the most urgent priority first (it opens the treatment), then the first everyday action of the role
const heroActions = computed(() => {
  const out = topPriorities(prio.value.priorities, 1).map(p => ({ key: `prio-${p.key}`, label: priorityLabel(p.key), icon: p.icon || 'tabler-alert-circle', to: p.to }))
  for (const a of homeActions.value) {
    if (out.length >= 2) break
    out.push({ key: a.key, label: t(a.label), icon: a.icon, to: a.to, test: `home-action-${a.key}` }) // ADR-775 contract : one home-action-<key> per served action
  }

  return out
})


// a company with nothing yet : the onboarding checklist + first operations instead of a wall of zeros
const showFirstSteps = computed(() => quiet.value && !showIndicators.value && !customizing.value)

// ADR-856 — a widget's « Retry » re-resolves the dashboard data (same engine)
const onWidgetRetry = () => { dashboardStore.resolveWidgets() }

onMounted(() => window.addEventListener('lzr-dashboard-retry', onWidgetRetry))
onBeforeUnmount(() => window.removeEventListener('lzr-dashboard-retry', onWidgetRetry))
</script>

<template>
  <div class="lz-ws">
    <!-- ═══ V22 (ADR-857) : hero + « À traiter » ═══ -->
    <VRow class="mb-1 lz-ws-top">
      <VCol
        cols="12"
        lg="8"
      >
        <WorkspaceHero
          :name="userName"
          :company="companyName"
          :sentences="sentences"
          :loading="heroLoading"
          :live="situation.live"
          :progress="heroProgress"
          :actions="heroActions"
        />
      </VCol>
      <VCol
        cols="12"
        lg="4"
      >
        <HomePrioritiesSection
          ref="prioritiesRef"
          @loaded="prio = $event"
        />
      </VCol>
    </VRow>

    <!-- ═══ Onboarding (ADR-383 — the ONE checklist ; self-hides once done or dismissed) ═══ -->
    <OnboardingWidget @state="onboarding = $event" />

    <!-- ═══ Actions rapides (server-filtered, V22 tiles) ═══ -->
    <QuickActionsBar
      v-if="!showFirstSteps"
      tiles
      :exclude="heroActions.map(a => a.key)"
      class="mb-6"
    />

    <!-- ═══ MOI (ADR-818) : le gestionnaire aussi salarié retrouve ses propres actions ici ═══ -->
    <slot name="personal" />

    <!-- ═══ Dashboard Host (ADR-198 — stable grid position) ═══ -->
    <DashboardHostContainer>
      <template #toolbar>
        <div class="d-flex align-center flex-wrap gap-2 mb-4">
          <h5 class="text-h5">
            {{ t('home.sections.overview') }}
          </h5>
          <IconBtn
            :loading="refreshing"
            size="small"
            :aria-label="t('common.refresh')"
            :title="t('common.refresh')"
            data-test="home-refresh"
            @click="refresh"
          >
            <VIcon
              icon="tabler-refresh"
              size="18"
            />
          </IconBtn>
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
              variant="text"
              size="small"
              prepend-icon="tabler-layout-dashboard"
              data-test="home-recommended"
              @click="confirmReset = true"
            >
              {{ t('cockpit.grid.recommended') }}
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
            class="lzr-text-action"
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

      <!-- ═══ ADR-857 : quiet company → first operations (no wall of zeros) ═══ -->
      <VCard
        v-if="hasDashboardWidgets && showFirstSteps"
        class="lz-first pa-2"
        data-test="home-first-steps"
      >
        <AppEmptyState
          icon="tabler-sparkles"
          :title="t('l3.first.title')"
          :text="t(focus === 'logistics' ? 'l3.first.textLogistics' : 'l3.first.text')"
        />
        <div class="d-flex flex-wrap justify-center gap-2 pb-4">
          <VBtn
            v-for="a in homeActions"
            :key="a.key"
            :to="a.to"
            variant="tonal"
            :prepend-icon="a.icon"
          >
            {{ t(a.label) }}
          </VBtn>
          <VBtn
            variant="text"
            data-test="home-show-indicators"
            @click="showIndicators = true"
          >
            {{ t('l3.first.showIndicators') }}
          </VBtn>
        </div>
      </VCard>

      <!-- ═══ Dashboard Grid ═══ -->
      <DashboardGrid
        v-else-if="hasDashboardWidgets"
        :row-height="60"
        :layout="dashboardStore.layout"
        :widget-data="dashboardStore.widgetData"
        :widget-errors="dashboardStore.widgetErrors"
        :catalog="dashboardStore.catalog"
        :loading="dashboardStore.dataLoading"
        :editable="canEdit && customizing"
        premium
        @update:layout="dashboardStore.updateLayout($event)"
      />

      <!-- ═══ Empty State ═══ -->
      <VCard
        v-else-if="!hasDashboardWidgets && !dashboardStore.isLoading"
        variant="flat"
        class="lz-first"
        data-test="home-overview-empty"
      >
        <AppEmptyState
          compact
          icon="tabler-layout-dashboard"
          :title="canEdit ? t('home.overviewEmptyEditable') : t('home.overviewEmpty')"
        />
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
            data-test="home-reset-confirm"
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

<style scoped>
/* ADR-857 (L3) — V22 entrance : cards rise in sequence once (transform only : content is never hidden) */
.lz-ws :deep(.dashboard-tile) { animation: lzr-enter .5s cubic-bezier(.22, 1, .36, 1) both; }
.lz-ws :deep(.dashboard-tile:nth-child(2)) { animation-delay: 40ms; }
.lz-ws :deep(.dashboard-tile:nth-child(3)) { animation-delay: 80ms; }
.lz-ws :deep(.dashboard-tile:nth-child(4)) { animation-delay: 120ms; }
.lz-ws :deep(.dashboard-tile:nth-child(n+5)) { animation-delay: 160ms; }
.lz-ws-top :deep(.v-col) { display: flex; flex-direction: column; }
.lz-ws-top :deep(.v-col > *) { flex: 1 1 auto; }
.lz-first { border: 1px dashed rgba(var(--v-border-color), .18); }
@media (prefers-reduced-motion: reduce) {
  .lz-ws :deep(.dashboard-tile) { animation: none; }
}
</style>
