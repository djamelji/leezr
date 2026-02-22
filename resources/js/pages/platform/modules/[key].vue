<script setup>
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    permission: 'manage_modules',
  },
})

const route = useRoute()
const router = useRouter()
const settingsStore = usePlatformSettingsStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const isSaving = ref(false)
const togglingGlobal = ref(false)
const showExpertMode = ref(false)
const companiesLoaded = ref(false)

// Profile data (read-only manifest)
const mod = ref(null)
const dependents = ref([])
const companies = ref([])
const compatibleJobdomainsDetail = ref(null)
const includedByJobdomains = ref([])

// Editable platform config
const platformConfig = ref({
  pricing_mode: null,
  is_listed: false,
  is_sellable: false,
  pricing_model: null,
  pricing_metric: null,
  pricing_params: null,
  settings_schema: null,
  notes: null,
  display_name_override: null,
  description_override: null,
  min_plan_override: null,
  sort_order_override: null,
  icon_type: null,
  icon_name: null,
})

// Manifest defaults (for persistent hints on override fields)
const manifestDefaults = ref({
  name: '',
  description: '',
  min_plan: null,
  sort_order: 0,
})

// Snapshot for dirty detection
const originalConfigSnapshot = ref('')

// Structured pricing refs
const flatPrice = ref(null)
const planFlatPrices = ref({ starter: null, pro: null, business: null })
const perSeatIncluded = ref({ starter: null, pro: null, business: null })
const perSeatOverage = ref({ starter: null, pro: null, business: null })
const usageUnitPrice = ref(null)
const tiers = ref([{ up_to: null, price: null }])

// Expert mode JSON refs
const expertPricingJson = ref('{}')
const expertSchemaJson = ref('{}')
const expertPricingError = ref('')
const expertSchemaError = ref('')

// ─── Options (user-friendly labels) ─────────────────
const pricingModeOptions = [
  { title: 'Included in Plan', value: 'included' },
  { title: 'Paid Add-on (additional monthly cost)', value: 'addon' },
  { title: 'Internal / Not Commercial', value: 'internal' },
]

const pricingStructureOptions = [
  { title: 'Fixed price (same for all plans)', value: 'flat' },
  { title: 'Price varies by plan', value: 'plan_flat' },
  { title: 'Per active user', value: 'per_seat' },
  { title: 'Usage-based', value: 'usage' },
  { title: 'Tiered pricing', value: 'tiered' },
]

const pricingUnitOptions = [
  { title: 'Not usage-based', value: 'none' },
  { title: 'Per active user', value: 'users' },
  { title: 'Per shipment', value: 'shipments' },
  { title: 'Per SMS sent', value: 'sms' },
  { title: 'Per API call', value: 'api_calls' },
  { title: 'Per GB stored', value: 'storage_gb' },
]

// ─── Computed: is pricing editor active? ────────────
const isPricingActive = computed(() => platformConfig.value.pricing_mode === 'addon')

// Metric should only show for usage/tiered models
const showMetricSelector = computed(() => {
  const m = platformConfig.value.pricing_model

  return isPricingActive.value && (m === 'usage' || m === 'tiered')
})

// ─── Hydration ──────────────────────────────────────
const hydratePricingFields = params => {
  const model = platformConfig.value.pricing_model

  // Reset all
  flatPrice.value = null
  planFlatPrices.value = { starter: null, pro: null, business: null }
  perSeatIncluded.value = { starter: null, pro: null, business: null }
  perSeatOverage.value = { starter: null, pro: null, business: null }
  usageUnitPrice.value = null
  tiers.value = [{ up_to: null, price: null }]

  if (!params || !model)
    return

  if (model === 'flat') {
    flatPrice.value = params.price_monthly ?? null
  }
  else if (model === 'plan_flat') {
    planFlatPrices.value = {
      starter: params.starter ?? null,
      pro: params.pro ?? null,
      business: params.business ?? null,
    }
  }
  else if (model === 'per_seat') {
    perSeatIncluded.value = {
      starter: params.included?.starter ?? null,
      pro: params.included?.pro ?? null,
      business: params.included?.business ?? null,
    }
    perSeatOverage.value = {
      starter: params.overage_unit_price?.starter ?? null,
      pro: params.overage_unit_price?.pro ?? null,
      business: params.overage_unit_price?.business ?? null,
    }
  }
  else if (model === 'usage') {
    usageUnitPrice.value = params.unit_price ?? null
  }
  else if (model === 'tiered') {
    tiers.value = Array.isArray(params.tiers) && params.tiers.length
      ? params.tiers.map(t => ({ up_to: t.up_to ?? null, price: t.price ?? null }))
      : [{ up_to: null, price: null }]
  }
}

