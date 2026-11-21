<script setup>
/**
 * ADR-268: Company 360° Admin View
 *
 * Follows the Vuexy User Detail preset pattern:
 * Left sidebar (bio panel) + right tabs (overview, billing, modules, members, activity)
 */
import { formatDate } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'
import { usePlatformCompaniesStore } from '@/modules/platform-admin/companies/companies.store'
import { useAppToast } from '@/composables/useAppToast'
import { usePlatformAuthStore } from '@/core/stores/platformAuth'
import CompanyBioPanel from '../_CompanyBioPanel.vue'
import CompanyBillingTab from '../_CompanyBillingTab.vue'
import CompanyMembersTab from '../_CompanyMembersTab.vue'
import CompanyActivityTab from '../_CompanyActivityTab.vue'
import CompanyOnboardingTab from '../_CompanyOnboardingTab.vue'

definePage({
  name: 'platform-companies-id', // ADR-820 : onglet dans le chemin (/platform/companies/{id}/{billing|modules|members|activity})
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.companies',
    navActiveKey: 'platform-companies',
    // ADR-834 : the creation operator (create_companies) follows the start of service of the companies it creates
    // GET /companies/{id} : view_companies, or create_companies on a company the operator created (scope middleware)
    anyPermission: ['view_companies', 'create_companies'],
  },
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const companiesStore = usePlatformCompaniesStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const actionLoading = ref(false)
const platformAuth = usePlatformAuthStore()
// ADR-834 : each tab / action only for the permission its API requires (create_companies alone = overview + start of service)
const canManage = computed(() => platformAuth.hasPermission('manage_companies'))
const canViewAll = computed(() => platformAuth.hasPermission('view_companies'))
const TAB_PERMISSION = { configuration: 'manage_companies', billing: 'view_companies', modules: 'view_companies', members: 'view_companies', activity: 'view_companies' }
const tabAllowed = tab => !TAB_PERMISSION[tab] || platformAuth.hasPermission(TAB_PERMISSION[tab])
const COMPANY_TABS = ['overview', 'onboarding', 'configuration', 'billing', 'modules', 'members', 'activity'].filter(tabAllowed) // ADR-821 : configuration séparée de la synthèse
// ADR-820 : l'onglet vient du chemin ; les anciens liens `?tab=billing` (jusqu'ici ignorés) sont compris puis réécrits.
const askedTab = route.params.tab || route.query.tab
const activeTab = ref(COMPANY_TABS.includes(askedTab) ? askedTab : 'overview')

// ─── Profile data ───────────────────────────────────
const company = ref(null)
const plan = ref(null)
const modules = ref([])
const addonSubscriptions = ref([])
const incompleteProfilesCount = ref(0)
const owner = ref(null)

// ─── Tab data (lazy loaded) ────────────────────────
const billingData = ref(null)
const billingLoading = ref(false)
const membersData = ref([])
const membersLoading = ref(false)
const activityData = ref([])
const activityLoading = ref(false)

// ─── Plan form ──────────────────────────────────────
const planForm = ref({ plan_key: '' })
const planPreview = ref(null)
const planPreviewLoading = ref(false)
const showPlanPreview = ref(false)
const pendingPlanKey = ref(null)

// ─── Overview form ─────────────────────────────────
const overviewForm = ref({ name: '' })
const dynamicFields = ref([])
const dynamicForm = ref({})
const overviewSaving = ref(false)

// Group dynamic fields by section for structured display
const fieldByCode = code => dynamicFields.value.find(f => f.code === code)
const hasField = code => !!fieldByCode(code)

const fieldLabel = code => fieldByCode(code)?.label || code

const fiscalFields = computed(() =>
  ['siret', 'vat_number', 'legal_name', 'legal_form'].filter(hasField),
)
const companyAddressFields = computed(() =>
  ['company_address', 'company_complement', 'company_city', 'company_postal_code', 'company_region'].filter(hasField),
)
const contactFields = computed(() =>
  ['company_phone'].filter(hasField),
)
const billingAddressFields = computed(() =>
  ['billing_address', 'billing_complement', 'billing_city', 'billing_postal_code', 'billing_region', 'billing_email'].filter(hasField),
)

// ─── Wallet form ────────────────────────────────────
const walletDialog = ref(false)
const walletForm = ref({ type: 'credit', amount: '', reason: '' })
const walletLoading = ref(false)

const applyProfile = data => {
  company.value = data.company
  plan.value = data.plan
  modules.value = data.modules
  addonSubscriptions.value = data.addon_subscriptions || []
  owner.value = data.owner || null
  planForm.value.plan_key = data.company.plan_key || 'starter'
  incompleteProfilesCount.value = data.incomplete_profiles_count || 0

  // Overview form
  overviewForm.value.name = data.company.name || ''
  dynamicFields.value = data.dynamic_fields || []
  const df = {}
  for (const field of data.dynamic_fields || [])
    df[field.code] = field.value
  dynamicForm.value = df
}

onMounted(async () => {
  try {
    const [profileData] = await Promise.all([
      companiesStore.fetchCompanyProfile(route.params.id),
      // the plan list (GET /plans = manage_plans) only feeds the plan change, itself reserved to manage_companies
      canManage.value && platformAuth.hasPermission('manage_plans') ? companiesStore.fetchPlans().catch(() => null) : null,
    ])

    applyProfile(profileData)

    // ADR-820 : arrivée directe sur /billing (ou ancien ?tab=billing) → données de facturation chargées
    if (route.query.tab) router.replace({ name: 'platform-companies-id', params: { id: route.params.id, tab: activeTab.value === 'overview' ? undefined : activeTab.value } })
    if (activeTab.value === 'billing')
      loadBilling()
  }
  catch {
    toast(t('platformCompanyDetail.failedToLoad'), 'error')
    router.push('/platform/companies')
  }
  finally {
    isLoading.value = false
  }
})

// ─── Save overview ──────────────────────────────────
const saveOverview = async () => {
  overviewSaving.value = true
  try {
    const data = await companiesStore.updateCompanyProfile(route.params.id, {
      name: overviewForm.value.name,
      dynamic_fields: dynamicForm.value,
    })

    // Re-apply updated data
    company.value = data.company
    overviewForm.value.name = data.company.name
    dynamicFields.value = data.dynamic_fields || []
    const df = {}
    for (const field of data.dynamic_fields || [])
      df[field.code] = field.value
    dynamicForm.value = df

    toast(t('platformCompanyDetail.profileUpdated'), 'success')
  }
  catch (err) {
    toast(err?.data?.message || t('platformCompanyDetail.profileUpdateFailed'), 'error')
  }
  finally {
    overviewSaving.value = false
  }
}

// ─── Lazy tab loading ───────────────────────────────
const loadBilling = async () => {
  if (billingData.value) return
  billingLoading.value = true
  try {
    billingData.value = await companiesStore.fetchCompanyBilling(route.params.id)
  }
  catch {
    toast(t('platformCompanyDetail.failedToLoadBilling'), 'error')
  }
  finally {
    billingLoading.value = false
  }
}

const loadMembers = async () => {
  if (membersData.value.length) return
  membersLoading.value = true
  try {
    const data = await companiesStore.fetchCompanyMembers(route.params.id)

    membersData.value = data.members
  }
  catch {
    toast(t('platformCompanyDetail.failedToLoadMembers'), 'error')
  }
  finally {
    membersLoading.value = false
  }
}

const loadActivity = async () => {
  if (activityData.value.length) return
  activityLoading.value = true
  try {
    const data = await companiesStore.fetchCompanyActivity(route.params.id)

    activityData.value = data.data || []
  }
  catch {
    toast(t('platformCompanyDetail.failedToLoadActivity'), 'error')
  }
  finally {
    activityLoading.value = false
  }
}

const refreshBilling = () => {
  billingData.value = null
  loadBilling()
}

const refreshMembers = () => {
  membersData.value = []
  loadMembers()
}

const refreshActivity = () => {
  activityData.value = []
  loadActivity()
}

watch(activeTab, tab => {
  const { tab: _, ...query } = route.query
  router.replace({ name: 'platform-companies-id', params: { id: route.params.id, tab: tab === 'overview' ? undefined : tab }, query })
  if (tab === 'billing') loadBilling()
  else if (tab === 'members') loadMembers()
  else if (tab === 'activity') loadActivity()
})

// ─── Plan change with preview ───────────────────────
const requestPlanChange = async planKey => {
  if (planKey === company.value.plan_key)
    return

  pendingPlanKey.value = planKey
  planPreviewLoading.value = true
  showPlanPreview.value = true

  try {
    planPreview.value = await companiesStore.fetchPlanChangePreview(company.value.id, planKey)
  }
  catch {
    planPreview.value = null
  }
  finally {
    planPreviewLoading.value = false
  }
}

const confirmPlanChange = async () => {
  actionLoading.value = true
  showPlanPreview.value = false

  try {
    // ADR-837 : interval kept by the server ; a change the billing policy defers is SCHEDULED, not applied now
    const res = await companiesStore.updateCompanyPlan(company.value.id, pendingPlanKey.value)
    const executed = res?.intent?.status === 'executed'
    company.value.plan_key = res?.company?.plan_key ?? company.value.plan_key
    planForm.value.plan_key = company.value.plan_key
    toast(executed ? t('companies.planUpdated') : t('companies.planChangeScheduled'), 'success')

    const profileData = await companiesStore.fetchCompanyProfile(route.params.id)

    applyProfile(profileData)

    if (billingData.value) {
      billingData.value = null
      loadBilling()
    }
  }
  catch (error) {
    planForm.value.plan_key = company.value.plan_key
    toast(error?.data?.message || t('companies.failedToUpdatePlan'), 'error')
  }
  finally {
    actionLoading.value = false
    pendingPlanKey.value = null
    planPreview.value = null
  }
}

const cancelPlanChange = () => {
  showPlanPreview.value = false
  planForm.value.plan_key = company.value.plan_key
  pendingPlanKey.value = null
  planPreview.value = null
}

// ─── Wallet adjustment ──────────────────────────────
const openWalletDialog = () => {
  walletForm.value = { type: 'credit', amount: '', reason: '' }
  walletDialog.value = true
}

const submitWalletAdjustment = async () => {
  if (!walletForm.value.amount || !walletForm.value.reason)
    return

  walletLoading.value = true

  try {
    const data = await companiesStore.adjustWallet(company.value.id, {
      type: walletForm.value.type,
      amount: Math.round(Number(walletForm.value.amount) * 100),
      reason: walletForm.value.reason,
    })

    toast(data.message, 'success')
    walletDialog.value = false

    if (billingData.value) {
      billingData.value = null
      loadBilling()
    }
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.walletAdjustFailed'), 'error')
  }
  finally {
    walletLoading.value = false
  }
}

// ─── Suspend / Reactivate ───────────────────────────
const suspendDialog = ref(false)

const openSuspendDialog = () => {
  suspendDialog.value = true
}

const confirmSuspend = async () => {
  suspendDialog.value = false
  actionLoading.value = true

  try {
    const data = await companiesStore.suspendCompany(company.value.id)

    company.value = data.company
    toast(t('companies.companySuspended'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companies.failedToSuspend'), 'error')
  }
  finally {
    actionLoading.value = false
  }
}

const reactivate = async () => {
  actionLoading.value = true

  try {
    const data = await companiesStore.reactivateCompany(company.value.id)

    company.value = data.company
    toast(t('companies.companyReactivated'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('companies.failedToReactivate'), 'error')
  }
  finally {
    actionLoading.value = false
  }
}

// ─── Module groups ──────────────────────────────────
const coreModules = computed(() =>
  modules.value.filter(m => m.type === 'core'),
)

const includedModules = computed(() =>
  modules.value.filter(m => m.type !== 'core' && m.entitlement_source === 'jobdomain' && m.is_entitled),
)

const planGatedModules = computed(() =>
  modules.value.filter(m => m.type !== 'core' && m.entitlement_reason === 'plan_required'),
)

const availableModules = computed(() =>
  modules.value.filter(m => {
    if (m.type === 'core') return false
    if (m.entitlement_source === 'jobdomain' && m.is_entitled) return false
    if (m.entitlement_reason === 'plan_required') return false

    return true
  }),
)

const planLabel = planKey => {
  const labels = { pro: t('platformModules.pro'), business: t('platformModules.business') }

  return labels[planKey] || planKey
}

// ─── Module toggle ──────────────────────────────────
const moduleToggleLoading = ref(null)

const toggleModule = async mod => {
  moduleToggleLoading.value = mod.key

  try {
    let data
    if (mod.is_enabled_for_company) {
      data = await companiesStore.disableModule(company.value.id, mod.key)
    }
    else {
      data = await companiesStore.enableModule(company.value.id, mod.key)
    }

    modules.value = data.modules
    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('platformCompanyDetail.failedToToggleModule'), 'error')
  }
  finally {
    moduleToggleLoading.value = null
  }
}

const isModuleToggleDisabled = mod => {
  return mod.type === 'core' || !mod.is_entitled || !mod.is_enabled_globally
}

// ─── ADR-821 : synthèse ─────────────────────────────
const { moduleName } = useCatalogText()
const { locale } = useI18n()
const activeModulesList = computed(() => (modules.value || []).filter(m => m.is_enabled_for_company && m.type !== 'core'))
const summaryStats = computed(() => [
  { key: 'status', icon: company.value?.status === 'active' ? 'tabler-circle-check' : 'tabler-player-pause', color: company.value?.status === 'active' ? 'success' : 'warning',
    value: t(`platformCompanySummary.status.${company.value?.status || 'active'}`), label: t('platformCompanyDetail.companyStatus') },
  { key: 'members', icon: 'tabler-users', color: 'primary', value: company.value?.memberships_count ?? 0, label: t('platformCompanySummary.members') },
  { key: 'modules', icon: 'tabler-puzzle', color: 'info', value: activeModulesList.value.length, label: t('platformCompanySummary.modules') },
  { key: 'plan', icon: 'tabler-receipt', color: 'warning', value: plan.value?.name || company.value?.plan_key || '—', label: t('platformCompanySummary.subscription') },
])

// ─── Tabs definition ────────────────────────────────
const tabs = computed(() => [
  { value: 'overview', icon: 'tabler-layout-dashboard', title: t('platformCompanyDetail.overview') },
  { value: 'onboarding', icon: 'tabler-rocket', title: t('platformCompanyCreation.onboarding.title') }, // ADR-834
  { value: 'configuration', icon: 'tabler-settings', title: t('platformCompanySummary.configuration') },
  { value: 'billing', icon: 'tabler-receipt', title: t('platformCompanyDetail.billing.tab') },
  { value: 'modules', icon: 'tabler-puzzle', title: t('platformCompanyDetail.modules') },
  { value: 'members', icon: 'tabler-users', title: t('platformCompanyDetail.members.tab') },
  { value: 'activity', icon: 'tabler-history', title: t('platformCompanyDetail.activity.tab') },
].filter(tab => tabAllowed(tab.value)))
</script>

<template>
  <div>
    <!-- Loading -->
    <VCard v-if="isLoading" class="pa-8 text-center">
      <VProgressCircular indeterminate />
    </VCard>

    <template v-else-if="company">
      <!-- Back button -->
      <div class="mb-4">
        <VBtn
          variant="text"
          size="small"
          to="/platform/companies"
        >
          <VIcon icon="tabler-arrow-left" class="me-1" />
          {{ t('platformCompanyDetail.backToList') }}
        </VBtn>
      </div>

      <!-- 360° Layout: Bio Panel (left) + Tabs (right) -->
      <VRow>
        <!-- Left: Bio Panel -->
        <VCol cols="12" md="5" lg="4">
          <CompanyBioPanel
            :company="company"
            :plan="plan"
            :billing="billingData"
            :owner="owner"
            :members-count="company.memberships_count ?? 0"
            :incomplete-profiles-count="incompleteProfilesCount"
            :action-loading="actionLoading"
            :can-manage="canManage"
            @suspend="openSuspendDialog"
            @reactivate="reactivate"
          />
        </VCol>

        <!-- Right: Tabbed Content -->
        <VCol cols="12" md="7" lg="8">
          <VTabs v-model="activeTab" class="v-tabs-pill">
            <VTab
              v-for="tab in tabs"
              :key="tab.value"
              :value="tab.value"
            >
              <VIcon :icon="tab.icon" class="me-1" />
              <span>{{ tab.title }}</span>
            </VTab>
          </VTabs>

          <VWindow v-model="activeTab" class="mt-6 disable-tab-transition" :touch="false">
            <!-- ─── Synthèse (ADR-821) ──────────────── -->
            <VWindowItem value="overview">
              <VRow class="card-grid card-grid-xs mb-2">
                <VCol
                  v-for="k in summaryStats"
                  :key="k.key"
                  cols="6"
                  md="3"
                >
                  <VCard :data-test="`pc-stat-${k.key}`">
                    <VCardText class="d-flex align-center gap-3">
                      <VAvatar
                        :color="k.color"
                        variant="tonal"
                        rounded
                        :icon="k.icon"
                      />
                      <div>
                        <div class="text-h6">
                          {{ k.value }}
                        </div>
                        <div class="text-body-2 text-medium-emphasis">
                          {{ k.label }}
                        </div>
                      </div>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
              <VCard
                class="mb-6"
                data-test="pc-summary"
              >
                <VCardItem>
                  <VCardTitle>{{ t('platformCompanySummary.identityTitle') }}</VCardTitle>
                </VCardItem>
                <VList density="compact">
                  <VListItem
                    prepend-icon="tabler-briefcase"
                    :title="t('platformCompanySummary.jobdomain')"
                    :subtitle="(company.jobdomains || []).map(j => j.label).join(', ') || company.jobdomain_key || '—'"
                  />
                  <VListItem
                    prepend-icon="tabler-receipt"
                    :title="t('platformCompanySummary.subscription')"
                    :subtitle="plan?.name || company.plan_key || '—'"
                  >
                    <template
                      v-if="canViewAll"
                      #append
                    >
                      <VBtn
                        size="small"
                        variant="text"
                        @click="activeTab = 'billing'"
                      >
                        {{ t('platformCompanySummary.open') }}
                      </VBtn>
                    </template>
                  </VListItem>
                  <VListItem
                    prepend-icon="tabler-user-star"
                    :title="t('platformCompanySummary.owner')"
                    :subtitle="owner ? `${owner.first_name || ''} ${owner.last_name || ''}`.trim() || owner.email : '—'"
                  />
                  <VListItem
                    prepend-icon="tabler-world"
                    :title="t('platformCompanySummary.market')"
                    :subtitle="company.market_key || '—'"
                  />
                  <VListItem
                    prepend-icon="tabler-calendar"
                    :title="t('platformCompanySummary.created')"
                    :subtitle="company.created_at ? new Date(company.created_at).toLocaleDateString(locale) : '—'"
                  />
                </VList>
              </VCard>
              <VCard data-test="pc-active-modules">
                <VCardItem>
                  <VCardTitle>{{ t('platformCompanySummary.activeModules', { n: activeModulesList.length }) }}</VCardTitle>
                  <template
                    v-if="canViewAll"
                    #append
                  >
                    <VBtn
                      size="small"
                      variant="text"
                      @click="activeTab = 'modules'"
                    >
                      {{ t('platformCompanySummary.manage') }}
                    </VBtn>
                  </template>
                </VCardItem>
                <VCardText class="d-flex flex-wrap gap-2">
                  <VChip
                    v-for="m in activeModulesList"
                    :key="m.key"
                    size="small"
                    label
                    variant="tonal"
                    color="primary"
                  >
                    {{ moduleName(m.key, m.name) }}
                  </VChip>
                  <span
                    v-if="!activeModulesList.length"
                    class="text-disabled"
                  >—</span>
                </VCardText>
              </VCard>
            </VWindowItem>

            <!-- ─── Configuration (identité légale, adresses) ── -->
            <VWindowItem value="configuration">
              <!-- General info -->
              <VCard flat border>
                <VCardTitle class="text-subtitle-1 font-weight-medium">
                  {{ t('platformCompanyDetail.generalInfo') }}
                </VCardTitle>
                <VCardText>
                  <VRow>
                    <VCol cols="12" md="6">
                      <AppTextField
                        v-model="overviewForm.name"
                        :label="t('platformCompanyDetail.companyName')"
                      />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppTextField
                        :model-value="company.slug"
                        :label="t('common.slug')"
                        disabled
                      />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppTextField
                        :model-value="company.market_key || '—'"
                        :label="t('platformCompanyDetail.bio.market')"
                        disabled
                      />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppTextField
                        :model-value="company.legal_status_key || '—'"
                        :label="t('platformCompanyDetail.legalStatus')"
                        disabled
                      />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppSelect
                        v-model="planForm.plan_key"
                        :items="companiesStore.plans"
                        item-title="name"
                        item-value="key"
                        :label="t('companies.plan')"
                        :loading="actionLoading"
                        @update:model-value="requestPlanChange"
                      />
                    </VCol>
                    <VCol cols="12" md="6">
                      <AppTextField
                        :model-value="formatDate(company.created_at)"
                        :label="t('common.created')"
                        disabled
                      />
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>

              <!-- Fiscal info -->
              <VCard
                v-if="fiscalFields.length"
                flat
                border
                class="mt-4"
              >
                <VCardTitle class="text-subtitle-1 font-weight-medium">
                  {{ t('platformCompanyDetail.fiscalInfo') }}
                </VCardTitle>
                <VCardText>
                  <VRow>
                    <VCol
                      v-for="code in fiscalFields"
                      :key="code"
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        :model-value="dynamicForm[code]"
                        :label="fieldLabel(code)"
                        @update:model-value="dynamicForm[code] = $event"
                      />
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>

              <!-- Contact -->
              <VCard
                v-if="contactFields.length"
                flat
                border
                class="mt-4"
              >
                <VCardTitle class="text-subtitle-1 font-weight-medium">
                  {{ t('platformCompanyDetail.contactInfo') }}
                </VCardTitle>
                <VCardText>
                  <VRow>
                    <VCol
                      v-for="code in contactFields"
                      :key="code"
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        :model-value="dynamicForm[code]"
                        :label="fieldLabel(code)"
                        @update:model-value="dynamicForm[code] = $event"
                      />
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>

              <!-- Company address -->
              <VCard
                v-if="companyAddressFields.length"
                flat
                border
                class="mt-4"
              >
                <VCardTitle class="text-subtitle-1 font-weight-medium">
                  {{ t('platformCompanyDetail.companyAddress') }}
                </VCardTitle>
                <VCardText>
                  <VRow>
                    <VCol
                      v-for="code in companyAddressFields"
                      :key="code"
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        :model-value="dynamicForm[code]"
                        :label="fieldLabel(code)"
                        @update:model-value="dynamicForm[code] = $event"
                      />
                    </VCol>
                    <VCol
                      v-if="company?.market_key"
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        :model-value="company.market_key"
                        :label="t('platformCompanyDetail.country')"
                        disabled
                      />
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>

              <!-- Billing address -->
              <VCard
                v-if="billingAddressFields.length"
                flat
                border
                class="mt-4"
              >
                <VCardTitle class="text-subtitle-1 font-weight-medium">
                  {{ t('platformCompanyDetail.billingAddress') }}
                </VCardTitle>
                <VCardText>
                  <VRow>
                    <VCol
                      v-for="code in billingAddressFields"
                      :key="code"
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        :model-value="dynamicForm[code]"
                        :label="fieldLabel(code)"
                        @update:model-value="dynamicForm[code] = $event"
                      />
                    </VCol>
                    <VCol
                      v-if="company?.market_key"
                      cols="12"
                      md="6"
                    >
                      <AppTextField
                        :model-value="company.market_key"
                        :label="t('platformCompanyDetail.country')"
                        disabled
                      />
                    </VCol>
                  </VRow>
                </VCardText>
              </VCard>

              <!-- Save -->
              <div class="d-flex justify-end mt-4">
                <VBtn
                  color="primary"
                  :loading="overviewSaving"
                  @click="saveOverview"
                >
                  {{ t('common.save') }}
                </VBtn>
              </div>
            </VWindowItem>

            <!-- ─── Billing ───────────────────────── -->
            <VWindowItem value="billing">
              <CompanyBillingTab
                :billing="billingData"
                :loading="billingLoading"
                :company-id="company.id"
                @adjust-wallet="openWalletDialog"
                @refresh="refreshBilling"
              />
            </VWindowItem>

            <!-- ─── Modules ───────────────────────── -->
            <VWindowItem value="modules">
              <VCard flat border>
                <VCardTitle class="d-flex align-center">
                  <VIcon icon="tabler-puzzle" class="me-2" />
                  {{ t('platformCompanyDetail.companyModules') }}
                </VCardTitle>

                <!-- Core -->
                <template v-if="coreModules.length">
                  <VCardTitle class="text-body-1 mt-2">
                    <VIcon icon="tabler-shield-check" size="20" class="me-2" color="primary" />
                    {{ t('platformCompanyDetail.coreModules') }}
                  </VCardTitle>
                  <VCardSubtitle class="text-body-2 mb-2">
                    {{ t('platformCompanyDetail.coreModulesInfo') }}
                  </VCardSubtitle>
                  <VTable class="text-no-wrap">
                    <tbody>
                      <tr v-for="mod in coreModules" :key="mod.key">
                        <td class="font-weight-medium">
                          <RouterLink :to="{ name: 'platform-modules-key', params: { key: mod.key } }" class="text-high-emphasis text-decoration-none">
                            {{ mod.name }}
                          </RouterLink>
                          <VChip color="primary" size="x-small" variant="tonal" class="ms-2">
                            {{ t('platformCompanyDetail.coreChip') }}
                          </VChip>
                        </td>
                        <td class="text-medium-emphasis">
                          {{ mod.description }}
                        </td>
                        <td style="width: 100px;">
                          <VSwitch :model-value="true" density="compact" hide-details disabled />
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </template>

                <!-- Included by Jobdomain -->
                <template v-if="includedModules.length">
                  <VDivider class="my-2" />
                  <VCardTitle class="text-body-1">
                    <VIcon icon="tabler-check" size="20" class="me-2" color="success" />
                    {{ t('platformCompanyDetail.includedByJobDomain') }}
                  </VCardTitle>
                  <VCardSubtitle class="text-body-2 mb-2">
                    {{ t('platformCompanyDetail.includedByJobDomainInfo') }}
                  </VCardSubtitle>
                  <VTable class="text-no-wrap">
                    <tbody>
                      <tr v-for="mod in includedModules" :key="mod.key">
                        <td class="font-weight-medium">
                          <RouterLink :to="{ name: 'platform-modules-key', params: { key: mod.key } }" class="text-high-emphasis text-decoration-none">
                            {{ mod.name }}
                          </RouterLink>
                          <VChip color="success" size="x-small" variant="tonal" class="ms-2">
                            {{ t('platformCompanyDetail.includedChip') }}
                          </VChip>
                        </td>
                        <td class="text-medium-emphasis">
                          {{ mod.description }}
                        </td>
                        <td style="width: 100px;">
                          <VSwitch
                            :model-value="mod.is_enabled_for_company"
                            density="compact"
                            hide-details
                            :loading="moduleToggleLoading === mod.key"
                            :disabled="!canManage"
                            @update:model-value="toggleModule(mod)"
                          />
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </template>

                <!-- Addons souscrits -->
                <template v-if="addonSubscriptions.length">
                  <VDivider class="my-2" />
                  <VCardTitle class="text-body-1">
                    <VIcon icon="tabler-shopping-cart-check" size="20" class="me-2" color="info" />
                    {{ t('platformCompanyDetail.subscribedAddons') }}
                  </VCardTitle>
                  <VCardSubtitle class="text-body-2 mb-2">
                    {{ t('platformCompanyDetail.subscribedAddonsInfo') }}
                  </VCardSubtitle>
                  <VTable class="text-no-wrap">
                    <tbody>
                      <tr v-for="addon in addonSubscriptions" :key="addon.module_key">
                        <td class="font-weight-medium">
                          <RouterLink :to="{ name: 'platform-modules-key', params: { key: addon.module_key } }" class="text-high-emphasis text-decoration-none">
                            {{ addon.name }}
                          </RouterLink>
                          <VChip color="info" size="x-small" variant="tonal" class="ms-2">
                            {{ t('platformCompanyDetail.addonChip') }}
                          </VChip>
                        </td>
                        <td>
                          {{ formatMoney(addon.amount_cents, { currency: addon.currency || 'EUR' }) }}/{{ addon.interval === 'yearly' ? t('common.year') : t('common.month') }}
                        </td>
                        <td class="text-medium-emphasis" style="width: 130px;">
                          {{ formatDate(addon.activated_at) }}
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </template>

                <!-- Plan-gated -->
                <template v-if="planGatedModules.length">
                  <VDivider class="my-2" />
                  <VCardTitle class="text-body-1">
                    <VIcon icon="tabler-lock" size="20" class="me-2" color="warning" />
                    {{ t('platformCompanyDetail.planGatedModules') }}
                  </VCardTitle>
                  <VCardSubtitle class="text-body-2 mb-2">
                    {{ t('platformCompanyDetail.planGatedInfo') }}
                  </VCardSubtitle>
                  <VTable class="text-no-wrap">
                    <tbody>
                      <tr v-for="mod in planGatedModules" :key="mod.key" class="text-disabled">
                        <td class="font-weight-medium">
                          <RouterLink :to="{ name: 'platform-modules-key', params: { key: mod.key } }" class="text-decoration-none">
                            {{ mod.name }}
                          </RouterLink>
                          <VChip color="warning" size="x-small" variant="tonal" class="ms-2">
                            {{ t('platformCompanyDetail.requiresPlan', { plan: planLabel(mod.min_plan) }) }}
                          </VChip>
                        </td>
                        <td>{{ mod.description }}</td>
                        <td style="width: 100px;">
                          <VSwitch :model-value="false" density="compact" hide-details disabled />
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </template>

                <!-- Other -->
                <template v-if="availableModules.length">
                  <VDivider class="my-2" />
                  <VCardTitle class="text-body-1">
                    <VIcon icon="tabler-puzzle" size="20" class="me-2" color="secondary" />
                    {{ t('platformCompanyDetail.otherModules') }}
                  </VCardTitle>
                  <VCardSubtitle class="text-body-2 mb-2">
                    {{ t('platformCompanyDetail.otherModulesInfo') }}
                  </VCardSubtitle>
                  <VTable class="text-no-wrap">
                    <tbody>
                      <tr v-for="mod in availableModules" :key="mod.key" class="text-disabled">
                        <td class="font-weight-medium">
                          <RouterLink :to="{ name: 'platform-modules-key', params: { key: mod.key } }" class="text-decoration-none">
                            {{ mod.name }}
                          </RouterLink>
                          <VChip color="secondary" size="x-small" variant="tonal" class="ms-2">
                            {{ mod.entitlement_reason === 'incompatible_jobdomain' ? t('platformCompanyDetail.incompatible') : t('platformCompanyDetail.notAvailable') }}
                          </VChip>
                        </td>
                        <td>{{ mod.description }}</td>
                        <td style="width: 100px;">
                          <VSwitch :model-value="false" density="compact" hide-details disabled />
                        </td>
                      </tr>
                    </tbody>
                  </VTable>
                </template>

                <VCardText v-if="!modules.length" class="text-center text-disabled">
                  {{ t('platformCompanyDetail.noModulesAvailable') }}
                </VCardText>
              </VCard>
            </VWindowItem>

            <!-- ─── Members ───────────────────────── -->
            <VWindowItem value="members">
              <CompanyMembersTab
                :members="membersData"
                :loading="membersLoading"
                @refresh="refreshMembers"
              />
            </VWindowItem>

            <!-- ─── Start of service (ADR-834) ────── -->
            <VWindowItem value="onboarding">
              <CompanyOnboardingTab
                v-if="activeTab === 'onboarding'"
                :company-id="route.params.id"
                @go-tab="tab => activeTab = tab"
              />
            </VWindowItem>

            <!-- ─── Activity ──────────────────────── -->
            <VWindowItem value="activity">
              <CompanyActivityTab
                :logs="activityData"
                :loading="activityLoading"
                @refresh="refreshActivity"
              />
            </VWindowItem>
          </VWindow>
        </VCol>
      </VRow>
    </template>

    <!-- Plan Change Preview Dialog -->
    <VDialog
      v-model="showPlanPreview"
      max-width="600"
      persistent
    >
      <VCard>
        <VCardTitle>
          <VIcon icon="tabler-exchange" class="me-2" />
          {{ t('platformCompanyDetail.planPreview.title') }}
        </VCardTitle>

        <VCardText v-if="planPreviewLoading" class="text-center pa-6">
          <VProgressCircular indeterminate />
        </VCardText>

        <VCardText v-else-if="planPreview">
          <!-- Plan comparison -->
          <div class="d-flex align-center justify-space-between pa-3 rounded" style="background: rgb(var(--v-theme-surface));">
            <div class="text-center">
              <div class="text-caption text-medium-emphasis">
                {{ t('platformCompanyDetail.planPreview.currentPlan') }}
              </div>
              <div class="text-h6">
                {{ planPreview.from_plan?.name }}
              </div>
              <div class="text-body-2">
                {{ formatMoney(planPreview.from_plan?.price, { currency: planPreview.currency }) }}/{{ planPreview.from_plan?.interval === 'yearly' ? t('common.year') : t('common.month') }}
              </div>
            </div>
            <VIcon icon="tabler-arrow-right" size="24" />
            <div class="text-center">
              <div class="text-caption text-medium-emphasis">
                {{ t('platformCompanyDetail.planPreview.newPlan') }}
              </div>
              <div class="text-h6">
                {{ planPreview.to_plan?.name }}
              </div>
              <div class="text-body-2">
                {{ formatMoney(planPreview.to_plan?.price, { currency: planPreview.currency }) }}/{{ planPreview.to_plan?.interval === 'yearly' ? t('common.year') : t('common.month') }}
              </div>
            </div>
          </div>

          <!-- Timing badge -->
          <div class="mt-3 mb-4">
            <VChip
              :color="planPreview.is_upgrade ? 'success' : 'warning'"
              size="small"
            >
              {{ planPreview.is_upgrade ? t('platformCompanyDetail.planPreview.upgrade') : t('platformCompanyDetail.planPreview.downgrade') }}
            </VChip>
            <VChip size="small" variant="tonal" class="ms-2">
              {{ planPreview.timing === 'immediate' ? t('platformCompanyDetail.planPreview.timingImmediate') : t('platformCompanyDetail.planPreview.timingEndOfPeriod') }}
            </VChip>
          </div>

          <!-- Downgrade warning -->
          <VAlert
            v-if="!planPreview.is_upgrade"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            <template #title>
              {{ t('platformCompanyDetail.planPreview.downgradeWarningTitle') }}
            </template>
            {{ t('platformCompanyDetail.planPreview.downgradeWarningText') }}
          </VAlert>

          <!-- Wallet deduction highlight -->
          <VAlert
            v-if="planPreview.immediate?.wallet_deduction > 0"
            type="info"
            variant="tonal"
            density="compact"
            icon="tabler-wallet"
            class="mb-4"
          >
            {{ t('platformCompanyDetail.planPreview.walletDeductionAlert', { amount: formatMoney(planPreview.immediate.wallet_deduction, { currency: planPreview.currency }), balance: formatMoney(planPreview.wallet_balance, { currency: planPreview.currency }) }) }}
          </VAlert>

          <!-- Immediate billing details -->
          <template v-if="planPreview.immediate && planPreview.timing === 'immediate'">
            <h6 class="text-h6 mb-2">
              {{ t('platformCompanyDetail.planPreview.immediateBilling') }}
            </h6>

            <!-- Proration lines -->
            <template v-if="planPreview.proration">
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span class="text-success">
                  {{ t('platformCompanyDetail.planPreview.credit') }}
                  <span class="text-medium-emphasis">({{ planPreview.proration.days_remaining }}/{{ planPreview.proration.total_days }} {{ t('platformCompanyDetail.planPreview.days') }})</span>
                </span>
                <span class="text-success">
                  -{{ formatMoney(planPreview.proration.credit_old_plan, { currency: planPreview.currency }) }}
                </span>
              </div>
              <div class="d-flex justify-space-between text-body-2 mb-1">
                <span>{{ t('platformCompanyDetail.planPreview.charge') }}</span>
                <span>{{ formatMoney(planPreview.proration.charge_new_plan, { currency: planPreview.currency }) }}</span>
              </div>
            </template>

            <VDivider class="my-2" />

            <!-- Subtotal -->
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span>{{ t('platformCompanyDetail.planPreview.subtotal') }}</span>
              <span>{{ formatMoney(planPreview.immediate.subtotal, { currency: planPreview.currency }) }}</span>
            </div>

            <!-- Tax -->
            <div
              v-if="planPreview.immediate.tax_amount > 0"
              class="d-flex justify-space-between text-body-2 mb-1"
            >
              <span>{{ t('platformCompanyDetail.planPreview.tax') }} ({{ (planPreview.immediate.tax_rate_bps / 100).toFixed(1) }}%)</span>
              <span>{{ formatMoney(planPreview.immediate.tax_amount, { currency: planPreview.currency }) }}</span>
            </div>

            <VDivider class="my-2" />

            <!-- Total -->
            <div class="d-flex justify-space-between text-body-1 font-weight-bold mb-1">
              <span>{{ t('platformCompanyDetail.planPreview.total') }}</span>
              <span>{{ formatMoney(planPreview.immediate.total, { currency: planPreview.currency }) }}</span>
            </div>

            <!-- Wallet deduction -->
            <div
              v-if="planPreview.immediate.wallet_deduction > 0"
              class="d-flex justify-space-between text-body-2 text-success mb-1"
            >
              <span>{{ t('platformCompanyDetail.planPreview.walletDeduction') }}</span>
              <span>-{{ formatMoney(planPreview.immediate.wallet_deduction, { currency: planPreview.currency }) }}</span>
            </div>

            <!-- Wallet credit added (downgrade) -->
            <div
              v-if="planPreview.immediate.wallet_credit_added > 0"
              class="d-flex justify-space-between text-body-2 text-info mb-1"
            >
              <span>{{ t('platformCompanyDetail.planPreview.walletCreditAdded') }}</span>
              <span>+{{ formatMoney(planPreview.immediate.wallet_credit_added, { currency: planPreview.currency }) }}</span>
            </div>

            <!-- Amount due after wallet -->
            <div
              v-if="planPreview.immediate.estimated_amount_due !== planPreview.immediate.total"
              class="d-flex justify-space-between text-body-1 font-weight-bold mt-2"
            >
              <span>{{ t('platformCompanyDetail.planPreview.amountDue') }}</span>
              <span :class="planPreview.immediate.estimated_amount_due > 0 ? 'text-error' : 'text-success'">
                {{ formatMoney(Math.abs(planPreview.immediate.estimated_amount_due), { currency: planPreview.currency }) }}
              </span>
            </div>
          </template>

          <!-- End of period (deferred change) -->
          <template v-else>
            <VAlert type="info" variant="tonal" density="compact">
              {{ t('platformCompanyDetail.planPreview.noImmediateImpact') }}
            </VAlert>
          </template>

          <!-- Wallet balance -->
          <div
            v-if="planPreview.wallet_balance != null"
            class="d-flex justify-space-between text-body-2 pa-3 rounded mt-4"
            style="background: rgb(var(--v-theme-surface));"
          >
            <span class="text-medium-emphasis">
              <VIcon icon="tabler-wallet" size="16" class="me-1" />
              {{ t('platformCompanyDetail.planPreview.walletBalance') }}
            </span>
            <span class="font-weight-medium">
              {{ formatMoney(planPreview.wallet_balance, { currency: planPreview.currency }) }}
            </span>
          </div>

          <!-- Addons impact -->
          <template v-if="planPreview.addons?.length">
            <VDivider class="my-4" />
            <h6 class="text-h6 mb-2">
              {{ t('platformCompanyDetail.planPreview.addonsImpact') }}
            </h6>
            <div
              v-for="addon in planPreview.addons"
              :key="addon.module_key"
              class="d-flex justify-space-between text-body-2 mb-1"
            >
              <span>{{ addon.name }}</span>
              <span>
                {{ formatMoney(addon.current_amount, { currency: planPreview.currency }) }}
                → {{ formatMoney(addon.new_amount, { currency: planPreview.currency }) }}
                <VChip
                  v-if="addon.difference !== 0"
                  :color="addon.difference > 0 ? 'error' : 'success'"
                  size="x-small"
                  class="ms-1"
                >
                  {{ addon.difference > 0 ? '+' : '' }}{{ formatMoney(addon.difference, { currency: planPreview.currency }) }}
                </VChip>
              </span>
            </div>
          </template>

          <!-- Next period preview -->
          <template v-if="planPreview.next_period">
            <VDivider class="my-4" />
            <h6 class="text-h6 mb-2">
              {{ t('platformCompanyDetail.planPreview.nextPeriod') }}
              <span class="text-body-2 text-medium-emphasis font-weight-regular">
                ({{ planPreview.next_period.interval === 'yearly' ? t('platformCompanyDetail.planPreview.perYear') : t('platformCompanyDetail.planPreview.perMonth') }})
              </span>
            </h6>
            <div class="d-flex justify-space-between text-body-2 mb-1">
              <span>{{ planPreview.to_plan?.name }}</span>
              <span>{{ formatMoney(planPreview.next_period.plan_price, { currency: planPreview.currency }) }}</span>
            </div>
            <div
              v-if="planPreview.next_period.addons_total > 0"
              class="d-flex justify-space-between text-body-2 mb-1"
            >
              <span>{{ t('platformCompanyDetail.planPreview.addons') }}</span>
              <span>{{ formatMoney(planPreview.next_period.addons_total, { currency: planPreview.currency }) }}</span>
            </div>
            <div
              v-if="planPreview.next_period.tax_amount > 0"
              class="d-flex justify-space-between text-body-2 mb-1"
            >
              <span>{{ t('platformCompanyDetail.planPreview.tax') }} ({{ (planPreview.next_period.tax_rate_bps / 100).toFixed(1) }}%)</span>
              <span>{{ formatMoney(planPreview.next_period.tax_amount, { currency: planPreview.currency }) }}</span>
            </div>
            <VDivider class="my-2" />
            <div class="d-flex justify-space-between text-body-1 font-weight-bold">
              <span>{{ t('platformCompanyDetail.planPreview.total') }}</span>
              <span>{{ formatMoney(planPreview.next_period.total, { currency: planPreview.currency }) }}</span>
            </div>
          </template>
        </VCardText>

        <VCardText v-else>
          <VAlert type="warning" variant="tonal" density="compact">
            {{ t('platformCompanyDetail.planPreview.noSubscription') }}
          </VAlert>
        </VCardText>

        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="cancelPlanChange">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            :color="planPreview?.is_upgrade ? 'primary' : 'warning'"
            :loading="actionLoading"
            @click="confirmPlanChange"
          >
            <VIcon icon="tabler-check" class="me-1" />
            {{ planPreview?.is_upgrade ? t('platformCompanyDetail.planPreview.confirm') : t('platformCompanyDetail.planPreview.confirmDowngrade') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Wallet Adjustment Dialog -->
    <VDialog
      v-model="walletDialog"
      max-width="450"
    >
      <VCard>
        <VCardTitle>
          <VIcon icon="tabler-wallet" class="me-2" />
          {{ t('platformCompanyDetail.wallet.title') }}
        </VCardTitle>

        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppSelect
                v-model="walletForm.type"
                :items="[
                  { title: t('platformCompanyDetail.wallet.credit'), value: 'credit' },
                  { title: t('platformCompanyDetail.wallet.debit'), value: 'debit' },
                ]"
                :label="t('platformCompanyDetail.wallet.type')"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="walletForm.amount"
                :label="t('platformCompanyDetail.wallet.amount')"
                type="number"
                min="0.01"
                step="0.01"
                prefix="€"
              />
            </VCol>
            <VCol cols="12">
              <AppTextField
                v-model="walletForm.reason"
                :label="t('platformCompanyDetail.wallet.reason')"
              />
            </VCol>
          </VRow>
        </VCardText>

        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="walletDialog = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="walletLoading"
            :disabled="!walletForm.amount || !walletForm.reason"
            @click="submitWalletAdjustment"
          >
            {{ t('platformCompanyDetail.wallet.apply') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Suspend Confirmation Dialog -->
    <VDialog v-model="suspendDialog" max-width="500">
      <VCard>
        <VCardTitle class="text-warning">
          <VIcon icon="tabler-alert-triangle" class="me-2" />
          {{ t('platformCompanyDetail.suspend.title') }}
        </VCardTitle>
        <VCardText>
          <p class="text-body-1 mb-3">
            {{ t('platformCompanyDetail.suspend.message', { name: company?.name }) }}
          </p>
          <VList density="compact" class="pa-0">
            <VListItem v-if="billingData?.subscription">
              <template #prepend>
                <VIcon icon="tabler-credit-card" size="20" class="me-2" color="warning" />
              </template>
              <VListItemTitle class="text-body-2">
                {{ t('platformCompanyDetail.suspend.activeSubscription', { status: billingData.subscription.status }) }}
              </VListItemTitle>
            </VListItem>
            <VListItem v-if="billingData?.dunning_invoices?.length">
              <template #prepend>
                <VIcon icon="tabler-file-invoice" size="20" class="me-2" color="error" />
              </template>
              <VListItemTitle class="text-body-2">
                {{ t('platformCompanyDetail.suspend.unpaidInvoices', { count: billingData.dunning_invoices.length }) }}
              </VListItemTitle>
            </VListItem>
            <VListItem>
              <template #prepend>
                <VIcon icon="tabler-users-minus" size="20" class="me-2" color="error" />
              </template>
              <VListItemTitle class="text-body-2">
                {{ t('platformCompanyDetail.suspend.membersBlocked') }}
              </VListItemTitle>
            </VListItem>
          </VList>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="suspendDialog = false">
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn color="warning" :loading="actionLoading" @click="confirmSuspend">
            {{ t('companies.suspend') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
