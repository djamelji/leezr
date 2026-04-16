<script setup>
definePage({ meta: { surface: 'structure', module: 'core.modules', permission: 'modules.manage' } })

import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'
import { formatMoney } from '@/utils/money'
import { useRouter } from 'vue-router'

const { t } = useI18n()
const auth = useAuthStore()
const moduleStore = useModuleStore()
const { toast } = useAppToast()
const router = useRouter()

const isLoading = ref(true)
const togglingKey = ref(null)
const errorMessage = ref('')

// Tab state
const currentTab = ref(0)

// Search + filters
const addonSearchQuery = ref('')
const searchQuery = ref('')
const categoryFilter = ref('all')

// Quote dialog state
const quoteDialog = ref(false)
const quoteLoading = ref(false)
const quoteData = ref(null)
const quoteModuleKey = ref(null)
const quoteModuleName = ref('')

// Deactivation confirmation dialog
const deactivateDialog = ref(false)
const deactivateTarget = ref(null)
const deactivatePreview = ref(null)
const deactivatePreviewLoading = ref(false)

// ADR-341: Grace period reactivation dialog
const reactivateDialog = ref(false)
const reactivateModuleKey = ref(null)
const reactivateModuleName = ref('')
const reactivateDeactivateDate = ref(null)

const canManage = computed(() => auth.roleLevel === 'management')

onMounted(async () => {
  try {
    await moduleStore.fetchModules()
  }
  finally {
    isLoading.value = false
  }
})

// --- Tab-based module filtering (ADR-163) ---

const addonModulesAll = computed(() =>
  moduleStore.modules.filter(m =>
    ['available', 'locked_plan', 'locked_addon', 'contact_sales'].includes(m.display_state),
  ),
)

const addonModules = computed(() => {
  if (!addonSearchQuery.value) return addonModulesAll.value
  const q = addonSearchQuery.value.toLowerCase()

  return addonModulesAll.value.filter(m => m.name.toLowerCase().includes(q))
})

const includedModules = computed(() =>
  moduleStore.modules.filter(m => m.display_state === 'included'),
)

const activeModules = computed(() =>
  moduleStore.modules.filter(m => m.display_state === 'active'),
)

const allFilteredModules = computed(() => {
  return moduleStore.modules.filter(m => {
    if (categoryFilter.value !== 'all' && m.category !== categoryFilter.value)
      return false
    if (searchQuery.value && !m.name.toLowerCase().includes(searchQuery.value.toLowerCase()))
      return false

    return true
  })
})

const categoryItems = computed(() => [
  { title: t('companyModules.allCategories'), value: 'all' },
  { title: t('companyModules.categoryCore'), value: 'core' },
  { title: t('companyModules.categoryAddon'), value: 'addon' },
  { title: t('companyModules.categoryPremium'), value: 'premium' },
  { title: t('companyModules.categoryIndustry'), value: 'industry' },
])

// --- Animated placeholder (cycles addon names, featured first) ---

const placeholderIdx = ref(0)

const addonModulesSorted = computed(() =>
  [...addonModulesAll.value]
    .sort((a, b) => {
      if (a.is_featured !== b.is_featured) return a.is_featured ? -1 : 1

      return a.name.localeCompare(b.name)
    }),
)

const currentPlaceholderModule = computed(() => {
  const mods = addonModulesSorted.value
  if (!mods.length) return null

  return mods[placeholderIdx.value % mods.length]
})

let phTimer = null

onMounted(() => {
  phTimer = setInterval(() => {
    if (addonModulesSorted.value.length <= 1) return
    placeholderIdx.value = (placeholderIdx.value + 1) % addonModulesSorted.value.length
  }, 3000)
})

onBeforeUnmount(() => {
  if (phTimer) clearInterval(phTimer)
})

// --- Display state helpers ---

const stateChip = module => {
  const map = {
    included: { text: t('companyModules.stateIncluded'), color: 'primary' },
    active: { text: t('companyModules.stateActive'), color: 'success' },
    available: { text: t('companyModules.stateAvailable'), color: 'info' },
    locked_plan: { text: t('companyModules.stateLocked'), color: 'warning' },
    locked_addon: { text: t('companyModules.stateLockedAddon'), color: 'warning' },
    contact_sales: { text: t('companyModules.stateContactSales'), color: 'secondary' },
  }

  return map[module.display_state] || { text: module.display_state, color: 'secondary' }
}

const categoryChip = module => {
  const map = {
    core: { text: t('companyModules.categoryCore'), color: 'primary' },
    addon: { text: t('companyModules.categoryAddon'), color: 'info' },
    premium: { text: t('companyModules.categoryPremium'), color: 'warning' },
    industry: { text: t('companyModules.categoryIndustry'), color: 'success' },
  }

  return map[module.category] || map.addon
}

const isLocked = module =>
  ['locked_plan', 'locked_addon', 'contact_sales'].includes(module.display_state)

// --- Pricing helpers (ADR-210) ---