// ─── Build pricing params from structured refs ──────
const buildPricingParams = () => {
  if (!isPricingActive.value)
    return null

  const model = platformConfig.value.pricing_model

  if (!model)
    return null

  if (model === 'flat')
    return { price_monthly: Number(flatPrice.value) || 0 }

  if (model === 'plan_flat') {
    return {
      starter: planFlatPrices.value.starter != null ? Number(planFlatPrices.value.starter) : null,
      pro: planFlatPrices.value.pro != null ? Number(planFlatPrices.value.pro) : null,
      business: planFlatPrices.value.business != null ? Number(planFlatPrices.value.business) : null,
    }
  }

  if (model === 'per_seat') {
    return {
      included: {
        starter: perSeatIncluded.value.starter != null ? Number(perSeatIncluded.value.starter) : null,
        pro: perSeatIncluded.value.pro != null ? Number(perSeatIncluded.value.pro) : null,
        business: perSeatIncluded.value.business != null ? Number(perSeatIncluded.value.business) : null,
      },
      overage_unit_price: {
        starter: perSeatOverage.value.starter != null ? Number(perSeatOverage.value.starter) : null,
        pro: perSeatOverage.value.pro != null ? Number(perSeatOverage.value.pro) : null,
        business: perSeatOverage.value.business != null ? Number(perSeatOverage.value.business) : null,
      },
    }
  }

  if (model === 'usage')
    return { unit_price: Number(usageUnitPrice.value) || 0 }

  if (model === 'tiered')
    return { tiers: tiers.value.map(t => ({ up_to: t.up_to != null ? Number(t.up_to) : null, price: Number(t.price) || 0 })) }

  return null
}

// ─── Current payload for dirty detection ────────────
const currentPayload = computed(() => JSON.stringify({
  pricing_mode: platformConfig.value.pricing_mode,
  is_listed: platformConfig.value.is_listed,
  is_sellable: platformConfig.value.is_sellable,
  pricing_model: isPricingActive.value ? platformConfig.value.pricing_model : null,
  pricing_metric: isPricingActive.value ? platformConfig.value.pricing_metric : null,
  pricing_params: buildPricingParams(),
  settings_schema: platformConfig.value.settings_schema,
  notes: platformConfig.value.notes,
  display_name_override: platformConfig.value.display_name_override,
  description_override: platformConfig.value.description_override,
  min_plan_override: platformConfig.value.min_plan_override,
  sort_order_override: platformConfig.value.sort_order_override,
  icon_type: platformConfig.value.icon_type,
  icon_name: platformConfig.value.icon_name,
}))

const isDirty = computed(() => currentPayload.value !== originalConfigSnapshot.value)

// ─── Pricing preview ────────────────────────────────
const metricLabel = computed(() => {
  const m = platformConfig.value.pricing_metric
  const opt = pricingUnitOptions.find(o => o.value === m)

  return opt?.title || m || ''
})

// ─── Pricing visual identity ────────────────────────
const pricingModelIcon = computed(() => {
  const icons = {
    flat: 'tabler-currency-dollar',
    plan_flat: 'tabler-layers-linked',
    per_seat: 'tabler-users',
    usage: 'tabler-activity',
    tiered: 'tabler-chart-bar',
  }

  return icons[platformConfig.value.pricing_model] || 'tabler-currency-dollar'
})

const pricingModeColor = computed(() => {
  const colors = {
    included: 'primary',
    addon: 'success',
    internal: 'warning',
  }

  return colors[platformConfig.value.pricing_mode] || 'secondary'
})

const pricingPreview = computed(() => {
  const mode = platformConfig.value.pricing_mode

  if (!mode || mode === 'internal')
    return { type: 'none', text: 'This module does not generate additional revenue.' }

  if (mode === 'included')
    return { type: 'included', text: 'Included in subscription plan. No additional cost.' }

  // mode === 'addon'
  const model = platformConfig.value.pricing_model
  const params = buildPricingParams()

  if (!model || !params)
    return { type: 'none', text: 'Select a pricing structure to configure add-on pricing.' }

  if (model === 'flat')
    return { type: 'simple', text: `All plans: +$${params.price_monthly}/month` }

  if (model === 'plan_flat') {
    return {
      type: 'table',
      rows: [
        { plan: 'Starter', value: params.starter != null ? `+$${params.starter}/month` : '—' },
        { plan: 'Pro', value: params.pro != null ? `+$${params.pro}/month` : '—' },
        { plan: 'Business', value: params.business != null ? `+$${params.business}/month` : '—' },
      ],
    }
  }

  if (model === 'per_seat') {
    return {
      type: 'table',
      rows: [
        { plan: 'Starter', value: `${params.included?.starter ?? 0} seats incl., then +$${params.overage_unit_price?.starter ?? 0}/user` },
        { plan: 'Pro', value: `${params.included?.pro ?? 0} seats incl., then +$${params.overage_unit_price?.pro ?? 0}/user` },
        { plan: 'Business', value: `${params.included?.business ?? 0} seats incl., then +$${params.overage_unit_price?.business ?? 0}/user` },
      ],
    }
  }

  if (model === 'usage')
    return { type: 'simple', text: `+$${params.unit_price} ${metricLabel.value || 'per unit'}` }

  if (model === 'tiered') {
    return {
      type: 'tiers',
      rows: params.tiers.map(t => ({
        range: t.up_to != null ? `Up to ${t.up_to}` : 'Unlimited',
        price: `+$${t.price}`,
      })),
    }
  }

  return null
})

