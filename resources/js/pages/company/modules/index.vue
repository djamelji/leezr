<script setup>
import { $api } from '@/utils/api'
definePage({ meta: { surface: 'structure', module: 'core.modules', permission: 'modules.manage' } })

import { useAuthStore } from '@/core/stores/auth'
import { useModuleStore } from '@/core/stores/module'
import { useAppToast } from '@/composables/useAppToast'
import { formatMoney } from '@/utils/money'
import { useRouter } from 'vue-router'

const { t, locale } = useI18n()
const auth = useAuthStore()
const moduleStore = useModuleStore()
const { toast } = useAppToast()
const router = useRouter()
const focusModuleKey = useRoute().query.module // ADR-839 : read in setup (inject is unavailable after an await)

const isLoading = ref(true)
const togglingKey = ref(null)
const errorMessage = ref('')

// Tab state
const currentTab = ref(0)
// ADR-821 — un seul catalogue filtré par état
const CATALOG_FILTERS = ['all', 'active', 'included', 'options', 'unavailable']
const catalogFilter = ref('all')
const FILTER_STATES = { active: ['active'], included: ['included'], options: ['available', 'locked_addon'], unavailable: ['locked_plan', 'contact_sales'] }
const matchesFilter = (m, f) => f === 'all' || (FILTER_STATES[f] || []).includes(m.display_state)
const filterCount = f => (moduleStore.modules || []).filter(m => matchesFilter(m, f)).length
const catalogModules = computed(() => {
  const q = (searchQuery.value || '').toLowerCase()
  const rank = { active: 0, included: 1, available: 2, locked_addon: 3, locked_plan: 4, contact_sales: 5 }

  return (moduleStore.modules || []).filter(m => matchesFilter(m, catalogFilter.value) && (!q || modName(m).toLowerCase().includes(q) || modDesc(m).toLowerCase().includes(q)))
    .sort((a, b) => (Number(isPreview(a)) - Number(isPreview(b))) || ((rank[a.display_state] ?? 9) - (rank[b.display_state] ?? 9)) || modName(a).localeCompare(modName(b)))
})

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
// ADR-807 — the real activation also activates (and bills) every INACTIVE requirement : the quote covers
// them, and the dialog separates requested / added / already active modules and states the deactivation rule.
const quoteAdded = ref([])
const quoteAlreadyActive = ref([])
const modByKey = k => moduleStore.modules.find(m => m.key === k)
const requiresClosure = key => {
  const seen = new Set()
  const walk = k => { for (const r of (modByKey(k)?.requires || [])) if (!seen.has(r)) { seen.add(r); walk(r) } }
  walk(key)

  return [...seen]
}
const quoteLine = k => quoteData.value?.lines?.find(l => l.key === k)
const quoteName = k => modName(modByKey(k) || { key: k })

// Deactivation confirmation dialog
const deactivateDialog = ref(false)
const deactivateImpact = ref(null) // ADR-805
const deactivateTarget = ref(null)
const deactivatePreview = ref(null)
const deactivatePreviewLoading = ref(false)

// ADR-341: Grace period reactivation dialog
const reactivateDialog = ref(false)
const reactivateModuleKey = ref(null)
const reactivateModuleName = ref('')
const reactivateDeactivateDate = ref(null)

const canManage = computed(() => auth.roleLevel === 'management')

// --- Contact sales dialog state ---
const showContactDialog = ref(false)
const contactModule = ref(null)
const contactForm = reactive({ email: '', message: '' })
const contactSending = ref(false)
const contactSent = ref(false)

const openContactDialog = mod => {
  contactModule.value = mod
  contactForm.email = auth.user?.email || ''
  contactForm.message = ''
  contactSent.value = false
  showContactDialog.value = true
}

const submitContact = () => {
  contactSending.value = true
  const subject = encodeURIComponent(`Demande module: ${contactModule.value?.name}`)
  const body = encodeURIComponent(`Email: ${contactForm.email}\n\nModule: ${contactModule.value?.name}\n\nMessage: ${contactForm.message}`)
  window.open(`mailto:contact@leezr.com?subject=${subject}&body=${body}`)
  contactSent.value = true
  contactSending.value = false
}

// --- Preview drawer state ---
const selectedModule = ref(null)
const showPreviewDrawer = ref(false)

const openPreviewDrawer = mod => {
  selectedModule.value = mod
  showPreviewDrawer.value = true
}

const previewContent = computed(() => {
  if (!selectedModule.value) return null
  const key = selectedModule.value.key

  return {
    tagline: t(`modulePreview.${key}.tagline`),
    benefits: [
      t(`modulePreview.${key}.benefits[0]`),
      t(`modulePreview.${key}.benefits[1]`),
      t(`modulePreview.${key}.benefits[2]`),
    ],
    example: t(`modulePreview.${key}.example`),
    valueProposition: t(`modulePreview.${key}.valueProposition`),
  }
})