const modulePriceLabel = mod => {
  if (['included', 'active'].includes(mod.display_state)) return null
  if (!mod.addon_pricing) return t('companyModules.pricingContactSales')

  const { pricing_model, pricing_params, pricing_metric } = mod.addon_pricing
  const planKey = moduleStore.companyPlanKey || 'starter'

  if (pricing_model === 'flat') {
    const price = pricing_params?.price_monthly
    return price != null ? `${price} \u20AC/${t('companyModules.perMonth')}` : null
  }

  if (pricing_model === 'plan_flat') {
    const price = pricing_params?.[planKey]
    return price != null ? `${price} \u20AC/${t('companyModules.perMonth')}` : null
  }

  if (pricing_model === 'per_seat') {
    const metricLabel = t(`companyModules.metric_${pricing_metric || 'users'}`)
    const included = pricing_params?.included
    const overage = pricing_params?.overage_unit_price

    // Plan-based included seats + overage
    if (included && typeof included === 'object') {
      const qty = included[planKey] ?? Object.values(included)[0] ?? 0
      const overagePrice = overage?.[planKey] ?? overage ?? null
      if (overagePrice != null) {
        return t('companyModules.pricingPerSeatIncluded', { count: qty, price: overagePrice, metric: metricLabel })
      }

      return t('companyModules.pricingPerSeatBase', { count: qty, metric: metricLabel })
    }

    // Simple per-unit
    const price = pricing_params?.price_per_unit
    return price != null ? `${price} \u20AC/${metricLabel}` : null
  }

  if (pricing_model === 'usage') {
    const metricLabel = t(`companyModules.metric_${pricing_metric || 'units'}`)
    const price = pricing_params?.price_per_unit
    return price != null ? `${price} \u20AC/${metricLabel}` : null
  }

  if (pricing_model === 'tiered') {
    return t('companyModules.pricingTiered')
  }

  return null
}

const dependencyLabel = reqKey => {
  const dep = moduleStore.modules.find(m => m.key === reqKey)
  if (!dep) return null

  if (['included', 'active'].includes(dep.display_state))
    return { name: dep.name, label: t('companyModules.depIncluded'), color: 'success', icon: 'tabler-check' }

  const price = modulePriceLabel(dep)
  if (price)
    return { name: dep.name, label: price, color: 'warning', icon: 'tabler-plus' }

  return { name: dep.name, label: t('companyModules.pricingContactSales'), color: 'secondary', icon: 'tabler-plus' }
}

const dependentNames = mod => {
  if (!mod.dependents?.length) return null
  return mod.dependents
    .map(k => moduleStore.modules.find(m => m.key === k)?.name || k)
    .join(', ')
}

// --- Actions ---