// ─── Lifecycle ──────────────────────────────────────
onMounted(async () => {
  try {
    const data = await settingsStore.fetchModuleProfile(route.params.key)

    mod.value = data.module
    dependents.value = data.dependents
    companies.value = data.companies
    companiesLoaded.value = true
    compatibleJobdomainsDetail.value = data.compatible_jobdomains_detail
    includedByJobdomains.value = data.included_by_jobdomains

    // Hydrate manifest defaults (for persistent hints)
    if (data.manifest_defaults) {
      manifestDefaults.value = data.manifest_defaults
    }

    // Hydrate platform config
    if (data.platform_config) {
      platformConfig.value = { ...platformConfig.value, ...data.platform_config }
      hydratePricingFields(data.platform_config.pricing_params)
      expertSchemaJson.value = JSON.stringify(data.platform_config.settings_schema || {}, null, 2)
    }

    // Take dirty-detection snapshot
    originalConfigSnapshot.value = currentPayload.value
  }
  catch {
    toast('Module not found.', 'error')
    router.push({ name: 'platform-modules' })
  }
  finally {
    isLoading.value = false
  }
})

// ─── Watch pricing_mode changes — clear pricing when not addon ──
watch(() => platformConfig.value.pricing_mode, (newMode, oldMode) => {
  if (newMode !== oldMode && newMode !== 'addon') {
    platformConfig.value.pricing_model = null
    platformConfig.value.pricing_metric = null
    hydratePricingFields(null)
  }
})

// ─── Watch pricing_model changes — enforce metric + reset fields ──
watch(() => platformConfig.value.pricing_model, (newModel, oldModel) => {
  if (newModel !== oldModel) {
    hydratePricingFields(null)

    // Auto-correct metric
    if (newModel === 'flat' || newModel === 'plan_flat')
      platformConfig.value.pricing_metric = 'none'
    else if (newModel === 'per_seat')
      platformConfig.value.pricing_metric = 'users'
  }
})

// ─── Expert mode sync ───────────────────────────────
watch(showExpertMode, on => {
  if (on) {
    expertPricingJson.value = JSON.stringify(buildPricingParams() || {}, null, 2)
    expertSchemaJson.value = JSON.stringify(platformConfig.value.settings_schema || {}, null, 2)
  }
  else {
    try {
      const parsed = JSON.parse(expertPricingJson.value || '{}')

      platformConfig.value.pricing_params = parsed
      hydratePricingFields(parsed)
      expertPricingError.value = ''
    }
    catch (e) {
      expertPricingError.value = `Invalid JSON: ${e.message}`
    }
    try {
      platformConfig.value.settings_schema = JSON.parse(expertSchemaJson.value || '{}')
      expertSchemaError.value = ''
    }
    catch (e) {
      expertSchemaError.value = `Invalid JSON: ${e.message}`
    }
  }
})

// ─── Actions ────────────────────────────────────────
const toggleGlobal = async () => {
  togglingGlobal.value = true

  try {
    const data = await settingsStore.toggleModule(mod.value.key)

    mod.value.is_enabled_globally = data.module.is_enabled_globally
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to toggle module.', 'error')
  }
  finally {
    togglingGlobal.value = false
  }
}

const saveConfig = async () => {
  isSaving.value = true

  try {
    // If expert mode is on, parse JSON first
    if (showExpertMode.value) {
      try {
        const parsed = JSON.parse(expertPricingJson.value || '{}')

        platformConfig.value.pricing_params = parsed
        hydratePricingFields(parsed)
      }
      catch (e) {
        toast(`Invalid pricing JSON: ${e.message}`, 'error')
        isSaving.value = false

        return
      }
      try {
        platformConfig.value.settings_schema = JSON.parse(expertSchemaJson.value || '{}')
      }
      catch (e) {
        toast(`Invalid schema JSON: ${e.message}`, 'error')
        isSaving.value = false

        return
      }
    }

    const payload = {
      pricing_mode: platformConfig.value.pricing_mode,
      is_listed: platformConfig.value.is_listed,
      is_sellable: platformConfig.value.is_sellable,
      pricing_model: isPricingActive.value ? platformConfig.value.pricing_model : null,
      pricing_metric: isPricingActive.value ? platformConfig.value.pricing_metric : null,
      pricing_params: buildPricingParams(),
      settings_schema: platformConfig.value.settings_schema,
      notes: platformConfig.value.notes,
      display_name_override: platformConfig.value.display_name_override || null,
      description_override: platformConfig.value.description_override || null,
      min_plan_override: platformConfig.value.min_plan_override || null,
      sort_order_override: platformConfig.value.sort_order_override != null ? Number(platformConfig.value.sort_order_override) : null,
      icon_type: platformConfig.value.icon_type || null,
      icon_name: platformConfig.value.icon_name || null,
    }

    const data = await settingsStore.updateModuleConfig(mod.value.key, payload)

    // Re-hydrate from server response
    const updated = data.module

    platformConfig.value = {
      pricing_mode: updated.pricing_mode,
      is_listed: updated.is_listed,
      is_sellable: updated.is_sellable,
      pricing_model: updated.pricing_model,
      pricing_metric: updated.pricing_metric,
      pricing_params: updated.pricing_params,
      settings_schema: updated.settings_schema,
      notes: updated.notes,
      display_name_override: updated.display_name_override,
      description_override: updated.description_override,
      min_plan_override: updated.min_plan_override,
      sort_order_override: updated.sort_order_override,
      icon_type: updated.icon_type,
      icon_name: updated.icon_name,
    }

    hydratePricingFields(updated.pricing_params)
    expertSchemaJson.value = JSON.stringify(updated.settings_schema || {}, null, 2)
    originalConfigSnapshot.value = currentPayload.value
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || 'Failed to save configuration.', 'error')
  }
  finally {
    isSaving.value = false
  }
}

