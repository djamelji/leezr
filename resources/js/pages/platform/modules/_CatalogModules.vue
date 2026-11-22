<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'
import PaymentModulesSection from './_PaymentModulesSection.vue'
import PaymentRulesSection from './_PaymentRulesSection.vue'

// Sub-component — no definePage — hub: platform/catalog/[tab].vue

const { t } = useI18n()
const router = useRouter()
const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const togglingKey = ref(null)
const activeTab = ref('company')
const isSyncing = ref(false)

// Filters (company tab only)
const filterType = ref(null)
const filterPlan = ref(null)
const filterEnabled = ref(null)

onMounted(async () => {
  try {
    await settingsStore.fetchModules()
  }
  finally {
    isLoading.value = false
  }
})

// ─── Company tab ────────────────────────────────────
const filteredModules = computed(() => {
  return settingsStore.modules.filter(m => {
    if (filterType.value && m.type !== filterType.value) return false
    if (filterPlan.value === 'none' && m.min_plan !== null) return false
    if (filterPlan.value && filterPlan.value !== 'none' && m.min_plan !== filterPlan.value) return false
    if (filterEnabled.value === true && !m.is_enabled_globally) return false
    if (filterEnabled.value === false && m.is_enabled_globally) return false

    return true
  })
})

const companyHeaders = computed(() => [
  { title: t('platformModules.module'), key: 'name' },
  { title: t('platformModules.key'), key: 'key', width: '180px' },
  { title: t('common.type'), key: 'type', align: 'center', width: '100px', sortable: false },
  { title: t('platformModules.minPlan'), key: 'min_plan', align: 'center', width: '120px', sortable: false },
  { title: t('platformModules.jobdomains'), key: 'compatible_jobdomains', align: 'center', width: '140px', sortable: false },
  { title: t('platformModules.global'), key: 'status', align: 'center', width: '100px', sortable: false },
])

const filterTypeItems = computed(() => [
  { title: t('platformModules.core'), value: 'core' },
  { title: t('platformModules.addon'), value: 'addon' },
])

const filterPlanItems = computed(() => [
  { title: t('platformModules.noRequirement'), value: 'none' },
  { title: t('platformModules.pro'), value: 'pro' },
  { title: t('platformModules.business'), value: 'business' },
])

const filterEnabledItems = computed(() => [
  { title: t('platformModules.enabled'), value: true },
  { title: t('platformModules.disabled'), value: false },
])

// ── ADR-821 — catalogue lisible ──
const { moduleName } = useCatalogText()
const kindFilter = ref('extensions')
const moduleSearch = ref('')
const filterCommercial = ref(null)
const commercialItems = computed(() => ['available', 'preview', 'internal', 'deprecated'].map(v => ({ value: v, title: t(`platformModulesCatalog.commercial.${v}`) })))
const kindMatch = (m, f) => f === 'all' || (f === 'core' ? m.type === 'core' : m.type !== 'core')
const kindCount = f => settingsStore.modules.filter(m => kindMatch(m, f)).length
const catalogRows = computed(() => {
  const q = (moduleSearch.value || '').toLowerCase()

  return settingsStore.modules.filter(m => kindMatch(m, kindFilter.value)
    && (!filterCommercial.value || (m.commercial_status || 'available') === filterCommercial.value)
    && (!q || `${moduleName(m.key, m.name)} ${m.key}`.toLowerCase().includes(q)))
})
const catalogHeaders = computed(() => [
  { title: t('platformModules.module'), key: 'name' },
  { title: t('platformModulesCatalog.dependencies'), key: 'requires', sortable: false },
  { title: t('platformModulesCatalog.offerCol'), key: 'offer', sortable: false },
  { title: t('platformModulesCatalog.commercialCol'), key: 'commercial', sortable: false },
  { title: t('platformModules.jobdomains'), key: 'compatible_jobdomains', sortable: false },
  { title: t('platformModules.global'), key: 'status', align: 'center', width: 90, sortable: false },
])

const onRowClick = (_event, { item }) => {
  router.push({ name: 'platform-modules-key', params: { key: item.key } })
}

const toggleModule = async module => {
  togglingKey.value = module.key

  try {
    const data = await settingsStore.toggleModule(module.key)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformModules.failedToToggle'), 'error')
  }
  finally {
    togglingKey.value = null
  }
}

const togglePlatformModule = async module => {
  togglingKey.value = module.key

  try {
    const data = await settingsStore.togglePlatformModule(module.key)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformModules.failedToToggle'), 'error')
  }
  finally {
    togglingKey.value = null
  }
}

const planLabel = planKey => {
  const labels = { pro: t('platformModules.pro'), business: t('platformModules.business') }

  return labels[planKey] || planKey
}

const clearFilters = () => {
  filterType.value = null
  filterPlan.value = null
  filterEnabled.value = null
}

const hasFilters = computed(() =>
  filterType.value !== null || filterPlan.value !== null || filterEnabled.value !== null,
)

