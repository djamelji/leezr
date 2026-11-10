<script setup>
// ADR-749 (P2) — Cockpit Platform UNIFIÉ : plus d'empilement de deux générations.
// Un en-tête + un bandeau « Attention requise » COMPACT et permissionné (gated serveur,
// ADR-749 P0) + UNE seule grille de widgets premium (Presentation Engine, ADR-748b).
// Supprimés (doublons / hors pilotage) : l'ancien bloc Santé système (→ widget
// platform.system_health), les 5 cartes KPI (Entreprises/Modules → widgets ; Rôles,
// Utilisateurs → pages d'administration dédiées).
const { t } = useI18n()

import { useDashboardStore } from '@/modules/platform-admin/dashboard/dashboard.store'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'

// Sub-component — no definePage — hub: platform/dashboard/[tab].vue

const dashboardStore = useDashboardStore()
const auth = usePlatformAuthStore()

// ── Attention requise (ADR-441 / ADR-749) — bandeau compact ──
const hasCritical = computed(() =>
  dashboardStore.attentionItems.some(i => i.severity === 'critical'),
)
const criticalCount = computed(() =>
  dashboardStore.attentionItems.filter(i => i.severity === 'critical').reduce((sum, i) => sum + i.count, 0),
)
const severityColor = severity => ({ critical: 'error', warning: 'warning', info: 'info' }[severity] || 'secondary')

// ── Mount: cockpit (alertes) + moteur de widgets — deux flux indépendants ──
// ADR-598 : ne charger que si l'utilisateur détient view_dashboard (l'API reste
// enforce-serveur de toute façon ; les alertes sont en plus filtrées par domaine).
onMounted(() => {
  if (!auth.hasPermission('view_dashboard')) return
  dashboardStore.loadCockpit()
  dashboardStore.loadDashboard()
})

// ── Dashboard Engine (D4e.3) ──
const showCatalogDrawer = ref(false)
const hasDashboardWidgets = computed(() => dashboardStore.layout.length > 0)

const availableWidgets = computed(() => {
  const layoutKeys = new Set(dashboardStore.layout.map(i => i.key))

  return dashboardStore.catalog.filter(w => !layoutKeys.has(w.key))
})

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

    <!-- ═══ ATTENTION REQUISE — bandeau compact, permissionné (ADR-749) ═══ -->
    <template v-if="dashboardStore.cockpitLoading">
      <VSkeletonLoader type="chip@3" class="mb-6" />
    </template>

    <template v-else-if="dashboardStore.attentionItems.length > 0">
      <VCard flat border class="mb-6 attention-strip">
        <VCardText class="d-flex align-center flex-wrap gap-x-6 gap-y-3 pa-4">
          <div class="d-flex align-center gap-2 me-2 flex-shrink-0">
            <VIcon
              :icon="hasCritical ? 'tabler-alert-triangle' : 'tabler-bell-ringing'"
              :color="hasCritical ? 'error' : 'warning'"
              size="20"
            />
            <span class="text-body-1 font-weight-medium text-high-emphasis">{{ t('platformDashboard.attentionTitle') }}</span>
            <VChip
              v-if="hasCritical"
              color="error"
              size="x-small"
              label
              variant="tonal"
            >
              {{ t('platformDashboard.attentionBanner', { count: criticalCount }) }}
            </VChip>
          </div>

          <RouterLink
            v-for="item in dashboardStore.attentionItems"
            :key="item.type"
            :to="item.route"
            class="attention-item d-flex align-center gap-2 text-decoration-none"
          >
            <VAvatar :color="severityColor(item.severity)" variant="tonal" size="34" rounded>
              <VIcon :icon="item.icon" size="20" />
            </VAvatar>
            <div class="d-flex flex-column">
              <span class="d-flex align-baseline gap-1">
                <span class="text-subtitle-1 font-weight-medium text-high-emphasis">{{ item.count }}</span>
                <span class="text-caption text-medium-emphasis text-truncate">{{ t(`platformDashboard.${item.type}`) }}</span>
              </span>
            </div>
            <VIcon icon="tabler-chevron-right" size="16" class="text-disabled" />
          </RouterLink>
        </VCardText>
      </VCard>
    </template>

    <!-- Aucune action requise — état discret -->
    <div v-else class="d-flex align-center gap-2 mb-6 text-medium-emphasis">
      <VIcon icon="tabler-circle-check" color="success" size="18" />
      <span class="text-body-2">{{ t('platformDashboard.noAttention') }}</span>
    </div>

    <!-- ═══ Dashboard Host (ADR-198 — stable grid position) ═══ -->
    <DashboardHostContainer>
      <template #toolbar>
        <div class="d-flex align-center mb-4">
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
            <VIcon start icon="tabler-device-floppy" size="18" />
            {{ t('platformDashboard.engine.saveLayout') }}
          </VBtn>
          <VBtn
            variant="tonal"
            size="small"
            @click="showCatalogDrawer = true"
          >
            <VIcon start icon="tabler-plus" size="18" />
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

<style scoped>
.attention-item {
  color: inherit;
  padding-block: 2px;
  padding-inline: 4px;
  border-radius: 8px;
  transition: background-color 0.15s ease;
  min-inline-size: 0;
}
.attention-item:hover {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}
</style>