const planLabel = planKey => {
  const labels = { pro: 'Pro', business: 'Business' }

  return labels[planKey] || planKey
}
</script>

<template>
  <div>
    <!-- Loading -->
    <VCard
      v-if="isLoading"
      class="pa-8 text-center"
    >
      <VProgressCircular indeterminate />
    </VCard>

    <template v-else-if="mod">
      <!-- Header -->
      <VCard class="mb-6">
        <VCardText class="d-flex align-center gap-4">
          <VBtn
            icon
            variant="text"
            size="small"
            :to="{ name: 'platform-modules' }"
          >
            <VIcon icon="tabler-arrow-left" />
          </VBtn>

          <VAvatar
            size="48"
            :color="mod.type === 'core' ? 'primary' : 'info'"
            variant="tonal"
          >
            <VIcon :icon="mod.icon_name || 'tabler-puzzle'" />
          </VAvatar>

          <div class="flex-grow-1">
            <h5 class="text-h5">
              {{ mod.name }}
            </h5>
            <div class="d-flex align-center gap-2 mt-1">
              <code class="text-body-2">{{ mod.key }}</code>
              <VChip
                :color="mod.type === 'core' ? 'primary' : 'info'"
                size="x-small"
              >
                {{ mod.type === 'core' ? 'Core' : 'Addon' }}
              </VChip>
              <VChip
                v-if="mod.min_plan"
                color="warning"
                size="x-small"
              >
                Min: {{ planLabel(mod.min_plan) }}
              </VChip>
              <VChip
                :color="mod.is_enabled_globally ? 'success' : 'error'"
                size="x-small"
              >
                {{ mod.is_enabled_globally ? 'Enabled' : 'Disabled' }}
              </VChip>
            </div>
          </div>

          <!-- Global toggle -->
          <div class="d-flex align-center gap-2">
            <span class="text-body-2 text-medium-emphasis">Global</span>
            <VSwitch
              :model-value="mod.is_enabled_globally"
              :loading="togglingGlobal"
              hide-details
              color="primary"
              @update:model-value="toggleGlobal"
            />
          </div>
        </VCardText>
      </VCard>

      <!-- Two-column layout -->
      <VRow>
        <!-- LEFT COLUMN (md=8) -->
        <VCol
          cols="12"
          md="8"
        >
          <!-- Module Identity (editable overrides + read-only technical) -->
          <VCard
            title="Module Identity"
            class="mb-6"
          >
            <VCardText>
              <!-- Editable overrides -->
              <div class="text-body-2 font-weight-medium mb-3">
                Display Overrides
              </div>
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="platformConfig.display_name_override"
                    label="Display Name"
                    :placeholder="manifestDefaults.name"
                    :hint="`Manifest: ${manifestDefaults.name}`"
                    persistent-hint
                    clearable
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="mod.key"
                    label="Key"
                    disabled
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextarea
                    v-model="platformConfig.description_override"
                    label="Description"
                    :placeholder="manifestDefaults.description"
                    :hint="`Manifest: ${manifestDefaults.description}`"
                    persistent-hint
                    clearable
                    rows="2"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <VSelect
                    v-model="platformConfig.min_plan_override"
                    :items="[{ title: 'Pro', value: 'pro' }, { title: 'Business', value: 'business' }]"
                    label="Min Plan Override"
                    :placeholder="manifestDefaults.min_plan || 'None'"
                    :hint="`Manifest: ${manifestDefaults.min_plan || 'None'}`"
                    persistent-hint
                    clearable
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="platformConfig.sort_order_override"
                    type="number"
                    label="Sort Order Override"
                    :placeholder="String(manifestDefaults.sort_order)"
                    :hint="`Manifest: ${manifestDefaults.sort_order}`"
                    persistent-hint
                    clearable
                  />
                </VCol>
              </VRow>

              <VDivider class="my-4" />

              <!-- Icon override -->
              <div class="text-body-2 font-weight-medium mb-3">
                Icon
              </div>
              <VRow>
                <VCol
                  cols="12"
                  md="4"
                >
                  <VSelect
                    v-model="platformConfig.icon_type"
                    :items="[{ title: 'Tabler Icon', value: 'tabler' }, { title: 'Image (SVG)', value: 'image' }]"
                    label="Icon Type"
                    clearable
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="8"
                >
                  <AppTextField
                    v-model="platformConfig.icon_name"
                    label="Icon Name"
                    placeholder="tabler-puzzle"
                    hint="Tabler icon name (e.g. tabler-truck) or image path"
                    persistent-hint
                    clearable
                  />
                </VCol>
              </VRow>

              <VDivider class="my-4" />

              <!-- Read-only technical metadata -->
              <div class="text-body-2 font-weight-medium mb-3">
                Technical Metadata
              </div>
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="mod.type"
                    label="Type"
                    disabled
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    :model-value="mod.surface"
                    label="Surface"
                    disabled
                  />
                </VCol>
              </VRow>
            </VCardText>

            <VDivider />

            <!-- Entitlement logic -->
            <VCardText>
              <div class="text-body-1 font-weight-medium mb-3">
                Entitlement Logic
              </div>
              <div class="d-flex flex-column gap-2">
                <div class="d-flex align-center gap-2">
                  <VIcon
                    :icon="mod.type === 'core' ? 'tabler-check' : 'tabler-minus'"
                    :color="mod.type === 'core' ? 'success' : 'secondary'"
                    size="18"
                  />
                  <span class="text-body-2">
                    <strong>Core gate:</strong> {{ mod.type === 'core' ? 'Always entitled (core module)' : 'Not a core module — passes through' }}
                  </span>
                </div>
                <div class="d-flex align-center gap-2">
                  <VIcon
                    :icon="mod.min_plan ? 'tabler-check' : 'tabler-minus'"
                    :color="mod.min_plan ? 'warning' : 'secondary'"
                    size="18"
                  />
                  <span class="text-body-2">
                    <strong>Plan gate:</strong> {{ mod.min_plan ? `Requires ${planLabel(mod.min_plan)} plan or higher` : 'No plan requirement' }}
                  </span>
                </div>
                <div class="d-flex align-center gap-2">
                  <VIcon
                    :icon="mod.compatible_jobdomains ? 'tabler-check' : 'tabler-minus'"
                    :color="mod.compatible_jobdomains ? 'info' : 'secondary'"
                    size="18"
                  />
                  <span class="text-body-2">
                    <strong>Compat gate:</strong> {{ mod.compatible_jobdomains ? `Restricted to: ${mod.compatible_jobdomains.join(', ')}` : 'Compatible with all job domains' }}
                  </span>
                </div>
                <div class="d-flex align-center gap-2">
                  <VIcon
                    icon="tabler-check"
                    color="success"
                    size="18"
                  />
                  <span class="text-body-2">
                    <strong>Source gate:</strong> Must be in jobdomain's default_modules (or future addon purchase)
                  </span>
                </div>
              </div>
            </VCardText>
          </VCard>

          <!-- Pricing Editor -->
          <VCard class="mb-6">
            <VCardTitle class="d-flex align-center">
              <VIcon
                :icon="pricingModelIcon"
                :color="pricingModeColor"
                class="me-2"
              />
              Pricing
              <VChip
                v-if="platformConfig.pricing_mode"
                :color="pricingModeColor"
                size="x-small"
                variant="tonal"
                class="ms-2"
              >
                {{ platformConfig.pricing_mode === 'addon' ? '+ Add-on' : platformConfig.pricing_mode === 'included' ? 'Included' : 'Internal' }}
              </VChip>
            </VCardTitle>
            <VCardText>
              <!-- Commercial Mode -->
              <AppSelect
                v-model="platformConfig.pricing_mode"
                :items="pricingModeOptions"
                label="Commercial Mode"
                clearable
                class="mb-2"
              />
              <div class="text-body-2 text-medium-emphasis mb-4">
                If set to "Paid Add-on", the price configured below is added on top of the company's base subscription plan price.
              </div>

              <!-- Pricing structure (only when addon) -->
              <template v-if="isPricingActive">
                <VDivider class="mb-4" />

                <VRow>
                  <VCol
                    cols="12"
                    :md="showMetricSelector ? 6 : 12"
                  >
                    <AppSelect
                      v-model="platformConfig.pricing_model"
                      :items="pricingStructureOptions"
                      label="Pricing Structure"
                      clearable
                    />
                  </VCol>
                  <VCol
                    v-if="showMetricSelector"
                    cols="12"
                    md="6"
                  >
                    <AppSelect
                      v-model="platformConfig.pricing_metric"
                      :items="pricingUnitOptions"
                      label="Pricing Unit"
                      clearable
                    />
                    <div class="text-body-2 text-medium-emphasis mt-1">
                      For usage-based or per-user pricing, this defines what is measured.
                    </div>
                  </VCol>
                </VRow>

                <VDivider
                  v-if="platformConfig.pricing_model"
                  class="my-4"
                />

                <!-- Flat -->
                <template v-if="platformConfig.pricing_model === 'flat'">
                  <div class="text-body-2 font-weight-medium mb-3">
                    Additional monthly price (same for all plans)
                  </div>
                  <VRow>
                    <VCol
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        v-model="flatPrice"
                        type="number"
                        label="Monthly add-on price"
                        prefix="$"
                        placeholder="29"
                      />
                    </VCol>
                  </VRow>
                </template>

                <!-- Plan Flat -->
                <template v-if="platformConfig.pricing_model === 'plan_flat'">
                  <div class="text-body-2 font-weight-medium mb-1">
                    Additional monthly price per subscription plan
                  </div>
                  <div class="text-body-2 text-medium-emphasis mb-3">
                    This amount is added to the company's selected subscription plan price.
                  </div>
                  <VRow>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="planFlatPrices.starter"
                        type="number"
                        label="Starter"
                        prefix="$"
                        placeholder="49"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="planFlatPrices.pro"
                        type="number"
                        label="Pro"
                        prefix="$"
                        placeholder="29"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="planFlatPrices.business"
                        type="number"
                        label="Business"
                        prefix="$"
                        placeholder="19"
                      />
                    </VCol>
                  </VRow>
                </template>

                <!-- Per Seat -->
                <template v-if="platformConfig.pricing_model === 'per_seat'">
                  <div class="text-body-2 font-weight-medium mb-3">
                    Included seats per plan (no additional cost)
                  </div>
                  <VRow>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="perSeatIncluded.starter"
                        type="number"
                        label="Starter"
                        placeholder="5"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="perSeatIncluded.pro"
                        type="number"
                        label="Pro"
                        placeholder="10"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="perSeatIncluded.business"
                        type="number"
                        label="Business"
                        placeholder="25"
                      />
                    </VCol>
                  </VRow>

                  <VDivider class="my-4" />

                  <div class="text-body-2 font-weight-medium mb-3">
                    Additional price per extra user (beyond included seats)
                  </div>
                  <VRow>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="perSeatOverage.starter"
                        type="number"
                        label="Starter"
                        prefix="$"
                        placeholder="1.00"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="perSeatOverage.pro"
                        type="number"
                        label="Pro"
                        prefix="$"
                        placeholder="0.80"
                      />
                    </VCol>
                    <VCol
                      cols="12"
                      md="4"
                    >
                      <AppTextField
                        v-model="perSeatOverage.business"
                        type="number"
                        label="Business"
                        prefix="$"
                        placeholder="0.60"
                      />
                    </VCol>
                  </VRow>
                </template>

                <!-- Usage -->
                <template v-if="platformConfig.pricing_model === 'usage'">
                  <div class="text-body-2 font-weight-medium mb-3">
                    Additional cost per unit consumed
                  </div>
                  <VRow>
                    <VCol
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        v-model="usageUnitPrice"
                        type="number"
                        label="Price per unit"
                        prefix="$"
                        placeholder="0.05"
                      />
                    </VCol>
                  </VRow>
                </template>

                <!-- Tiered -->
                <template v-if="platformConfig.pricing_model === 'tiered'">
                  <div class="text-body-2 font-weight-medium mb-3">
                    Additional pricing tiers
                  </div>
                  <div
                    v-for="(tier, i) in tiers"
                    :key="i"
                    class="mb-3"
                  >
                    <VRow>
                      <VCol
                        cols="12"
                        md="5"
                      >
                        <AppTextField
                          v-model="tier.up_to"
                          type="number"
                          :label="i === 0 ? 'Up to (units)' : ''"
                          placeholder="1000"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        md="5"
                      >
                        <AppTextField
                          v-model="tier.price"
                          type="number"
                          :label="i === 0 ? 'Price' : ''"
                          prefix="$"
                          placeholder="10"
                        />
                      </VCol>
                      <VCol
                        cols="12"
                        md="2"
                        class="d-flex align-end"
                      >
                        <IconBtn
                          v-if="tiers.length > 1"
                          color="error"
                          @click="tiers.splice(i, 1)"
                        >
                          <VIcon icon="tabler-x" />
                        </IconBtn>
                      </VCol>
                    </VRow>
                  </div>
                  <VBtn
                    variant="tonal"
                    size="small"
                    prepend-icon="tabler-plus"
                    @click="tiers.push({ up_to: null, price: null })"
                  >
                    Add tier
                  </VBtn>
                </template>
              </template>

              <!-- Preview -->
              <template v-if="pricingPreview">
                <VDivider class="my-4" />
                <div class="text-body-2 font-weight-medium mb-2">
                  Revenue Impact Preview
                </div>

                <VAlert
                  :color="pricingModeColor"
                  variant="tonal"
                  class="text-body-2"
                >
                  <template #prepend>
                    <VIcon :icon="pricingModelIcon" />
                  </template>
                  <template v-if="pricingPreview.type === 'simple' || pricingPreview.type === 'included' || pricingPreview.type === 'none'">
                    {{ pricingPreview.text }}
                  </template>
                  <template v-else-if="pricingPreview.type === 'table'">
                    <div
                      v-for="row in pricingPreview.rows"
                      :key="row.plan"
                      class="d-flex justify-space-between py-1"
                    >
                      <strong>{{ row.plan }}</strong>
                      <span>{{ row.value }}</span>
                    </div>
                  </template>
                  <template v-else-if="pricingPreview.type === 'tiers'">
                    <div
                      v-for="(row, i) in pricingPreview.rows"
                      :key="i"
                      class="d-flex justify-space-between py-1"
                    >
                      <span>{{ row.range }}</span>
                      <strong>{{ row.price }}</strong>
                    </div>
                  </template>
                </VAlert>
              </template>
            </VCardText>
          </VCard>

          <!-- Expert mode toggle -->
          <div class="d-flex align-center gap-2 mb-4">
            <VSwitch
              v-model="showExpertMode"
              label="Expert mode"
              hide-details
              density="compact"
            />
            <span class="text-body-2 text-medium-emphasis">Edit raw JSON</span>
          </div>

          <!-- Expert mode card -->
          <VCard
            v-if="showExpertMode"
            title="Raw JSON"
            class="mb-6"
          >
            <VCardText>
              <AppTextarea
                v-model="expertPricingJson"
                label="pricing_params (JSON)"
                rows="6"
                :error-messages="expertPricingError ? [expertPricingError] : []"
                style="font-family: monospace;"
                class="mb-4"
                @input="expertPricingError = ''"
              />
              <AppTextarea
                v-model="expertSchemaJson"
                label="settings_schema (JSON)"
                rows="8"
                :error-messages="expertSchemaError ? [expertSchemaError] : []"
                style="font-family: monospace;"
                @input="expertSchemaError = ''"
              />
            </VCardText>
          </VCard>

          <!-- Save button -->
          <VBtn
            :loading="isSaving"
            :disabled="!isDirty"
            :variant="isDirty ? 'elevated' : 'tonal'"
            class="mb-6"
            @click="saveConfig"
          >
            Save Configuration
          </VBtn>

          <!-- Companies (lazy-loaded) -->
          <VExpansionPanels class="mb-6">
            <VExpansionPanel>
              <VExpansionPanelTitle>
                <VIcon
                  icon="tabler-buildings"
                  class="me-2"
                />
                Companies using this module
                <VChip
                  v-if="companiesLoaded"
                  size="x-small"
                  class="ms-2"
                >
                  {{ companies.length }}
                </VChip>
              </VExpansionPanelTitle>
              <VExpansionPanelText>
                <VTable
                  v-if="companies.length"
                  class="text-no-wrap"
                >
                  <thead>
                    <tr>
                      <th>Company</th>
                      <th>Slug</th>
                      <th class="text-center">
                        Status
                      </th>
                      <th class="text-center">
                        Plan
                      </th>
                      <th style="width: 100px;" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="c in companies"
                      :key="c.id"
                    >
                      <td class="font-weight-medium">
                        {{ c.name }}
                      </td>
                      <td>
                        <code>{{ c.slug }}</code>
                      </td>
                      <td class="text-center">
                        <VChip
                          :color="c.status === 'active' ? 'success' : 'error'"
                          size="small"
                          variant="tonal"
                        >
                          {{ c.status }}
                        </VChip>
                      </td>
                      <td class="text-center">
                        {{ c.plan_key || 'starter' }}
                      </td>
                      <td>
                        <VBtn
                          size="small"
                          variant="tonal"
                          :to="{ name: 'platform-companies-id', params: { id: c.id } }"
                        >
                          View
                        </VBtn>
                      </td>
                    </tr>
                  </tbody>
                </VTable>

                <div
                  v-else
                  class="text-center text-disabled pa-4"
                >
                  No companies are currently using this module.
                </div>
              </VExpansionPanelText>
            </VExpansionPanel>
          </VExpansionPanels>
        </VCol>

        <!-- RIGHT COLUMN (md=4) -->
        <VCol
          cols="12"
          md="4"
        >
          <!-- Commercial -->
          <VCard
            title="Commercial"
            class="mb-6"
          >
            <VCardText>
              <div class="d-flex flex-column gap-y-4">
                <VSwitch
                  v-model="platformConfig.is_listed"
                  label="Listed in catalog"
                  color="primary"
                  hide-details
                />
                <VSwitch
                  v-model="platformConfig.is_sellable"
                  label="Sellable"
                  color="primary"
                  hide-details
                />
              </div>

              <VDivider class="my-4" />

              <AppTextarea
                v-model="platformConfig.notes"
                label="Notes"
                rows="3"
                placeholder="Internal notes about this module..."
              />
            </VCardText>
          </VCard>

          <!-- Permissions (read-only chips) -->
          <VCard
            title="Permissions"
            class="mb-6"
          >
            <VCardText>
              <template v-if="mod.permissions && mod.permissions.length">
                <div class="d-flex flex-wrap gap-1">
                  <VChip
                    v-for="perm in mod.permissions"
                    :key="perm.key"
                    size="small"
                    variant="tonal"
                  >
                    {{ perm.label }}
                  </VChip>
                </div>
              </template>
              <span
                v-else
                class="text-disabled text-body-2"
              >No permissions defined.</span>
            </VCardText>
          </VCard>

          <!-- Capability Bundles (read-only chips + hint) -->
          <VCard
            title="Capability Bundles"
            class="mb-6"
          >
            <VCardText>
              <template v-if="mod.bundles && mod.bundles.length">
                <div
                  v-for="bundle in mod.bundles"
                  :key="bundle.key"
                  class="mb-3"
                >
                  <VChip
                    size="small"
                    :color="bundle.is_admin ? 'warning' : 'info'"
                    variant="tonal"
                    class="mb-1"
                  >
                    {{ bundle.label }}
                  </VChip>
                  <div
                    v-if="bundle.hint"
                    class="text-body-2 text-medium-emphasis ms-1"
                  >
                    {{ bundle.hint }}
                  </div>
                </div>
              </template>
              <span
                v-else
                class="text-disabled text-body-2"
              >No bundles defined.</span>
              <div class="text-body-2 text-medium-emphasis mt-3">
                Bundles group permissions for role templates. They are defined in code and read-only.
              </div>
            </VCardText>
          </VCard>

          <!-- Organize (read-only info) -->
          <VCard title="Organize">
            <VCardText>
              <!-- Compatibility -->
              <div class="text-body-2 font-weight-medium mb-2">
                Compatibility
              </div>
              <template v-if="compatibleJobdomainsDetail === null">
                <VAlert
                  type="info"
                  variant="tonal"
                  density="compact"
                  class="text-body-2 mb-4"
                >
                  All job domains
                </VAlert>
              </template>
              <template v-else-if="compatibleJobdomainsDetail.length">
                <div class="d-flex flex-wrap gap-1 mb-4">
                  <VChip
                    v-for="jd in compatibleJobdomainsDetail"
                    :key="jd.id"
                    size="small"
                    variant="tonal"
                    :to="{ name: 'platform-jobdomains-id', params: { id: jd.id } }"
                  >
                    {{ jd.label }}
                  </VChip>
                </div>
              </template>
              <template v-else>
                <span class="text-disabled text-body-2 d-block mb-4">No matching job domains.</span>
              </template>

              <VDivider class="mb-4" />

              <!-- Included by -->
              <div class="text-body-2 font-weight-medium mb-2">
                Included By
              </div>
              <template v-if="includedByJobdomains.length">
                <div class="d-flex flex-wrap gap-1 mb-4">
                  <VChip
                    v-for="jd in includedByJobdomains"
                    :key="jd.id"
                    size="small"
                    color="success"
                    variant="tonal"
                    :to="{ name: 'platform-jobdomains-id', params: { id: jd.id } }"
                  >
                    {{ jd.label }}
                  </VChip>
                </div>
              </template>
              <template v-else>
                <span class="text-disabled text-body-2 d-block mb-4">Not included by default in any job domain.</span>
              </template>

              <VDivider class="mb-4" />

              <!-- Dependencies -->
              <div class="text-body-2 font-weight-medium mb-2">
                Requires
              </div>
              <template v-if="mod.requires.length">
                <div class="d-flex flex-wrap gap-1 mb-4">
                  <VChip
                    v-for="req in mod.requires"
                    :key="req"
                    size="small"
                    color="warning"
                    variant="tonal"
                    :to="{ name: 'platform-modules-key', params: { key: req } }"
                  >
                    {{ req }}
                  </VChip>
                </div>
              </template>
              <template v-else>
                <span class="text-disabled text-body-2 d-block mb-4">No dependencies.</span>
              </template>

              <div class="text-body-2 font-weight-medium mb-2">
                Dependents
              </div>
              <template v-if="dependents.length">
                <div class="d-flex flex-wrap gap-1">
                  <VChip
                    v-for="dep in dependents"
                    :key="dep.key"
                    size="small"
                    color="info"
                    variant="tonal"
                    :to="{ name: 'platform-modules-key', params: { key: dep.key } }"
                  >
                    {{ dep.name }}
                  </VChip>
                </div>
              </template>
              <template v-else>
                <span class="text-disabled text-body-2">No modules depend on this one.</span>
              </template>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