// ─── Sync ────────────────────────────────────────────
const syncModules = async () => {
  isSyncing.value = true

  try {
    await settingsStore.syncModules()
    toast(t('platformModules.syncSuccess'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformModules.syncFailed'), 'error')
  }
  finally {
    isSyncing.value = false
  }
}

// ─── Platform tab ───────────────────────────────────
const platformHeaders = computed(() => [
  { title: t('platformModules.module'), key: 'name' },
  { title: t('platformModules.key'), key: 'key', width: '200px' },
  { title: t('common.type'), key: 'type', align: 'center', width: '100px', sortable: false },
  { title: t('platformModules.surface'), key: 'surface', align: 'center', width: '120px', sortable: false },
  { title: t('platformModules.global'), key: 'status', align: 'center', width: '100px', sortable: false },
  { title: t('platformModules.permissionsTitle'), key: 'permissions', sortable: false },
])

const visiblePlatformModules = computed(() => {
  return settingsStore.platformModules.filter(m => m.visibility !== 'hidden')
})

const onPlatformRowClick = (_event, { item }) => {
  if (item.settings_route) {
    router.push({ name: item.settings_route, params: { tab: 'general' } })
  }
}
</script>

<template>
  <div>
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between">
        <div class="d-flex align-center">
          <VIcon
            icon="tabler-puzzle"
            class="me-2"
          />
          {{ t('platformModules.title') }}
        </div>
        <VBtn
          v-platform-can="'manage_modules'"
          variant="tonal"
          color="secondary"
          size="small"
          :loading="isSyncing"
          :disabled="isSyncing"
          @click="syncModules"
        >
          <VIcon
            start
            icon="tabler-refresh"
            size="18"
          />
          {{ t('platformModules.syncButton') }}
        </VBtn>
      </VCardTitle>
      <VCardSubtitle>
        {{ t('platformModules.subtitle') }}
      </VCardSubtitle>

      <VCardText class="pb-0">
        <VTabs
          v-model="activeTab"
          class="v-tabs-pill"
        >
          <VTab value="company">
            <VIcon
              size="20"
              start
              icon="tabler-building"
            />
            {{ t('platformModules.companyModules') }}
          </VTab>
          <VTab value="platform">
            <VIcon
              size="20"
              start
              icon="tabler-server"
            />
            {{ t('platformModules.platformTab') }}
          </VTab>
        </VTabs>
      </VCardText>

      <VWindow
        v-model="activeTab"
        class="disable-tab-transition"
        :touch="false"
      >
        <!-- Tab 1: Company Modules -->
        <VWindowItem value="company">
          <!-- ADR-821 — catalogue lisible : recherche, filtres d'état, dépendances, offre et commercialisation -->
          <div class="d-flex flex-wrap align-center gap-3 mb-4">
            <VChipGroup
              v-model="kindFilter"
              mandatory
              selected-class="text-primary"
              data-test="pm-kind-filter"
            >
              <VChip
                v-for="f in ['extensions', 'core', 'all']"
                :key="f"
                :value="f"
                variant="tonal"
                label
              >
                {{ t(`platformModulesCatalog.kind.${f}`) }} ({{ kindCount(f) }})
              </VChip>
            </VChipGroup>
            <VSpacer />
            <AppSelect
              v-model="filterCommercial"
              :items="commercialItems"
              :placeholder="t('platformModulesCatalog.commercialFilter')"
              clearable
              style="max-inline-size: 220px"
            />
            <AppTextField
              v-model="moduleSearch"
              prepend-inner-icon="tabler-search"
              :placeholder="t('platformModulesCatalog.search')"
              clearable
              style="max-inline-size: 240px"
              data-test="pm-search"
            />
          </div>
          <VDataTable
            :headers="catalogHeaders"
            :items="catalogRows"
            :loading="isLoading"
            :items-per-page="-1"
            hide-default-footer
            hover
            class="text-no-wrap"
            data-test="pm-table"
            @click:row="onRowClick"
          >
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-3 py-2">
                <VAvatar
                  size="34"
                  variant="tonal"
                  :color="item.type === 'core' ? 'secondary' : 'primary'"
                  rounded
                >
                  <VIcon
                    :icon="item.icon_name || 'tabler-puzzle'"
                    size="20"
                  />
                </VAvatar>
                <div>
                  <div class="font-weight-medium text-high-emphasis">
                    {{ moduleName(item.key, item.name) }}
                  </div>
                  <div class="text-caption text-disabled">
                    {{ item.key }}
                  </div>
                </div>
              </div>
            </template>
            <template #item.requires="{ item }">
              <span
                v-if="!item.requires_all?.length"
                class="text-disabled"
              >—</span>
              <VChip
                v-for="r in item.requires_all"
                :key="r"
                size="x-small"
                label
                class="me-1"
                variant="tonal"
              >
                {{ moduleName(r, r) }}
              </VChip>
            </template>
            <template #item.offer="{ item }">
              <VChip
                size="small"
                label
                variant="tonal"
                :color="item.addon_pricing ? 'warning' : (item.min_plan ? 'info' : 'success')"
              >
                {{ item.type === 'core' ? t('platformModulesCatalog.offer.core') : (item.addon_pricing ? t('platformModulesCatalog.offer.option') : (item.min_plan ? t('platformModulesCatalog.offer.fromPlan', { plan: item.min_plan }) : t('platformModulesCatalog.offer.all'))) }}
              </VChip>
            </template>
            <template #item.commercial="{ item }">
              <VChip
                size="small"
                label
                variant="outlined"
                :color="item.commercial_status === 'available' ? 'success' : 'secondary'"
              >
                {{ t(`platformModulesCatalog.commercial.${item.commercial_status || 'available'}`) }}
              </VChip>
            </template>
            <template #item.compatible_jobdomains="{ item }">
              <span
                v-if="!item.compatible_jobdomains"
                class="text-medium-emphasis"
              >{{ t('platformModules.all') }}</span>
              <VChip
                v-for="jd in item.compatible_jobdomains || []"
                :key="jd"
                size="x-small"
                label
                class="me-1"
              >
                {{ jd }}
              </VChip>
            </template>
            <template #item.status="{ item }">
              <VSwitch
                v-platform-can:disable="'manage_modules'"
                :model-value="item.is_enabled_globally"
                :loading="togglingKey === item.key"
                :disabled="item.type === 'core'"
                density="compact"
                hide-details
                @click.stop
                @update:model-value="toggleModule(item)"
              />
            </template>
            <template #no-data>
              <div class="text-center pa-6 text-medium-emphasis">
                {{ t('platformModulesCatalog.empty') }}
              </div>
            </template>
          </VDataTable>
        </VWindowItem>

        <VWindowItem value="platform">
          <VCardText class="pb-0">
            <p class="text-body-2 text-medium-emphasis mb-0">
              {{ t('platformModules.platformSubtitle') }}
            </p>
          </VCardText>

          <VDataTable
            :headers="platformHeaders"
            :items="visiblePlatformModules"
            :loading="isLoading"
            item-value="key"
            :items-per-page="-1"
            hide-default-footer
            hover
            class="text-no-wrap cursor-pointer"
            @click:row="onPlatformRowClick"
          >
            <!-- Module name with icon -->
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-x-3 py-2">
                <VAvatar
                  size="32"
                  color="secondary"
                  variant="tonal"
                >
                  <VIcon
                    :icon="item.icon_name || 'tabler-puzzle'"
                    size="18"
                  />
                </VAvatar>
                <div>
                  <span class="text-body-1 font-weight-medium text-high-emphasis">
                    {{ item.name }}
                  </span>
                  <p
                    v-if="item.description"
                    class="text-body-2 text-medium-emphasis mb-0"
                  >
                    {{ item.description }}
                  </p>
                </div>
              </div>
            </template>

            <!-- Key -->
            <template #item.key="{ item }">
              <code class="text-sm">{{ item.key }}</code>
            </template>

            <!-- Type badge -->
            <template #item.type="{ item }">
              <VChip
                :color="item.type === 'platform' ? 'info' : 'secondary'"
                size="small"
                variant="tonal"
              >
                {{ item.type === 'platform' ? t('platformModules.platformType') : t('platformModules.internalLabel') }}
              </VChip>
            </template>

            <!-- Surface -->
            <template #item.surface="{ item }">
              <span class="text-body-2">{{ item.surface }}</span>
            </template>

            <!-- Global status toggle -->
            <template #item.status="{ item }">
              <div @click.stop>
                <VSwitch
                  v-platform-can:disable="'manage_modules'"
                  :model-value="item.is_enabled_globally"
                  :loading="togglingKey === item.key"
                  :disabled="togglingKey === item.key"
                  color="primary"
                  hide-details
                  @update:model-value="togglePlatformModule(item)"
                />
              </div>
            </template>

            <!-- Permissions -->
            <template #item.permissions="{ item }">
              <template v-if="item.permissions?.length">
                <VChip
                  v-for="perm in item.permissions"
                  :key="perm"
                  size="x-small"
                  variant="outlined"
                  class="me-1 mb-1"
                >
                  {{ perm }}
                </VChip>
              </template>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </template>

            <!-- Empty state -->
            <template #no-data>
              <div class="text-center pa-4 text-disabled">
                {{ t('platformModules.noModules') }}
              </div>
            </template>
          </VDataTable>

          <!-- Payment Modules Section -->
          <VCardText>
            <VDivider class="mb-6" />
            <h6 class="text-h6 mb-4">
              <VIcon
                icon="tabler-credit-card"
                class="me-2"
              />
              {{ t('platformModules.paymentModules') }}
            </h6>
            <PaymentModulesSection />

            <VDivider class="my-6" />
            <h6 class="text-h6 mb-4">
              <VIcon
                icon="tabler-list-check"
                class="me-2"
              />
              {{ t('platformModules.paymentRules') }}
            </h6>
            <PaymentRulesSection />
          </VCardText>
        </VWindowItem>
      </VWindow>
    </VCard>
  </div>
</template>