const formatPeriodEnd = dateStr => {
  if (!dateStr) return ''

  return new Date(dateStr).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

const activateModule = async module => {
  errorMessage.value = ''

  // Addon pricing → check grace period first, then fetch quote
  if (module.pricing_mode === 'addon') {
    // ADR-341: Check if addon is in grace period (deactivated_at in the future)
    const addonSub = moduleStore.getAddonSub(module.key)
    if (addonSub?.deactivated_at && new Date(addonSub.deactivated_at) > new Date()) {
      reactivateModuleKey.value = module.key
      reactivateModuleName.value = module.name
      reactivateDeactivateDate.value = addonSub.deactivated_at
      reactivateDialog.value = true

      return
    }

    quoteModuleKey.value = module.key
    quoteModuleName.value = module.name
    quoteLoading.value = true
    quoteDialog.value = true
    quoteData.value = null

    try {
      quoteData.value = await moduleStore.fetchQuote([module.key])
    }
    catch (error) {
      quoteDialog.value = false
      errorMessage.value = error?.data?.message || t('companyModules.failedToToggle')
    }
    finally {
      quoteLoading.value = false
    }

    return
  }

  // Direct enable
  await doToggle(module.key, false)
}

const confirmReactivation = async () => {
  reactivateDialog.value = false
  await doToggle(reactivateModuleKey.value, false)
}

const navigateToPlanUpgrade = mod => {
  router.push({ path: '/company/plan', query: { suggest: mod.upgrade_target_plan } })
}

const requestDeactivation = async module => {
  deactivateTarget.value = module
  deactivatePreview.value = null
  deactivateDialog.value = true

  // Fetch deactivation preview for addon modules
  if (module.pricing_mode === 'addon') {
    deactivatePreviewLoading.value = true
    try {
      deactivatePreview.value = await moduleStore.fetchDeactivationPreview(module.key)
    }
    catch {
      // Fallback — dialog still works without preview
    }
    finally {
      deactivatePreviewLoading.value = false
    }
  }
}

const confirmDeactivation = async () => {
  deactivateDialog.value = false
  if (deactivateTarget.value) {
    errorMessage.value = ''
    await doToggle(deactivateTarget.value.key, true)
    deactivateTarget.value = null
  }
}

const cancelDeactivation = () => {
  deactivateDialog.value = false
  deactivateTarget.value = null
  deactivatePreview.value = null
}

const confirmQuoteEnable = async () => {
  quoteDialog.value = false
  if (quoteModuleKey.value) {
    await doToggle(quoteModuleKey.value, false)
  }
}

const doToggle = async (key, isCurrentlyEnabled) => {
  togglingKey.value = key
  errorMessage.value = ''

  const mod = moduleStore.modules.find(m => m.key === key)
  const modName = mod?.name || key

  try {
    if (isCurrentlyEnabled) {
      await moduleStore.disableModule(key)
      toast(t('companyModules.deactivatedSuccess', { module: modName }), 'success')
    }
    else {
      await moduleStore.enableModule(key)
      toast(t('companyModules.activatedSuccess', { module: modName }), 'success')
    }
  }
  catch (error) {
    errorMessage.value = error?.data?.message || t('companyModules.failedToToggle')
  }
  finally {
    togglingKey.value = null
  }
}
</script>

<template>
  <div>
    <VAlert
      v-if="errorMessage"
      type="error"
      class="mb-4"
      closable
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

    <VCard>
      <VCardText>
        <!-- Header -->
        <div class="d-flex justify-space-between align-center flex-wrap gap-4 mb-6">
          <div>
            <h5 class="text-h5">
              {{ t('companyModules.title') }}
            </h5>
            <div class="text-body-1">
              {{ t('companyModules.subtitle') }}
            </div>
          </div>
        </div>

        <!-- Loading -->
        <div
          v-if="isLoading"
          class="text-center pa-8"
        >
          <VProgressCircular indeterminate />
        </div>

        <template v-else>
          <!-- Tabs -->
          <VTabs
            v-model="currentTab"
            class="mb-6"
          >
            <VTab :value="0">
              {{ t('companyModules.tabAddons') }}
              <VChip
                v-if="addonModulesAll.length"
                size="x-small"
                class="ms-2"
                color="info"
                variant="tonal"
              >
                {{ addonModulesAll.length }}
              </VChip>
            </VTab>
            <VTab :value="1">
              {{ t('companyModules.tabIncluded') }}
              <VChip
                v-if="includedModules.length"
                size="x-small"
                class="ms-2"
                color="primary"
                variant="tonal"
              >
                {{ includedModules.length }}
              </VChip>
            </VTab>
            <VTab :value="2">
              {{ t('companyModules.tabActive') }}
              <VChip
                v-if="activeModules.length"
                size="x-small"
                class="ms-2"
                color="success"
                variant="tonal"
              >
                {{ activeModules.length }}
              </VChip>
            </VTab>
            <VTab :value="3">
              {{ t('companyModules.tabAll') }}
            </VTab>
          </VTabs>

          <VWindow
            v-model="currentTab"
            class="disable-tab-transition"
          >
            <!-- Tab 0: Add-ons -->
            <VWindowItem :value="0">
              <div
                v-if="addonModulesAll.length"
                class="search-wrap mb-6"
              >
                <AppTextField
                  v-model="addonSearchQuery"
                  prepend-inner-icon="tabler-search"
                  clearable
                />
                <div
                  v-if="!addonSearchQuery && currentPlaceholderModule"
                  class="search-placeholder"
                >
                  <span class="search-placeholder-static">{{ t('companyModules.searchModuleHint') }} :</span>
                  <span class="search-placeholder-slot">
                    <Transition
                      name="slide-v"
                      mode="out-in"
                    >
                      <span :key="placeholderIdx">
                        <VIcon
                          :icon="currentPlaceholderModule.icon_name || 'tabler-puzzle'"
                          size="18"
                        />
                        {{ currentPlaceholderModule.name }}
                      </span>
                    </Transition>
                  </span>
                </div>
              </div>

              <VRow v-if="addonModules.length" class="card-grid card-grid-md">
                <VCol
                  v-for="mod in addonModules"
                  :key="mod.key"
                  cols="12"
                  sm="6"
                  md="4"
                >
                  <!-- Module Card -->
                  <VCard
                    flat
                    border
                    class="d-flex flex-column"
                  >
                    <VCardText class="flex-grow-1">
                      <div class="d-flex justify-space-between align-center mb-4">
                        <VAvatar
                          size="42"
                          color="info"
                          variant="tonal"
                        >
                          <VIcon
                            :icon="mod.icon_name || 'tabler-puzzle'"
                            size="22"
                          />
                        </VAvatar>
                        <VChip
                          :color="categoryChip(mod).color"
                          size="small"
                          variant="tonal"
                        >
                          {{ categoryChip(mod).text }}
                        </VChip>
                      </div>

                      <h6 class="text-h6 mb-1">
                        {{ mod.name }}
                        <VChip
                          v-if="mod.is_featured"
                          size="x-small"
                          color="warning"
                          class="ms-1"
                        >
                          Featured
                        </VChip>
                      </h6>
                      <p class="text-body-2 text-medium-emphasis mb-3">
                        {{ mod.description || '—' }}
                      </p>

                      <!-- ADR-210: Pricing + dependencies -->
                      <div
                        v-if="modulePriceLabel(mod)"
                        class="mt-3"
                      >
                        <span class="text-subtitle-2 font-weight-bold">
                          {{ modulePriceLabel(mod) }}
                        </span>
                      </div>

                      <template v-if="mod.requires?.length">
                        <div
                          v-for="reqKey in mod.requires"
                          :key="reqKey"
                          class="d-flex align-center gap-1 mt-2"
                        >
                          <VIcon
                            :icon="dependencyLabel(reqKey)?.icon || 'tabler-plus'"
                            size="14"
                            :color="dependencyLabel(reqKey)?.color || 'secondary'"
                          />
                          <span class="text-caption text-medium-emphasis">
                            {{ dependencyLabel(reqKey)?.name }} —
                            <span class="font-weight-medium">{{ dependencyLabel(reqKey)?.label }}</span>
                          </span>
                        </div>
                      </template>

                      <div
                        v-if="dependentNames(mod)"
                        class="d-flex align-center gap-1 mt-2"
                      >
                        <VIcon
                          icon="tabler-sitemap"
                          size="16"
                          color="info"
                        />
                        <span class="text-caption text-medium-emphasis">
                          {{ t('companyModules.requiredByModules', { modules: dependentNames(mod) }) }}
                        </span>
                      </div>
                    </VCardText>

                    <VCardActions class="px-4 pb-4 pt-0">
                      <!-- Available → Activate -->
                      <VBtn
                        v-if="mod.display_state === 'available' && canManage"
                        v-can="'modules.manage'"
                        color="primary"
                        variant="tonal"
                        class="flex-grow-1"
                        :loading="togglingKey === mod.key"
                        @click="activateModule(mod)"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-power" />
                        </template>
                        {{ t('companyModules.activate') }}
                      </VBtn>

                      <!-- Locked plan → Upgrade CTA -->
                      <VBtn
                        v-else-if="mod.display_state === 'locked_plan'"
                        v-can="'modules.manage'"
                        color="warning"
                        variant="tonal"
                        class="flex-grow-1"
                        @click="navigateToPlanUpgrade(mod)"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-lock" />
                        </template>
                        {{ t('companyModules.upgradeTo', { plan: mod.upgrade_target_plan }) }}
                      </VBtn>

                      <!-- Locked addon → Purchase -->
                      <VBtn
                        v-else-if="mod.display_state === 'locked_addon'"
                        v-can="'modules.manage'"
                        color="warning"
                        variant="tonal"
                        class="flex-grow-1"
                        :loading="togglingKey === mod.key"
                        @click="activateModule(mod)"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-shopping-cart" />
                        </template>
                        {{ t('companyModules.purchaseAddon') }}
                      </VBtn>

                      <!-- Contact sales -->
                      <VBtn
                        v-else-if="mod.display_state === 'contact_sales'"
                        color="secondary"
                        variant="tonal"
                        class="flex-grow-1"
                        disabled
                      >
                        <template #prepend>
                          <VIcon icon="tabler-mail" />
                        </template>
                        {{ t('companyModules.contactSales') }}
                      </VBtn>
                    </VCardActions>
                  </VCard>
                </VCol>
              </VRow>

              <div
                v-else
                class="text-center pa-8"
              >
                <h6 class="text-h6 text-disabled">
                  {{ addonSearchQuery ? t('companyModules.noSearchResults') : t('companyModules.noModulesInTab') }}
                </h6>
              </div>
            </VWindowItem>

            <!-- Tab 1: Included -->
            <VWindowItem :value="1">
              <VRow v-if="includedModules.length" class="card-grid card-grid-md">
                <VCol
                  v-for="mod in includedModules"
                  :key="mod.key"
                  cols="12"
                  sm="6"
                  md="4"
                >
                  <VCard
                    flat
                    border
                    class="d-flex flex-column"
                  >
                    <VCardText class="flex-grow-1">
                      <div class="d-flex justify-space-between align-center mb-4">
                        <VAvatar
                          size="42"
                          color="primary"
                          variant="tonal"
                        >
                          <VIcon
                            :icon="mod.icon_name || 'tabler-puzzle'"
                            size="22"
                          />
                        </VAvatar>
                        <VChip
                          :color="categoryChip(mod).color"
                          size="small"
                          variant="tonal"
                        >
                          {{ categoryChip(mod).text }}
                        </VChip>
                      </div>

                      <h6 class="text-h6 mb-1">
                        {{ mod.name }}
                      </h6>
                      <p class="text-body-2 text-medium-emphasis mb-3">
                        {{ mod.description || '—' }}
                      </p>

                      <VChip
                        color="primary"
                        size="small"
                      >
                        {{ t('companyModules.stateIncluded') }}
                      </VChip>

                      <!-- ADR-210: Dependencies (included tab) -->
                      <template v-if="mod.requires?.length">
                        <div
                          v-for="reqKey in mod.requires"
                          :key="reqKey"
                          class="d-flex align-center gap-1 mt-2"
                        >
                          <VIcon
                            icon="tabler-check"
                            size="14"
                            color="success"
                          />
                          <span class="text-caption text-medium-emphasis">
                            {{ dependencyLabel(reqKey)?.name }} — {{ t('companyModules.depIncluded') }}
                          </span>
                        </div>
                      </template>

                      <div
                        v-if="dependentNames(mod)"
                        class="d-flex align-center gap-1 mt-2"
                      >
                        <VIcon
                          icon="tabler-sitemap"
                          size="16"
                          color="info"
                        />
                        <span class="text-caption text-medium-emphasis">
                          {{ t('companyModules.requiredByModules', { modules: dependentNames(mod) }) }}
                        </span>
                      </div>
                    </VCardText>

                    <VCardActions class="px-4 pb-4 pt-0">
                      <VBtn
                        v-if="mod.capabilities?.settings_panels?.length"
                        variant="tonal"
                        class="flex-grow-1"
                        :to="{ name: 'company-modules-key', params: { key: mod.key } }"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-settings" />
                        </template>
                        {{ t('companyModules.configure') }}
                      </VBtn>
                    </VCardActions>
                  </VCard>
                </VCol>
              </VRow>

              <div
                v-else
                class="text-center pa-8"
              >
                <h6 class="text-h6 text-disabled">
                  {{ t('companyModules.noModulesInTab') }}
                </h6>
              </div>
            </VWindowItem>

            <!-- Tab 2: Active -->
            <VWindowItem :value="2">
              <VRow v-if="activeModules.length" class="card-grid card-grid-md">
                <VCol
                  v-for="mod in activeModules"
                  :key="mod.key"
                  cols="12"
                  sm="6"
                  md="4"
                >
                  <VCard
                    flat
                    border
                    class="d-flex flex-column"
                  >
                    <VCardText class="flex-grow-1">
                      <div class="d-flex justify-space-between align-center mb-4">
                        <VAvatar
                          size="42"
                          color="success"
                          variant="tonal"
                        >
                          <VIcon
                            :icon="mod.icon_name || 'tabler-puzzle'"
                            size="22"
                          />
                        </VAvatar>
                        <VChip
                          :color="categoryChip(mod).color"
                          size="small"
                          variant="tonal"
                        >
                          {{ categoryChip(mod).text }}
                        </VChip>
                      </div>

                      <h6 class="text-h6 mb-1">
                        {{ mod.name }}
                      </h6>
                      <p class="text-body-2 text-medium-emphasis mb-3">
                        {{ mod.description || '—' }}
                      </p>

                      <VChip
                        color="success"
                        size="small"
                      >
                        {{ t('companyModules.stateActive') }}
                      </VChip>

                      <!-- ADR-210: Active dependencies -->
                      <template v-if="mod.requires?.length">
                        <div
                          v-for="reqKey in mod.requires"
                          :key="reqKey"
                          class="d-flex align-center gap-1 mt-2"
                        >
                          <VIcon
                            icon="tabler-check"
                            size="14"
                            color="success"
                          />
                          <span class="text-caption text-medium-emphasis">
                            {{ dependencyLabel(reqKey)?.name }} — {{ dependencyLabel(reqKey)?.label }}
                          </span>
                        </div>
                      </template>

                      <div
                        v-if="dependentNames(mod)"
                        class="d-flex align-center gap-1 mt-2"
                      >
                        <VIcon
                          icon="tabler-sitemap"
                          size="16"
                          color="info"
                        />
                        <span class="text-caption text-medium-emphasis">
                          {{ t('companyModules.requiredByModules', { modules: dependentNames(mod) }) }}
                        </span>
                      </div>
                    </VCardText>

                    <VCardActions class="px-4 pb-4 pt-0">
                      <VBtn
                        v-if="mod.capabilities?.settings_panels?.length"
                        variant="tonal"
                        class="flex-grow-1"
                        :to="{ name: 'company-modules-key', params: { key: mod.key } }"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-settings" />
                        </template>
                        {{ t('companyModules.configure') }}
                      </VBtn>

                      <VBtn
                        v-if="canManage && mod.type !== 'core'"
                        v-can="'modules.manage'"
                        variant="text"
                        color="secondary"
                        size="small"
                        :loading="togglingKey === mod.key"
                        @click="requestDeactivation(mod)"
                      >
                        {{ t('companyModules.deactivate') }}
                      </VBtn>
                    </VCardActions>
                  </VCard>
                </VCol>
              </VRow>

              <div
                v-else
                class="text-center pa-8"
              >
                <h6 class="text-h6 text-disabled">
                  {{ t('companyModules.noModulesInTab') }}
                </h6>
              </div>
            </VWindowItem>

            <!-- Tab 3: All (with filters) -->
            <VWindowItem :value="3">
              <div class="search-wrap mb-4">
                <AppTextField
                  v-model="searchQuery"
                  prepend-inner-icon="tabler-search"
                  clearable
                />
                <div
                  v-if="!searchQuery && currentPlaceholderModule"
                  class="search-placeholder"
                >
                  <span class="search-placeholder-static">{{ t('companyModules.searchModuleHint') }} :</span>
                  <span class="search-placeholder-slot">
                    <Transition
                      name="slide-v"
                      mode="out-in"
                    >
                      <span :key="placeholderIdx">
                        <VIcon
                          :icon="currentPlaceholderModule.icon_name || 'tabler-puzzle'"
                          size="18"
                        />
                        {{ currentPlaceholderModule.name }}
                      </span>
                    </Transition>
                  </span>
                </div>
              </div>
              <div class="d-flex flex-wrap gap-x-4 gap-y-4 align-center mb-6">
                <AppSelect
                  v-model="categoryFilter"
                  :items="categoryItems"
                  style="min-inline-size: 180px;"
                />
              </div>

              <VRow v-if="allFilteredModules.length" class="card-grid card-grid-md">
                <VCol
                  v-for="mod in allFilteredModules"
                  :key="mod.key"
                  cols="12"
                  sm="6"
                  md="4"
                >
                  <VCard
                    flat
                    border
                    class="d-flex flex-column"
                  >
                    <VCardText class="flex-grow-1">
                      <div class="d-flex justify-space-between align-start mb-4">
                        <VAvatar
                          size="42"
                          :color="mod.type === 'core' ? 'primary' : 'info'"
                          variant="tonal"
                        >
                          <VIcon
                            :icon="mod.icon_name || 'tabler-puzzle'"
                            size="22"
                          />
                        </VAvatar>
                        <div class="d-flex flex-column align-end gap-1">
                          <VChip
                            :color="categoryChip(mod).color"
                            size="small"
                            variant="tonal"
                          >
                            {{ categoryChip(mod).text }}
                          </VChip>
                          <VChip
                            :color="stateChip(mod).color"
                            size="small"
                            variant="tonal"
                          >
                            {{ stateChip(mod).text }}
                          </VChip>
                        </div>
                      </div>

                      <h6
                        class="text-h6 mb-1"
                        :class="{ 'text-disabled': isLocked(mod) }"
                      >
                        {{ mod.name }}
                      </h6>
                      <p
                        class="text-body-2 text-medium-emphasis mb-3"
                        :class="{ 'text-disabled': isLocked(mod) }"
                      >
                        {{ mod.description || '—' }}
                      </p>

                      <!-- ADR-210: Pricing + dependencies -->
                      <div
                        v-if="modulePriceLabel(mod)"
                        class="mt-3"
                      >
                        <span
                          class="text-subtitle-2 font-weight-bold"
                          :class="{ 'text-disabled': isLocked(mod) }"
                        >
                          {{ modulePriceLabel(mod) }}
                        </span>
                      </div>

                      <template v-if="mod.requires?.length">
                        <div
                          v-for="reqKey in mod.requires"
                          :key="reqKey"
                          class="d-flex align-center gap-1 mt-2"
                        >
                          <VIcon
                            :icon="dependencyLabel(reqKey)?.icon || 'tabler-plus'"
                            size="14"
                            :color="dependencyLabel(reqKey)?.color || 'secondary'"
                          />
                          <span class="text-caption text-medium-emphasis">
                            {{ dependencyLabel(reqKey)?.name }} —
                            <span class="font-weight-medium">{{ dependencyLabel(reqKey)?.label }}</span>
                          </span>
                        </div>
                      </template>

                      <div
                        v-if="dependentNames(mod)"
                        class="d-flex align-center gap-1 mt-2"
                      >
                        <VIcon
                          icon="tabler-sitemap"
                          size="16"
                          color="info"
                        />
                        <span class="text-caption text-medium-emphasis">
                          {{ t('companyModules.requiredByModules', { modules: dependentNames(mod) }) }}
                        </span>
                      </div>
                    </VCardText>

                    <VCardActions class="px-4 pb-4 pt-0">
                      <!-- Included / Active → Configure (only if settings panels exist) -->
                      <VBtn
                        v-if="(mod.display_state === 'included' || mod.display_state === 'active') && mod.capabilities?.settings_panels?.length"
                        variant="tonal"
                        class="flex-grow-1"
                        :to="{ name: 'company-modules-key', params: { key: mod.key } }"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-settings" />
                        </template>
                        {{ t('companyModules.configure') }}
                      </VBtn>

                      <!-- Available → Activate -->
                      <VBtn
                        v-else-if="mod.display_state === 'available' && canManage"
                        v-can="'modules.manage'"
                        color="primary"
                        variant="tonal"
                        class="flex-grow-1"
                        :loading="togglingKey === mod.key"
                        @click="activateModule(mod)"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-power" />
                        </template>
                        {{ t('companyModules.activate') }}
                      </VBtn>

                      <!-- Locked plan -->
                      <VBtn
                        v-else-if="mod.display_state === 'locked_plan'"
                        v-can="'modules.manage'"
                        color="warning"
                        variant="tonal"
                        class="flex-grow-1"
                        @click="navigateToPlanUpgrade(mod)"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-lock" />
                        </template>
                        {{ t('companyModules.upgradeTo', { plan: mod.upgrade_target_plan }) }}
                      </VBtn>

                      <!-- Locked addon -->
                      <VBtn
                        v-else-if="mod.display_state === 'locked_addon'"
                        v-can="'modules.manage'"
                        color="warning"
                        variant="tonal"
                        class="flex-grow-1"
                        :loading="togglingKey === mod.key"
                        @click="activateModule(mod)"
                      >
                        <template #prepend>
                          <VIcon icon="tabler-shopping-cart" />
                        </template>
                        {{ t('companyModules.purchaseAddon') }}
                      </VBtn>

                      <!-- Contact sales -->
                      <VBtn
                        v-else-if="mod.display_state === 'contact_sales'"
                        color="secondary"
                        variant="tonal"
                        class="flex-grow-1"
                        disabled
                      >
                        <template #prepend>
                          <VIcon icon="tabler-mail" />
                        </template>
                        {{ t('companyModules.contactSales') }}
                      </VBtn>

                      <!-- Active → Deactivate (secondary) -->
                      <VBtn
                        v-if="mod.display_state === 'active' && canManage && mod.type !== 'core'"
                        v-can="'modules.manage'"
                        variant="text"
                        color="secondary"
                        size="small"
                        :loading="togglingKey === mod.key"
                        @click="requestDeactivation(mod)"
                      >
                        {{ t('companyModules.deactivate') }}
                      </VBtn>
                    </VCardActions>
                  </VCard>
                </VCol>
              </VRow>

              <div
                v-else
                class="text-center pa-8"
              >
                <h6 class="text-h6 text-disabled">
                  {{ t('companyModules.noModulesAvailable') }}
                </h6>
              </div>
            </VWindowItem>
          </VWindow>
        </template>
      </VCardText>
    </VCard>

    <!-- Deactivation confirmation dialog -->
    <VDialog
      v-model="deactivateDialog"
      max-width="420"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center pt-5 px-6">
          <VIcon
            icon="tabler-alert-triangle"
            color="warning"
            class="me-2"
          />
          {{ t('companyModules.deactivateConfirmTitle') }}
        </VCardTitle>

        <VCardText class="px-6">
          <p class="text-body-1">
            {{ t('companyModules.deactivateConfirmMessage', { module: deactivateTarget?.name }) }}
          </p>

          <!-- ADR-341: Deactivation preview — loaded from backend -->
          <VSkeletonLoader
            v-if="deactivatePreviewLoading"
            type="list-item-three-line"
            class="mt-3"
          />

          <!-- Addon billing breakdown -->
          <template v-else-if="deactivatePreview?.has_addon">
            <!-- Timing badge -->
            <VAlert
              :type="deactivatePreview.timing === 'immediate' ? 'warning' : 'info'"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              <div class="text-body-2 font-weight-medium">
                {{ deactivatePreview.timing === 'immediate'
                  ? t('companyModules.deactivateImmediate')
                  : t('companyModules.deactivateActiveUntil', { date: formatPeriodEnd(deactivatePreview.active_until) })
                }}
              </div>
            </VAlert>

            <!-- Billing breakdown table -->
            <VTable
              density="compact"
              class="mt-3 text-body-2 billing-breakdown"
            >
              <tbody>
                <!-- Period -->
                <tr>
                  <td class="text-disabled">
                    {{ t('companyModules.previewPeriod') }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ formatPeriodEnd(deactivatePreview.period_start) }} → {{ formatPeriodEnd(deactivatePreview.period_end) }}
                  </td>
                </tr>

                <!-- Days used / total -->
                <tr>
                  <td class="text-disabled">
                    {{ t('companyModules.previewDaysUsed') }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ deactivatePreview.days_used }} / {{ deactivatePreview.total_days }} {{ t('companyModules.previewDays') }}
                  </td>
                </tr>

                <!-- Amount paid -->
                <tr>
                  <td class="text-disabled">
                    {{ t('companyModules.previewAmountPaid') }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ formatMoney(deactivatePreview.amount_paid_ht, { currency: deactivatePreview.currency }) }} {{ t('companyModules.previewHT') }}
                  </td>
                </tr>

                <!-- Consumed -->
                <tr>
                  <td class="text-disabled">
                    {{ t('companyModules.previewConsumed') }}
                  </td>
                  <td class="text-end font-weight-medium">
                    {{ formatMoney(deactivatePreview.consumed_ht, { currency: deactivatePreview.currency }) }} {{ t('companyModules.previewHT') }}
                    <span class="text-disabled ms-1">({{ deactivatePreview.days_used }} j.)</span>
                  </td>
                </tr>

                <!-- Separator -->
                <tr v-if="deactivatePreview.timing === 'immediate' && deactivatePreview.credit_ht > 0">
                  <td colspan="2">
                    <VDivider />
                  </td>
                </tr>

                <!-- Credit (immediate only) -->
                <tr
                  v-if="deactivatePreview.timing === 'immediate' && deactivatePreview.credit_ht > 0"
                  class="text-success"
                >
                  <td class="font-weight-bold">
                    {{ t('companyModules.previewCredit') }}
                  </td>
                  <td class="text-end font-weight-bold">
                    + {{ formatMoney(deactivatePreview.credit_ht, { currency: deactivatePreview.currency }) }} {{ t('companyModules.previewHT') }}
                  </td>
                </tr>

                <!-- Credit TTC (if tax exclusive) -->
                <tr
                  v-if="deactivatePreview.timing === 'immediate' && deactivatePreview.credit_ht > 0 && deactivatePreview.tax_rate_bps > 0"
                  class="text-success"
                >
                  <td class="text-disabled font-weight-regular">
                    {{ t('companyModules.previewCreditTTC') }}
                  </td>
                  <td class="text-end text-disabled font-weight-regular">
                    + {{ formatMoney(deactivatePreview.credit_ttc, { currency: deactivatePreview.currency }) }} {{ t('companyModules.previewTTC') }}
                  </td>
                </tr>
              </tbody>
            </VTable>

            <!-- Wallet impact -->
            <VAlert
              v-if="deactivatePreview.timing === 'immediate' && deactivatePreview.credit_ht > 0"
              type="success"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              <div class="text-body-2">
                {{ t('companyModules.previewWalletCredit', {
                  amount: formatMoney(deactivatePreview.credit_ht, { currency: deactivatePreview.currency }),
                  balance: formatMoney(deactivatePreview.wallet_balance_after, { currency: deactivatePreview.currency }),
                }) }}
              </div>
            </VAlert>

            <!-- No refund (end_of_period) -->
            <VAlert
              v-if="deactivatePreview.timing === 'end_of_period'"
              type="info"
              variant="tonal"
              density="compact"
              class="mt-3"
            >
              <div class="text-body-2">
                {{ t('companyModules.deactivateNoRefund') }}
              </div>
            </VAlert>
          </template>

          <!-- Non-addon modules (included in plan) -->
          <VAlert
            v-else-if="deactivatePreview && !deactivatePreview.has_addon"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            <div class="text-body-2">
              {{ t('companyModules.deactivateIncludedInPlan') }}
            </div>
          </VAlert>

          <VAlert
            v-if="deactivateTarget?.dependents?.length"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            {{ t('companyModules.deactivateWillAffect', { modules: dependentNames(deactivateTarget) }) }}
          </VAlert>
        </VCardText>

        <VCardActions class="px-6 pb-5">
          <VSpacer />
          <VBtn
            color="secondary"
            variant="tonal"
            @click="cancelDeactivation"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            v-can="'modules.manage'"
            color="error"
            variant="elevated"
            :loading="togglingKey === deactivateTarget?.key"
            @click="confirmDeactivation"
          >
            {{ t('companyModules.deactivate') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- ADR-341: Grace period reactivation dialog (no charge) -->
    <VDialog
      v-model="reactivateDialog"
      max-width="420"
    >
      <VCard>
        <VCardTitle>{{ t('companyModules.reactivateTitle', { module: reactivateModuleName }) }}</VCardTitle>
        <VCardText>
          <VAlert
            type="info"
            variant="tonal"
            class="mb-4"
          >
            {{ t('companyModules.reactivateNoCharge', { date: formatPeriodEnd(reactivateDeactivateDate) }) }}
          </VAlert>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="reactivateDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            v-can="'modules.manage'"
            color="primary"
            @click="confirmReactivation"
          >
            {{ t('companyModules.reactivateConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Quote confirmation dialog -->
    <VDialog
      v-model="quoteDialog"
      max-width="480"
      persistent
    >
      <VCard>
        <VCardTitle class="d-flex align-center pt-5 px-6">
          <VIcon
            icon="tabler-receipt"
            class="me-2"
          />
          {{ t('companyModules.quoteTitle', { module: quoteModuleName }) }}
        </VCardTitle>

        <VCardText class="px-6">
          <div
            v-if="quoteLoading"
            class="text-center py-4"
          >
            <VProgressCircular indeterminate />
          </div>

          <template v-else-if="quoteData">
            <!-- ADR-340: Line items HT -->
            <div
              v-for="line in quoteData.lines"
              :key="line.key"
              class="d-flex justify-space-between align-center py-1"
            >
              <span class="text-body-1">{{ line.title }}</span>
              <span class="text-body-1 font-weight-medium">
                {{ formatMoney(line.amount, { currency: quoteData.currency }) }}/{{ t('companyModules.quoteMonth') }}
              </span>
            </div>

            <VDivider class="my-3" />

            <!-- ADR-340: Tax breakdown -->
            <div class="d-flex justify-space-between align-center py-1">
              <span class="text-body-2 text-medium-emphasis">{{ t('companyPlan.planChangePreview.subtotalHT') }}</span>
              <span class="text-body-2">
                {{ formatMoney(quoteData.subtotal, { currency: quoteData.currency }) }}
              </span>
            </div>

            <div
              v-if="quoteData.tax_amount > 0"
              class="d-flex justify-space-between align-center py-1"
            >
              <span class="text-body-2 text-medium-emphasis">
                {{ t('companyPlan.planChangePreview.tax') }} ({{ (quoteData.tax_rate_bps / 100).toFixed(1) }}%)
              </span>
              <span class="text-body-2">
                +{{ formatMoney(quoteData.tax_amount, { currency: quoteData.currency }) }}
              </span>
            </div>

            <VDivider class="my-2" />

            <div class="d-flex justify-space-between align-center">
              <span class="text-body-1 font-weight-bold">{{ t('companyPlan.planChangePreview.totalTTC') }}</span>
              <span class="text-body-1 font-weight-bold">
                {{ formatMoney(quoteData.total_ttc, { currency: quoteData.currency }) }}/{{ t('companyModules.quoteMonth') }}
              </span>
            </div>

            <!-- ADR-340: Charged immediately notice -->
            <VAlert
              type="info"
              variant="tonal"
              density="compact"
              class="mt-4"
            >
              {{ t('companyModules.quoteChargedImmediately') }}
            </VAlert>

            <!-- ADR-340: Included modules (no charge) -->
            <div
              v-if="quoteData.included.length"
              class="mt-4"
            >
              <span class="text-body-2 text-medium-emphasis">
                {{ t('companyModules.quoteIncludesNoCharge') }}
              </span>
              <div class="d-flex flex-wrap gap-1 mt-1">
                <VChip
                  v-for="inc in quoteData.included"
                  :key="inc.key"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ inc.title }}
                </VChip>
              </div>
            </div>
          </template>
        </VCardText>

        <VCardActions class="px-6 pb-5">
          <VSpacer />
          <VBtn
            color="secondary"
            variant="tonal"
            @click="quoteDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            v-can="'modules.manage'"
            color="primary"
            variant="elevated"
            :disabled="quoteLoading || !quoteData"
            @click="confirmQuoteEnable"
          >
            {{ t('companyModules.quoteConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
/* Animated search placeholder — slide from top, exit to bottom */
.search-wrap {
  position: relative;
}

.search-placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  padding-inline-start: 2.75rem;
  pointer-events: none;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  font-size: 1rem;
  white-space: nowrap;
  gap: 6px;
}

.search-placeholder-static {
  flex-shrink: 0;
}

.search-placeholder-slot {
  position: relative;
  overflow: hidden;
  display: inline-flex;
  align-items: center;
  block-size: 1.5em;
}

.search-placeholder-slot span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.slide-v-enter-active,
.slide-v-leave-active {
  transition: transform 0.3s ease, opacity 0.3s ease;
}

.slide-v-enter-from {
  transform: translateY(-100%);
  opacity: 0;
}

.slide-v-leave-to {
  transform: translateY(100%);
  opacity: 0;
}
</style>
