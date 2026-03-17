<script setup>
import { useAuthStore } from '@/core/stores/auth'
import { useOperationalHomeStore } from '@/modules/company/home/home.store'
import DashboardGrid from '@/components/dashboard/DashboardGrid.vue'
import DashboardHostContainer from '@/components/dashboard/DashboardHostContainer.vue'

const { t } = useI18n()
const auth = useAuthStore()
const homeStore = useOperationalHomeStore()

const canEdit = computed(() => auth.hasPermission('manage-own-dashboard'))

onMounted(() => {
  homeStore.loadDashboard()
})

// ── Dashboard Engine ──
const showCatalogDrawer = ref(false)
const hasWidgets = computed(() => homeStore.layout.length > 0)

const availableWidgets = computed(() => {
  const layoutKeys = new Set(homeStore.layout.map(i => i.key))

  return homeStore.catalog.filter(w => !layoutKeys.has(w.key))
})

const addWidgetFromCatalog = widget => {
  homeStore.addWidget(widget)
  showCatalogDrawer.value = false
}

const saveAndResolve = async () => {
  try {
    await homeStore.saveLayout()
    await homeStore.resolveWidgets()
  }
  catch {
    // saveError is set by the engine — UI shows snackbar
  }
}
</script>

<template>
  <div>
    <!-- ═══ Dashboard Host (ADR-357 — operational workspace) ═══ -->
    <DashboardHostContainer>
      <template #toolbar>
        <div
          v-if="canEdit && hasWidgets"
          class="d-flex align-center mb-4"
        >
          <h5 class="text-h5">
            {{ t('home.widgetsTitle') }}
          </h5>
          <VSpacer />
          <VBtn
            v-if="homeStore.isDirty"
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
            {{ t('home.saveLayout') }}
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
            {{ t('home.addWidget') }}
          </VBtn>
        </div>
      </template>

      <!-- ═══ Dashboard Grid ═══ -->
      <DashboardGrid
        v-if="hasWidgets"
        :layout="homeStore.layout"
        :widget-data="homeStore.widgetData"
        :widget-errors="homeStore.widgetErrors"
        :catalog="homeStore.catalog"
        :loading="homeStore.dataLoading"
        :editable="canEdit"
        @update:layout="homeStore.updateLayout($event)"
      />

      <!-- ═══ Empty State ═══ -->
      <VCard
        v-else-if="!homeStore.isLoading"
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
            icon="tabler-home"
            size="40"
          />
        </VAvatar>
        <h5 class="text-h5 mb-2">
          {{ t('home.noWidgets') }}
        </h5>
        <p class="text-body-1 text-disabled mb-4">
          {{ t('home.emptyStateHint') }}
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
          {{ t('home.addWidget') }}
        </VBtn>
      </VCard>

      <!-- ═══ Loading State ═══ -->
      <div
        v-if="homeStore.isLoading"
        class="d-flex justify-center pa-8"
      >
        <VProgressCircular indeterminate />
      </div>
    </DashboardHostContainer>

    <!-- ═══ Save Error ═══ -->
    <VSnackbar
      :model-value="!!homeStore.saveError"
      color="error"
      :timeout="5000"
    >
      {{ homeStore.saveError }}
    </VSnackbar>

    <!-- ═══ Catalog Drawer ═══ -->
    <VNavigationDrawer
      v-model="showCatalogDrawer"
      temporary
      location="end"
      width="360"
    >
      <VCardTitle class="pa-4">
        {{ t('home.catalogTitle') }}
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
        {{ t('home.allWidgetsAdded') }}
      </VCardText>
    </VNavigationDrawer>
  </div>
</template>