const previewStatusChip = computed(() => {
  if (!selectedModule.value) return null
  const state = selectedModule.value.display_state
  if (state === 'included') return { text: t('modulePreview.included'), color: 'primary', icon: 'tabler-check' }
  if (state === 'active') return { text: t('modulePreview.alreadyActive'), color: 'success', icon: 'tabler-check' }
  if (['locked_plan', 'locked_addon'].includes(state)) return { text: t('modulePreview.upgradeRequired'), color: 'warning', icon: 'tabler-lock' }
  if (state === 'contact_sales') return { text: t('modulePreview.contactSales'), color: 'secondary', icon: 'tabler-mail' }
  if (selectedModule.value.pricing_mode === 'addon') return { text: t('modulePreview.addon'), color: 'info', icon: 'tabler-puzzle' }

  return { text: t('companyModules.stateAvailable'), color: 'info', icon: 'tabler-circle-check' }
})

onMounted(async () => {
  try {
    await moduleStore.fetchModules()

    // ADR-839 (M3) — arriving from « module not activated / not in your plan » : open that module's details
    const focus = moduleStore.modules.find(m => m.key === focusModuleKey)
    if (focus) openPreviewDrawer(focus)
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
  // ADR-783 (UX6.2) : offres activables d'abord, « Bientôt disponible » ensuite.
  const ordered = [...addonModulesAll.value].sort((a, b) => Number(a?.commercial_status === 'preview') - Number(b?.commercial_status === 'preview'))
  if (!addonSearchQuery.value) return ordered
  const q = addonSearchQuery.value.toLowerCase()

  return ordered.filter(m => modName(m).toLowerCase().includes(q))
})

const includedModules = computed(() =>
  moduleStore.modules.filter(m => m.display_state === 'included'),
)

// ADR-782 (UX6.1) — produits payants réellement souscrits (vérité Billing, ADR-621), lecture seule.
const subscribedCount = computed(() => moduleStore.modules.filter(m => m.is_subscribed).length)

const activeModules = computed(() =>
  moduleStore.modules.filter(m => m.display_state === 'active'),
)

const allFilteredModules = computed(() => {
  return moduleStore.modules.filter(m => {
    if (categoryFilter.value !== 'all' && m.category !== categoryFilter.value)
      return false
    if (searchQuery.value && !modName(m).toLowerCase().includes(searchQuery.value.toLowerCase()))
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

// ADR-783 (UX6.2) — noms et descriptions traduits par clé (repli : texte du catalogue Platform).
const { te } = useI18n()
const catKey = m => String(m?.key || '').replace(/\./g, '_')
const modName = m => (m && te(`moduleCatalog.${catKey(m)}.name`) ? t(`moduleCatalog.${catKey(m)}.name`) : (m?.name || ''))
const modDesc = m => (m && te(`moduleCatalog.${catKey(m)}.description`) ? t(`moduleCatalog.${catKey(m)}.description`) : (m?.description || ''))

// ADR-732 (P0-A) — module « En préparation » : déclaré mais non commercialisable → jamais d'achat/prix.
const isPreview = module => module?.commercial_status === 'preview'

const stateChip = module => {
  if (isPreview(module)) return { text: t('companyModules.statePreparing'), color: 'secondary' }
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
  if (isPreview(mod)) return null // ADR-732 : aucun prix commandable pour un module en préparation
  if (['included', 'active'].includes(mod.display_state)) return null
  if (!mod.addon_pricing) return t('companyModules.pricingContactSales')

  const { pricing_model, pricing_params, pricing_metric } = mod.addon_pricing
  const planKey = moduleStore.companyPlanKey || 'starter'

  if (pricing_model === 'flat') {
    const price = pricing_params?.price_monthly
    return price != null ? `${price} \u20AC HT/${t('companyModules.perMonth')}` : null
  }

  if (pricing_model === 'plan_flat') {
    const price = pricing_params?.[planKey]
    return price != null ? `${price} \u20AC HT/${t('companyModules.perMonth')}` : null
  }

  if (pricing_model === 'per_seat') {
    const metricLabel = t(`companyModules.metric_${pricing_metric || 'users'}`)
    // ADR-818 : accord du nombre (« 5 salariés inclus », jamais « 5 salarié inclus »).
    const metricsFor = n => (n > 1 ? t(`companyModules.metricPlural_${pricing_metric || 'users'}`) : metricLabel)
    const included = pricing_params?.included
    const overage = pricing_params?.overage_unit_price

    // Plan-based included seats + overage
    if (included && typeof included === 'object') {
      const qty = included[planKey] ?? Object.values(included)[0] ?? 0
      const overagePrice = overage?.[planKey] ?? overage ?? null
      if (overagePrice != null) {
        return t('companyModules.pricingPerSeatIncluded', { count: qty, price: overagePrice, metric: metricLabel, metrics: metricsFor(qty) })
      }

      return t('companyModules.pricingPerSeatBase', { count: qty, metric: metricLabel, metrics: metricsFor(qty) })
    }

    // Simple per-unit
    const price = pricing_params?.price_per_unit
    return price != null ? `${price} \u20AC HT/${metricLabel}` : null
  }

  if (pricing_model === 'usage') {
    const metricLabel = t(`companyModules.metric_${pricing_metric || 'units'}`)
    const price = pricing_params?.price_per_unit
    return price != null ? `${price} \u20AC HT/${metricLabel}` : null
  }

  if (pricing_model === 'tiered') {
    return t('companyModules.pricingTiered')
  }

  return null
}

// ADR-624 (LOT 6g): for a SUBSCRIBED paid addon, show its FROZEN contractual price (snapshot, HT),
// which may differ from the current catalog price. Distinct from modulePriceLabel (catalog).
const contractualPriceLabel = mod => {
  if (!mod.is_subscribed || mod.contracted_amount_cents == null) return null

  const period = mod.subscribed_interval === 'yearly'
    ? t('common.annually').toLowerCase()
    : t('companyModules.perMonth')

  return `${formatMoney(mod.contracted_amount_cents)} ${t('companyModules.previewHT')}/${period}`
}

const dependencyLabel = reqKey => {
  const dep = moduleStore.modules.find(m => m.key === reqKey)
  if (!dep) return null

  if (['included', 'active'].includes(dep.display_state))
    return { name: modName(dep), label: t('companyModules.depIncluded'), color: 'success', icon: 'tabler-check' }

  const price = modulePriceLabel(dep)
  if (price)
    return { name: modName(dep), label: price, color: 'warning', icon: 'tabler-plus' }

  return { name: modName(dep), label: t('companyModules.pricingContactSales'), color: 'secondary', icon: 'tabler-plus' }
}

const dependentNames = mod => {
  if (!mod.dependents?.length) return null
  return mod.dependents
    .map(k => modName(moduleStore.modules.find(m => m.key === k) || { key: k, name: k })) // ADR-818 : un module = un nom
    .join(', ')
}

// --- Actions ---

const formatPeriodEnd = dateStr => {
  if (!dateStr) return ''

  // ADR-818 : dates dans la langue de l'interface (le dialogue de désactivation affichait l'anglais).
  return new Date(dateStr).toLocaleDateString(locale.value, {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
  })
}

const activateModule = async module => {
  errorMessage.value = ''

  const closure = requiresClosure(module.key)
  const missing = closure.filter(k => !modByKey(k)?.is_active)
  const paidMissing = missing.some(k => modByKey(k)?.pricing_mode === 'addon')

  // Addon pricing (requested module OR one of its inactive requirements) → check grace period, then fetch quote
  if (module.pricing_mode === 'addon' || paidMissing) {
    // ADR-341: Check if addon is in grace period (deactivated_at in the future)
    const addonSub = module.pricing_mode === 'addon' ? moduleStore.getAddonSub(module.key) : null
    if (addonSub?.deactivated_at && new Date(addonSub.deactivated_at) > new Date()) {
      reactivateModuleKey.value = module.key
      reactivateModuleName.value = modName(module)
      reactivateDeactivateDate.value = addonSub.deactivated_at
      reactivateDialog.value = true

      return
    }

    quoteModuleKey.value = module.key
    quoteModuleName.value = modName(module)
    quoteAdded.value = missing
    quoteAlreadyActive.value = closure.filter(k => modByKey(k)?.is_active)
    quoteLoading.value = true
    quoteDialog.value = true
    quoteData.value = null

    try {
      quoteData.value = await moduleStore.fetchQuote([module.key, ...missing])
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

  // ADR-821 — modules sans achat : aperçu serveur (dépendances ajoutées, blocages commerciaux), puis confirmation.
  activationTarget.value = module
  activationPreview.value = null
  activationDialog.value = true
  try { activationPreview.value = await $api(`/modules/${module.key}/activation-preview`) }
  catch (error) { activationDialog.value = false; errorMessage.value = error?.data?.message || t('companyModules.failedToToggle') }
}

// ── ADR-821 : aperçu d'activation et désactivation cohérente ──
const activationDialog = ref(false)
const activationTarget = ref(null)
const activationPreview = ref(null)
const confirmActivation = async () => {
  activationDialog.value = false
  if (activationTarget.value) await doToggle(activationTarget.value.key, false)
}
const deactivateCascade = ref(false)
const deactivateDependents = computed(() => deactivatePreview.value?.dependents || [])

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
  deactivateImpact.value = null
  deactivateDialog.value = true
  deactivateCascade.value = false
  // ADR-821 : modules actifs qui dépendent de celui-ci (et, pour une option payante, l'aperçu commercial)
  moduleStore.fetchDeactivationPreview(module.key).then(r => { deactivatePreview.value = { ...(deactivatePreview.value || {}), ...r } }).catch(() => {})
  // ADR-805 : processus en cours / documents émis concernés (lecture seule, la désactivation ne change pas)
  $api(`/modules/${module.key}/deactivation-impact`).then(r => { deactivateImpact.value = r }).catch(() => {})

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
    await doToggle(deactivateTarget.value.key, true, deactivateCascade.value)
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

const doToggle = async (key, isCurrentlyEnabled, cascade = false) => {
  togglingKey.value = key
  errorMessage.value = ''

  const mod = moduleStore.modules.find(m => m.key === key)
  const label = mod ? modName(mod) : key

  try {
    if (isCurrentlyEnabled) {
      await moduleStore.disableModule(key, cascade)
      toast(t('companyModules.deactivatedSuccess', { module: label }), 'success')
    }
    else {
      await moduleStore.enableModule(key)
      toast(t('companyModules.activatedSuccess', { module: label }), 'success')
      // ADR-803 : un module configurable enchaîne sur son assistant (qui peut faire quoi, réglages, confirmation).
      const enabled = moduleStore.modules.find(m => m.key === key)
      if (enabled?.setup_status && enabled.setup_status !== 'legacy') router.push(`/company/modules/${key}`)
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
    <SettingsReturnLink /> <!-- ADR-781 -->
    <VAlert
      v-if="errorMessage"
      type="error"
      class="mb-4"
      closable
      @click:close="errorMessage = ''"
    >
      {{ errorMessage }}
    </VAlert>

    <!-- ADR-821 — catalogue unifié (Vuexy) : un filtre d'état, une carte par module (objet manipulable),
         dépendances et droits commerciaux expliqués, actions selon l'état. -->
    <div class="d-flex flex-wrap align-center gap-4 mb-6">
      <div class="flex-grow-1">
        <h4 class="text-h4 mb-1">
          {{ t('companyModules.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('companyModules.subtitle') }}
        </p>
      </div>
      <div
        v-if="!isLoading"
        class="d-flex flex-wrap gap-2"
        data-test="modules-summary"
      >
        <VChip
          color="success"
          variant="tonal"
          label
        >
          {{ t('companyModules.summaryActive', { n: activeModules.length }) }}
        </VChip>
        <VChip
          color="primary"
          variant="tonal"
          label
        >
          {{ t('companyModules.summaryIncluded', { n: includedModules.length }) }}
        </VChip>
        <VChip
          v-if="subscribedCount"
          color="warning"
          variant="tonal"
          label
        >
          {{ t('companyModules.catalogSubscribed', { n: subscribedCount }) }}
        </VChip>
      </div>
    </div>

    <!-- Barre de recherche d'origine, avec son libellé animé (noms des modules qui défilent) -->
    <div class="search-wrap mb-4">
      <AppTextField
        v-model="searchQuery"
        prepend-inner-icon="tabler-search"
        clearable
        data-test="modules-search"
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
              {{ modName(currentPlaceholderModule) }}
            </span>
          </Transition>
        </span>
      </div>
    </div>

    <VCard class="mb-6">
      <VCardText class="d-flex flex-wrap align-center gap-4">
        <VChipGroup
          v-model="catalogFilter"
          mandatory
          selected-class="text-primary"
          data-test="modules-filter"
        >
          <VChip
            v-for="f in CATALOG_FILTERS"
            :key="f"
            :value="f"
            variant="tonal"
            label
            :data-test="`modules-filter-${f}`"
          >
            {{ t(`companyModules.catalogFilter.${f}`) }} ({{ filterCount(f) }})
          </VChip>
        </VChipGroup>
      </VCardText>
    </VCard>

    <VRow
      v-if="isLoading"
      class="card-grid card-grid-md"
    >
      <VCol
        v-for="i in 6"
        :key="i"
        cols="12"
        sm="6"
        lg="4"
      >
        <VSkeletonLoader type="card" />
      </VCol>
    </VRow>
    <VCard
      v-else-if="!catalogModules.length"
      data-test="modules-empty"
    >
      <VCardText class="text-center py-12 text-medium-emphasis">
        {{ t('companyModules.noModulesInTab') }}
      </VCardText>
    </VCard>
    <VRow
      v-else
      class="card-grid card-grid-md"
      data-test="modules-grid"
    >
      <VCol
        v-for="mod in catalogModules"
        :key="mod.key"
        cols="12"
        sm="6"
        lg="4"
      >
        <VCard
          class="d-flex flex-column"
          :data-test="`module-card-${mod.key}`"
        >
          <VCardItem>
            <template #prepend>
              <VAvatar
                :color="mod.is_active ? 'success' : 'primary'"
                variant="tonal"
                rounded
              >
                <VIcon :icon="mod.icon_name || 'tabler-puzzle'" />
              </VAvatar>
            </template>
            <VCardTitle class="text-wrap">
              {{ modName(mod) }}
            </VCardTitle>
            <VCardSubtitle>
              <VChip
                size="x-small"
                label
                variant="tonal"
                :color="stateChip(mod).color"
                :data-test="`module-state-${mod.key}`"
              >
                {{ stateChip(mod).text }}
              </VChip>
              <VChip
                v-if="mod.recommended_for_jobdomain && !mod.is_active"
                size="x-small"
                label
                color="primary"
                variant="outlined"
                prepend-icon="tabler-star"
                class="ms-1"
                :data-test="`module-recommended-${mod.key}`"
              >
                {{ t('companyModules.recommendedForYou') }}
              </VChip>
            </VCardSubtitle>
          </VCardItem>
          <VCardText class="flex-grow-1">
            <p class="text-body-2 mb-3">
              {{ modDesc(mod) || '—' }}
            </p>
            <div
              v-if="mod.requires?.length"
              class="text-body-2 mb-1"
              :data-test="`module-requires-${mod.key}`"
            >
              <span class="text-medium-emphasis">{{ t('companyModules.needsLabel') }}</span>
              <span
                v-for="(r, i) in mod.requires"
                :key="r"
              >
                {{ quoteName(r) }}<VIcon
                  v-if="modByKey(r)?.is_active"
                  icon="tabler-check"
                  size="14"
                  color="success"
                  class="ms-1"
                />{{ i < mod.requires.length - 1 ? ', ' : '' }}
              </span>
            </div>
            <div
              v-if="mod.is_active && dependentNames(mod)"
              class="text-body-2 text-medium-emphasis mb-1"
            >
              {{ t('companyModules.usedBy', { list: dependentNames(mod) }) }}
            </div>
            <div
              v-if="!mod.is_active && modulePriceLabel(mod)"
              class="text-body-2 font-weight-medium"
            >
              {{ modulePriceLabel(mod) }}
            </div>
            <div
              v-if="contractualPriceLabel(mod)"
              class="text-body-2 text-medium-emphasis"
            >
              {{ contractualPriceLabel(mod) }}
            </div>
            <!-- ADR-807 : un module inclus par l'activité ne se désactive pas ici -->
            <div
              v-if="mod.display_state === 'included' && mod.is_active"
              class="d-flex align-center gap-1 text-caption text-medium-emphasis mt-2"
              :title="t('companyModules.includedLocked')"
              :data-test="`module-included-locked-${mod.key}`"
            >
              <VIcon
                icon="tabler-lock"
                size="14"
              />
              <span>{{ t('companyModules.includedLockedShort') }}</span>
              <VTooltip
                activator="parent"
                location="top"
                max-width="320"
              >
                {{ t('companyModules.includedLocked') }}
              </VTooltip>
            </div>
            <!-- ADR-859 (L6, 05 §4) : « configured » reads as done (green + check), never as a grey « disabled » -->
            <VChip
              v-if="mod.is_active && mod.setup_status"
              size="small"
              class="mt-2"
              :prepend-icon="['ready', 'legacy'].includes(mod.setup_status) ? 'tabler-circle-check' : undefined"
              :color="['ready', 'legacy'].includes(mod.setup_status) ? 'success' : 'warning'"
              :to="`/company/modules/${mod.key}`"
              :data-test="`module-setup-chip-${mod.key}`"
            >
              {{ t(`moduleSetup.status.${mod.setup_status}`) }}
            </VChip>
          </VCardText>
          <VDivider />
          <VCardActions class="flex-wrap gap-2">
            <VBtn
              variant="text"
              size="small"
              :data-test="`module-details-${mod.key}`"
              @click="openPreviewDrawer(mod)"
            >
              {{ t('companyModules.details') }}
            </VBtn>
            <VSpacer />
            <template v-if="canManage">
              <VBtn
                v-if="mod.is_active"
                variant="tonal"
                size="small"
                :to="`/company/modules/${mod.key}`"
                :data-test="`module-configure-${mod.key}`"
              >
                {{ t('companyModules.configure') }}
              </VBtn>
              <!-- ADR-859 (L6, 05 §4) : rare and heavy action → in the « ⋯ » menu, same confirmation dialog with its impact -->
              <IconBtn
                v-if="mod.display_state === 'active'"
                size="small"
                :loading="togglingKey === mod.key"
                :aria-label="t('l6.modules.more', { module: mod.name || mod.key })"
                :data-test="`module-more-${mod.key}`"
              >
                <VIcon icon="tabler-dots-vertical" />
                <VMenu
                  activator="parent"
                  location="bottom end"
                >
                  <VList density="compact">
                    <VListItem
                      prepend-icon="tabler-power"
                      base-color="error"
                      :title="t('companyModules.deactivate')"
                      :data-test="`module-deactivate-${mod.key}`"
                      @click="requestDeactivation(mod)"
                    />
                  </VList>
                </VMenu>
              </IconBtn>
              <VBtn
                v-else-if="!mod.is_active && ['included', 'available'].includes(mod.display_state) && !isPreview(mod)"
                color="primary"
                size="small"
                :loading="togglingKey === mod.key"
                :data-test="`module-activate-${mod.key}`"
                @click="activateModule(mod)"
              >
                {{ t('companyModules.activate') }}
              </VBtn>
              <VBtn
                v-else-if="mod.display_state === 'locked_plan'"
                variant="tonal"
                color="warning"
                size="small"
                @click="navigateToPlanUpgrade(mod)"
              >
                {{ t('companyModules.upgradePlan') }}
              </VBtn>
              <VBtn
                v-else-if="mod.display_state === 'contact_sales'"
                variant="tonal"
                size="small"
                @click="openContactDialog(mod)"
              >
                {{ t('companyModules.contactSales') }}
              </VBtn>
            </template>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <!-- ADR-821 — aperçu d'activation : modules ajoutés automatiquement, blocages commerciaux -->
    <VDialog
      v-model="activationDialog"
      max-width="520"
    >
      <VCard data-test="activation-preview">
        <VCardItem>
          <VCardTitle>{{ t('companyModules.activationTitle', { module: modName(activationTarget) }) }}</VCardTitle>
        </VCardItem>
        <VCardText>
          <VSkeletonLoader
            v-if="!activationPreview"
            type="list-item-two-line"
          />
          <template v-else>
            <p
              v-for="(by, dep) in activationPreview.added"
              :key="dep"
              class="text-body-1"
              :data-test="`activation-added-${dep}`"
            >
              {{ t('companyModules.addedAutomatically', { modules: by.map(k => quoteName(k)).join(', '), dependency: quoteName(dep) }) }}
            </p>
            <p
              v-if="!Object.keys(activationPreview.added || {}).length && activationPreview.can_activate"
              class="text-body-1"
            >
              {{ t('companyModules.activationSimple', { module: modName(activationTarget) }) }}
            </p>
            <VList
              density="compact"
              class="py-0"
            >
              <VListItem
                v-for="k in activationPreview.to_activate"
                :key="k"
                prepend-icon="tabler-plus"
                :title="quoteName(k)"
                :subtitle="t('companyModules.willBeActivated')"
              />
              <VListItem
                v-for="k in activationPreview.already_active"
                :key="k"
                prepend-icon="tabler-check"
                :title="quoteName(k)"
                :subtitle="t('companyModules.alreadyActive')"
              />
            </VList>
            <VAlert
              v-for="b in activationPreview.blocked"
              :key="b.module"
              type="warning"
              variant="tonal"
              density="compact"
              class="mt-2"
              :data-test="`activation-blocked-${b.module}`"
            >
              {{ t(`companyModules.blocked.${b.reason}`, { module: quoteName(b.module) }) }}
            </VAlert>
          </template>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="activationDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            variant="elevated"
            :disabled="!activationPreview?.can_activate"
            :loading="togglingKey === activationTarget?.key"
            data-test="activation-confirm"
            @click="confirmActivation"
          >
            {{ t('companyModules.confirmActivation', { n: activationPreview?.to_activate?.length || 1 }) }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

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
            {{ t('companyModules.deactivateConfirmMessage', { module: modName(deactivateTarget) }) }}
          </p>
          <!-- ADR-821 : jamais un module actif privé de sa dépendance -->
          <VAlert
            v-if="deactivateDependents.length"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-3"
            data-test="deactivate-dependents"
          >
            {{ t('companyModules.dependentsWarning', { module: modName(deactivateTarget), list: deactivateDependents.map(k => quoteName(k)).join(', ') }) }}
            <VCheckbox
              v-model="deactivateCascade"
              :label="t('companyModules.dependentsCascade', { list: deactivateDependents.map(k => quoteName(k)).join(', ') })"
              density="compact"
              hide-details
              class="mt-1"
              data-test="deactivate-cascade"
            />
          </VAlert>

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

          <VAlert
            v-if="deactivateImpact?.items?.length"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-3"
            data-test="module-deactivate-impact"
          >
            <div
              v-for="it in deactivateImpact.items"
              :key="`${it.module}-${it.key}`"
              class="text-body-2 mb-1"
            >
              <strong>{{ modName({ key: it.module, name: it.module_name }) }}</strong> — {{ t(`companyModules.impact.${it.key}`, { n: it.count }) }}
            </div>
            <div class="text-body-2 mt-1">
              {{ t('companyModules.impact.nothingDeleted') }}
            </div>
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
            data-test="module-deactivate-confirm"
            :loading="togglingKey === deactivateTarget?.key"
            :disabled="deactivateDependents.length > 0 && !deactivateCascade"
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
          <VSkeletonLoader
            v-if="quoteLoading"
            type="paragraph"
          />

          <template v-else-if="quoteData">
            <!-- ADR-340: Line items HT -->
            <!-- ADR-807 : module demandé, puis modules nécessaires ajoutés (facturés s'ils sont payants) -->
            <div class="text-caption text-medium-emphasis">
              {{ t('companyModules.quoteRequested') }}
            </div>
            <div
              class="d-flex justify-space-between align-center py-1"
              data-test="quote-requested"
            >
              <span class="text-body-1">{{ quoteName(quoteModuleKey) }}</span>
              <span class="text-body-1 font-weight-medium">
                <template v-if="quoteLine(quoteModuleKey)">{{ formatMoney(quoteLine(quoteModuleKey).amount, { currency: quoteData.currency }) }}/{{ t('companyModules.quoteMonth') }}</template>
                <template v-else>{{ t('companyModules.quoteNoCharge') }}</template>
              </span>
            </div>
            <template v-if="quoteAdded.length">
              <div class="text-caption text-medium-emphasis mt-2">
                {{ t('companyModules.quoteAdded') }}
              </div>
              <div
                v-for="k in quoteAdded"
                :key="k"
                class="d-flex justify-space-between align-center py-1"
                :data-test="`quote-added-${k}`"
              >
                <span class="text-body-1">{{ quoteName(k) }}</span>
                <span class="text-body-1 font-weight-medium">
                  <template v-if="quoteLine(k) && quoteLine(k).amount > 0">{{ formatMoney(quoteLine(k).amount, { currency: quoteData.currency }) }}/{{ t('companyModules.quoteMonth') }}</template>
                  <template v-else>{{ t('companyModules.quoteNoCharge') }}</template>
                </span>
              </div>
            </template>

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
              v-if="quoteData.total_ttc > 0"
              type="info"
              variant="tonal"
              density="compact"
              class="mt-4"
            >
              {{ t('companyModules.quoteChargedImmediately') }}
            </VAlert>

            <!-- ADR-340: Included modules (no charge) -->
            <div
              v-if="quoteAlreadyActive.length"
              class="mt-4"
              data-test="quote-already-active"
            >
              <span class="text-body-2 text-medium-emphasis">
                {{ t('companyModules.quoteAlreadyActive') }}
              </span>
              <div class="d-flex flex-wrap gap-1 mt-1">
                <VChip
                  v-for="k in quoteAlreadyActive"
                  :key="k"
                  size="small"
                  variant="tonal"
                  color="info"
                >
                  {{ quoteName(k) }}
                </VChip>
              </div>
            </div>
            <!-- ADR-807 : conséquence d'une désactivation future (règle réelle de ModuleActivationEngine::disable) -->
            <VAlert
              type="warning"
              variant="tonal"
              density="compact"
              class="mt-4"
              data-test="quote-deactivation"
            >
              <template v-if="quoteAdded.length">
                {{ t('companyModules.quoteDeactivationWithAdded', { module: quoteModuleName, list: quoteAdded.map(quoteName).join(', ') }) }}
              </template>
              <template v-else>
                {{ t('companyModules.quoteDeactivation', { module: quoteModuleName }) }}
              </template>
              {{ moduleStore.addonDeactivationTiming === 'immediate' ? t('companyModules.quoteBillingStopsNow') : t('companyModules.quoteBillingStopsEndOfPeriod') }}
            </VAlert>
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
            data-test="module-quote-confirm"
            :disabled="quoteLoading || !quoteData"
            @click="confirmQuoteEnable"
          >
            {{ t('companyModules.quoteConfirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Module preview drawer -->
    <VNavigationDrawer
      v-model="showPreviewDrawer"
      temporary
      location="end"
      width="420"
    >
      <template v-if="selectedModule">
        <!-- Header -->
        <div class="pa-6 pb-4">
          <div class="d-flex align-center gap-3 mb-4">
            <VAvatar
              size="48"
              :color="selectedModule.type === 'core' ? 'primary' : 'info'"
              variant="tonal"
            >
              <VIcon
                :icon="selectedModule.icon_name || 'tabler-puzzle'"
                size="24"
              />
            </VAvatar>
            <div class="flex-grow-1">
              <h5 class="text-h5">
                {{ modName(selectedModule) }}
              </h5>
              <p
                v-if="previewContent"
                class="text-body-2 text-medium-emphasis mb-0"
              >
                {{ previewContent.tagline }}
              </p>
            </div>
            <VBtn
              icon
              variant="text"
              size="small"
              @click="showPreviewDrawer = false"
            >
              <VIcon icon="tabler-x" />
            </VBtn>
          </div>

          <!-- Status chip -->
          <VChip
            v-if="previewStatusChip"
            :color="previewStatusChip.color"
            variant="tonal"
            size="small"
          >
            <template #prepend>
              <VIcon
                :icon="previewStatusChip.icon"
                size="16"
              />
            </template>
            {{ previewStatusChip.text }}
          </VChip>
        </div>

        <VDivider />

        <!-- Benefits -->
        <div
          v-if="previewContent"
          class="pa-6 pb-2"
        >
          <h6 class="text-h6 mb-3">
            {{ t('modulePreview.benefits') }}
          </h6>
          <VList density="compact">
            <VListItem
              v-for="(benefit, idx) in previewContent.benefits"
              :key="idx"
              class="px-0"
            >
              <template #prepend>
                <VIcon
                  icon="tabler-circle-check-filled"
                  color="success"
                  size="20"
                  class="me-2"
                />
              </template>
              <VListItemTitle class="text-body-2 text-wrap">
                {{ benefit }}
              </VListItemTitle>
            </VListItem>
          </VList>
        </div>

        <!-- Usage example -->
        <div
          v-if="previewContent"
          class="px-6 pb-4"
        >
          <h6 class="text-h6 mb-3">
            {{ t('modulePreview.usageExample') }}
          </h6>
          <VCard
            color="primary"
            variant="tonal"
          >
            <VCardText class="text-body-2">
              <VIcon
                icon="tabler-bulb"
                size="18"
                class="me-1"
              />
              {{ previewContent.example }}
            </VCardText>
          </VCard>
        </div>

        <!-- Value proposition (ROI) -->
        <div
          v-if="previewContent"
          class="px-6 pb-4"
        >
          <h6 class="text-h6 mb-3">
            {{ t('modulePreview.roi') }}
          </h6>
          <VAlert
            type="success"
            variant="tonal"
            density="comfortable"
          >
            <template #prepend>
              <VIcon icon="tabler-trending-up" />
            </template>
            <span class="text-body-1 font-weight-medium">
              {{ previewContent.valueProposition }}
            </span>
          </VAlert>
        </div>

        <VSpacer />

        <!-- CTA -->
        <div class="pa-6 pt-2">
          <!-- ADR-732 (P0-A) : module en préparation → aucune action d'achat -->
          <VBtn
            v-if="isPreview(selectedModule)"
            color="secondary"
            variant="tonal"
            block
            disabled
          >
            <template #prepend>
              <VIcon icon="tabler-tools" />
            </template>
            {{ t('companyModules.statePreparing') }}
          </VBtn>

          <!-- Already active -->
          <VBtn
            v-else-if="['included', 'active'].includes(selectedModule.display_state)"
            color="success"
            variant="tonal"
            block
            disabled
          >
            <template #prepend>
              <VIcon icon="tabler-check" />
            </template>
            {{ t('modulePreview.alreadyActive') }}
          </VBtn>

          <!-- Available → Activate -->
          <VBtn
            v-else-if="selectedModule.display_state === 'available' && canManage"
            color="primary"
            variant="elevated"
            block
            :loading="togglingKey === selectedModule.key"
            @click="showPreviewDrawer = false; activateModule(selectedModule)"
          >
            <template #prepend>
              <VIcon icon="tabler-power" />
            </template>
            {{ t('modulePreview.activate') }}
          </VBtn>

          <!-- Locked plan → Upgrade -->
          <VBtn
            v-else-if="selectedModule.display_state === 'locked_plan'"
            color="warning"
            variant="elevated"
            block
            @click="showPreviewDrawer = false; navigateToPlanUpgrade(selectedModule)"
          >
            <template #prepend>
              <VIcon icon="tabler-lock" />
            </template>
            {{ t('modulePreview.upgradeRequired') }}
          </VBtn>

          <!-- Locked addon → Purchase -->
          <VBtn
            v-else-if="selectedModule.display_state === 'locked_addon'"
            color="warning"
            variant="elevated"
            block
            :loading="togglingKey === selectedModule.key"
            @click="showPreviewDrawer = false; activateModule(selectedModule)"
          >
            <template #prepend>
              <VIcon icon="tabler-shopping-cart" />
            </template>
            {{ t('modulePreview.activate') }}
          </VBtn>

          <!-- Contact sales -->
          <VBtn
            v-else-if="selectedModule.display_state === 'contact_sales'"
            color="secondary"
            variant="elevated"
            block
            @click="showPreviewDrawer = false; openContactDialog(selectedModule)"
          >
            <template #prepend>
              <VIcon icon="tabler-mail" />
            </template>
            {{ t('modulePreview.contactSales') }}
          </VBtn>
        </div>
      </template>
    </VNavigationDrawer>

    <!-- Contact sales dialog -->
    <VDialog
      v-model="showContactDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle class="d-flex align-center pt-5 px-6">
          <VIcon
            icon="tabler-lock"
            color="secondary"
            class="me-2"
          />
          {{ t('contactSales.title') }}
        </VCardTitle>

        <VCardText class="px-6">
          <p class="text-body-1 mb-4">
            {{ t('contactSales.description') }}
          </p>

          <div
            v-if="contactModule"
            class="d-flex align-center gap-2 mb-4"
          >
            <span class="text-body-2 text-medium-emphasis">{{ t('contactSales.moduleName') }} :</span>
            <VChip
              size="small"
              variant="tonal"
              color="info"
            >
              <template #prepend>
                <VIcon
                  :icon="contactModule.icon_name || 'tabler-puzzle'"
                  size="16"
                />
              </template>
              {{ modName(contactModule) }}
            </VChip>
          </div>

          <VAlert
            v-if="contactSent"
            type="success"
            variant="tonal"
            class="mb-4"
          >
            {{ t('contactSales.sent') }}
          </VAlert>

          <template v-if="!contactSent">
            <AppTextField
              v-model="contactForm.email"
              :label="t('contactSales.emailLabel')"
              type="email"
              class="mb-4"
            />

            <VTextarea
              v-model="contactForm.message"
              :label="t('contactSales.messageLabel')"
              :placeholder="t('contactSales.messagePlaceholder')"
              rows="3"
              auto-grow
            />
          </template>
        </VCardText>

        <VCardActions class="px-6 pb-5">
          <VSpacer />
          <VBtn
            color="secondary"
            variant="tonal"
            @click="showContactDialog = false"
          >
            {{ contactSent ? t('common.close') : t('contactSales.cancel') }}
          </VBtn>
          <VBtn
            v-if="!contactSent"
            color="info"
            variant="tonal"
            @click="router.push({ name: 'company-plan' }); showContactDialog = false"
          >
            <template #prepend>
              <VIcon icon="tabler-credit-card" />
            </template>
            {{ t('contactSales.viewPlans') }}
          </VBtn>
          <VBtn
            v-if="!contactSent"
            color="primary"
            variant="elevated"
            :loading="contactSending"
            :disabled="!contactForm.email"
            @click="submitContact"
          >
            <template #prepend>
              <VIcon icon="tabler-send" />
            </template>
            {{ t('contactSales.submit') }}
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
.modules-summary :deep(.app-status-text) { white-space: nowrap; }
</style>
