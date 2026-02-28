<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.modules',
    permission: 'manage_modules',
  },
})

const { t } = useI18n()
const router = useRouter()
const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()
const isLoading = ref(true)
const togglingKey = ref(null)
const activeTab = ref('company')

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
      <VCardTitle class="d-flex align-center">
        <VIcon
          icon="tabler-puzzle"
          class="me-2"
        />
        {{ t('platformModules.title') }}
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
          <!-- Filters -->
          <VCardText class="pb-0">
            <VRow>
              <VCol
                cols="12"
                sm="3"
              >
                <VSelect
                  v-model="filterType"
                  :items="filterTypeItems"
                  :label="t('common.type')"
                  clearable
                  density="compact"
                  hide-details
                />
              </VCol>
              <VCol
                cols="12"
                sm="3"
              >
                <VSelect
                  v-model="filterPlan"
                  :items="filterPlanItems"
                  :label="t('platformModules.minPlan')"
                  clearable
                  density="compact"
                  hide-details
                />
              </VCol>
              <VCol
                cols="12"
                sm="3"
              >
                <VSelect
                  v-model="filterEnabled"
                  :items="filterEnabledItems"
                  :label="t('platformModules.globalStatus')"
                  clearable
                  density="compact"
                  hide-details
                />
              </VCol>
              <VCol
                cols="12"
                sm="3"
                class="d-flex align-center"
              >
                <VBtn
                  v-if="hasFilters"
                  variant="text"
                  size="small"
                  color="secondary"
                  @click="clearFilters"
                >
                  {{ t('platformModules.clearFilters') }}
                </VBtn>
              </VCol>
            </VRow>
          </VCardText>

          <VDataTable
            :headers="companyHeaders"
            :items="filteredModules"
            :loading="isLoading"
            item-value="key"
            :items-per-page="-1"
            hide-default-footer
            hover
            class="cursor-pointer"
            @click:row="onRowClick"
          >
            <!-- Module name with icon -->
            <template #item.name="{ item }">
              <div class="d-flex align-center gap-x-3 py-2">
                <VAvatar
                  size="32"
                  :color="item.type === 'core' ? 'primary' : 'info'"
                  variant="tonal"
                >
                  <VIcon
                    :icon="item.icon_name || 'tabler-puzzle'"
                    size="18"
                  />
                </VAvatar>
                <span class="text-body-1 font-weight-medium text-high-emphasis">
                  {{ item.name }}
                </span>
              </div>
            </template>

            <!-- Key -->
            <template #item.key="{ item }">
              <code class="text-sm">{{ item.key }}</code>
            </template>

            <!-- Type -->
            <template #item.type="{ item }">
              <VChip
                :color="item.type === 'core' ? 'primary' : 'info'"
                size="small"
                variant="tonal"
              >
                {{ item.type === 'core' ? t('platformModules.core') : t('platformModules.addon') }}
              </VChip>
            </template>

            <!-- Min plan -->
            <template #item.min_plan="{ item }">
              <VChip
                v-if="item.min_plan"
                color="warning"
                size="small"
                variant="tonal"
              >
                {{ planLabel(item.min_plan) }}
              </VChip>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </template>

            <!-- Compatible jobdomains -->
            <template #item.compatible_jobdomains="{ item }">
              <template v-if="item.compatible_jobdomains">
                <VChip
                  v-for="jd in item.compatible_jobdomains"
                  :key="jd"
                  size="small"
                  variant="tonal"
                  class="me-1"
                >
                  {{ jd }}
                </VChip>
              </template>
              <span
                v-else
                class="text-disabled"
              >{{ t('platformModules.all') }}</span>
            </template>

            <!-- Global status toggle -->
            <template #item.status="{ item }">
              <div @click.stop>
                <VSwitch
                  :model-value="item.is_enabled_globally"
                  :loading="togglingKey === item.key"
                  :disabled="togglingKey === item.key"
                  color="primary"
                  hide-details
                  @update:model-value="toggleModule(item)"
                />
              </div>
            </template>

            <!-- Empty state -->
            <template #no-data>
              <div class="text-center pa-4 text-disabled">
                {{ t('platformModules.noModules') }}
              </div>
            </template>
          </VDataTable>
        </VWindowItem>

        <!-- Tab 2: Platform Modules (read-only) -->
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
        </VWindowItem>
      </VWindow>
    </VCard>
  </div>
</template>
