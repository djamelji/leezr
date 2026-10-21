<script setup>
import { usePlatformPlansStore } from '@/modules/platform-admin/plans/plans.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatDate } from '@/utils/datetime'
import { formatMoney } from '@/utils/money'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.plans',
    permission: 'manage_plans',
    navActiveLink: 'platform-catalog-tab',
  },
})

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const plansStore = usePlatformPlansStore()
const { toast } = useAppToast()

const isLoading = ref(true)
const saveLoading = ref(false)
const featuresLoading = ref(false)
const limitsLoading = ref(false)

// Commercial info form
const form = ref({
  key: '',
  name: '',
  description: '',
  level: 0,
  price_monthly: 0,
  price_yearly: 0,
  is_popular: false,
  is_active: true,
  trial_days: 0,
})

// Features
const featureLabels = ref([])
const newFeature = ref('')

// Limits
const memberLimit = ref(null)
const storageQuotaLimit = ref(null)

onMounted(async () => {
  try {
    await plansStore.fetchPlan(route.params.key)
    hydrateForm()
  }
  catch {
    toast(t('plans.planNotFound'), 'error')
    router.push({ name: 'platform-catalog-tab', params: { tab: 'plans' } })
  }
  finally {
    isLoading.value = false
  }
})

const hydrateForm = () => {
  const plan = plansStore.currentPlan
  if (!plan)
    return

  form.value = {
    key: plan.key,
    name: plan.name,
    description: plan.description || '',
    level: plan.level,
    price_monthly: plan.price_monthly_dollars ?? plan.price_monthly / 100,
    price_yearly: plan.price_yearly_dollars ?? plan.price_yearly / 100,
    is_popular: plan.is_popular,
    is_active: plan.is_active,
    trial_days: plan.trial_days ?? 0,
  }
  featureLabels.value = [...(plan.feature_labels || [])]
  memberLimit.value = plan.limits?.members ?? null
  storageQuotaLimit.value = plan.limits?.storage_quota_gb ?? null
}

// Section 1: Save commercial info — with public-price governance (ADR-588).
const pricingDialog = ref(false)
const pricingImpact = ref(null)
const pricingLoading = ref(false)

const doSaveCommercialInfo = async () => {
  saveLoading.value = true
  try {
    const payload = {
      ...form.value,
      feature_labels: featureLabels.value,
      limits: buildLimits(),
    }

    const data = await plansStore.updatePlan(plansStore.currentPlan.id, payload)

    toast(data.message, 'success')
    hydrateForm()
  }
  catch (error) {
    toast(error?.data?.message || t('plans.failedToSave'), 'error')
  }
  finally {
    saveLoading.value = false
  }
}

const saveCommercialInfo = async () => {
  // If the PUBLIC price changes, surface the governance impact (existing subscribers keep their
  // contracted price — ADR-587/588) and require explicit confirmation before publishing.
  const plan = plansStore.currentPlan
  const newMonthlyCents = Math.round((form.value.price_monthly || 0) * 100)
  const priceChanged = plan && newMonthlyCents !== plan.price_monthly

  if (!priceChanged) {
    await doSaveCommercialInfo()

    return
  }

  pricingLoading.value = true
  pricingDialog.value = true
  try {
    pricingImpact.value = await plansStore.fetchPricingImpact(plan.key, 'monthly', newMonthlyCents)
  }
  catch {
    pricingImpact.value = null
  }
  finally {
    pricingLoading.value = false
  }
}

const confirmPriceChange = async () => {
  pricingDialog.value = false
  await doSaveCommercialInfo()
}

const fmtCents = cents => formatMoney(cents ?? 0, { currency: pricingImpact.value?.by_currency?.[0]?.currency || 'EUR' })

// Section 2: Features
const addFeature = () => {
  const trimmed = newFeature.value.trim()
  if (trimmed && !featureLabels.value.includes(trimmed)) {
    featureLabels.value.push(trimmed)
    newFeature.value = ''
  }
}

const removeFeature = index => {
  featureLabels.value.splice(index, 1)
}

const moveFeature = (index, direction) => {
  const target = index + direction
  if (target < 0 || target >= featureLabels.value.length)
    return

  const items = [...featureLabels.value]
  const [item] = items.splice(index, 1)

  items.splice(target, 0, item)
  featureLabels.value = items
}

const saveFeatures = async () => {
  featuresLoading.value = true

  try {
    const payload = {
      ...form.value,
      feature_labels: featureLabels.value,
      limits: buildLimits(),
    }

    await plansStore.updatePlan(plansStore.currentPlan.id, payload)

    toast(t('plans.featuresUpdated'), 'success')
    hydrateForm()
  }
  catch (error) {
    toast(error?.data?.message || t('plans.failedToSaveFeatures'), 'error')
  }
  finally {
    featuresLoading.value = false
  }
}

// Section 3: Limits
const buildLimits = () => {
  const limits = {}
  if (memberLimit.value !== null && memberLimit.value !== '')
    limits.members = Number(memberLimit.value)
  if (storageQuotaLimit.value !== null && storageQuotaLimit.value !== '')
    limits.storage_quota_gb = Number(storageQuotaLimit.value)

  return Object.keys(limits).length ? limits : null
}

const saveLimits = async () => {
  limitsLoading.value = true

  try {
    const payload = {
      ...form.value,
      feature_labels: featureLabels.value,
      limits: buildLimits(),
    }

    await plansStore.updatePlan(plansStore.currentPlan.id, payload)

    toast(t('plans.limitsUpdated'), 'success')
    hydrateForm()
  }
  catch (error) {
    toast(error?.data?.message || t('plans.failedToSaveLimits'), 'error')
  }
  finally {
    limitsLoading.value = false
  }
}

// Section 4: Companies pagination
const onCompaniesPageChange = async page => {
  try {
    await plansStore.fetchPlan(route.params.key, page)
  }
  catch {
    toast(t('plans.failedToLoadCompanies'), 'error')
  }
}

const statusColor = status => {
  const colors = {
    pending: 'warning',
    active: 'success',
    cancelled: 'error',
    expired: 'secondary',
  }

  return colors[status] || 'default'
}

const statusLabel = status => {
  const key = `subscriptionStatus.${status}`
  const translated = t(key)

  return translated !== key ? translated : (status || '—')
}

const fmtDate = dateStr => {
  if (!dateStr)
    return '—'

  return formatDate(dateStr)
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center gap-2 mb-6">
      <VBtn
        icon
        variant="text"
        size="small"
        @click="router.push({ name: 'platform-catalog-tab', params: { tab: 'plans' } })"
      >
        <VIcon icon="tabler-arrow-left" />
      </VBtn>
      <div>
        <h4 class="text-h4">
          {{ plansStore.currentPlan?.name || t('Plan') }}
        </h4>
        <p class="text-body-2 text-disabled mb-0">
          {{ route.params.key }}
        </p>
      </div>
    </div>

    <VSkeletonLoader
      v-if="isLoading"
      type="card, card, card"
    />

    <template v-else>
      <!-- Section 1: Commercial Info -->
      <VCard class="mb-6">
        <VCardTitle>
          <VIcon
            icon="tabler-tag"
            class="me-2"
          />
          {{ t('plans.commercialInfo') }}
        </VCardTitle>

        <VCardText>
          <VForm @submit.prevent="saveCommercialInfo">
            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model="form.name"
                  :label="t('plans.planName')"
                  placeholder="e.g. Professional"
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model.number="form.level"
                  :label="t('plans.displayOrder')"
                  type="number"
                  :hint="t('plans.displayOrderHint')"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <AppTextarea
                  v-model="form.description"
                  :label="t('common.description')"
                  :placeholder="t('plans.descriptionPlaceholder')"
                  rows="2"
                />
              </VCol>

              <VCol
                cols="12"
                sm="6"
                md="3"
              >
                <AppTextField
                  v-model.number="form.price_monthly"
                  :label="t('plans.monthlyPriceHT')"
                  type="number"
                  step="0.01"
                  min="0"
                  data-test="plan-price-monthly"
                />
              </VCol>

              <VCol
                cols="12"
                sm="6"
                md="3"
              >
                <AppTextField
                  v-model.number="form.price_yearly"
                  :label="t('plans.yearlyPriceHT')"
                  type="number"
                  step="0.01"
                  min="0"
                />
              </VCol>

              <VCol
                cols="12"
                sm="6"
                md="3"
              >
                <AppTextField
                  v-model.number="form.trial_days"
                  :label="t('plans.trialDays')"
                  :hint="t('plans.trialDaysHint')"
                  persistent-hint
                  type="number"
                  min="0"
                  max="365"
                  :suffix="t('plans.trialDaysSuffix')"
                />
              </VCol>

              <VCol
                cols="12"
                sm="6"
                md="3"
              >
                <VSwitch
                  v-model="form.is_popular"
                  :label="t('plans.popularBadge')"
                  class="mt-2"
                />
              </VCol>

              <VCol
                cols="12"
                sm="6"
                md="3"
              >
                <VSwitch
                  v-model="form.is_active"
                  :label="form.is_active ? t('common.active') : t('common.hidden')"
                  class="mt-2"
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  type="submit"
                  :loading="saveLoading"
                  data-test="plan-save-commercial"
                >
                  {{ t('common.saveChanges') }}
                </VBtn>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>

      <!-- Section 2: Marketing Features -->
      <VCard class="mb-6">
        <VCardTitle>
          <VIcon
            icon="tabler-list-check"
            class="me-2"
          />
          {{ t('plans.marketingFeatures') }}
        </VCardTitle>

        <VCardText>
          <!-- Feature list -->
          <div
            v-if="featureLabels.length"
            class="mb-4"
          >
            <VCard
              v-for="(feature, index) in featureLabels"
              :key="index"
              flat
              border
              class="mb-2"
            >
              <div class="d-flex align-center pa-3">
                <VAvatar
                  size="24"
                  variant="tonal"
                  color="primary"
                  class="me-3"
                >
                  <VIcon
                    icon="tabler-check"
                    size="14"
                  />
                </VAvatar>

                <span class="flex-grow-1 text-body-1">{{ feature }}</span>

                <IconBtn
                  size="small"
                  :disabled="index === 0"
                  @click="moveFeature(index, -1)"
                >
                  <VIcon
                    icon="tabler-arrow-up"
                    size="18"
                  />
                </IconBtn>

                <IconBtn
                  size="small"
                  :disabled="index === featureLabels.length - 1"
                  @click="moveFeature(index, 1)"
                >
                  <VIcon
                    icon="tabler-arrow-down"
                    size="18"
                  />
                </IconBtn>

                <IconBtn
                  size="small"
                  color="error"
                  @click="removeFeature(index)"
                >
                  <VIcon
                    icon="tabler-x"
                    size="18"
                  />
                </IconBtn>
              </div>
            </VCard>
          </div>

          <div
            v-else
            class="text-center text-disabled text-body-2 mb-4 pa-4"
          >
            {{ t('plans.noFeaturesAdded') }}
          </div>

          <!-- Add feature -->
          <div class="d-flex gap-3 align-center mb-4">
            <AppTextField
              v-model="newFeature"
              :label="t('plans.newFeature')"
              :placeholder="t('plans.newFeaturePlaceholder')"
              class="flex-grow-1"
              @keyup.enter="addFeature"
            />
            <VBtn
              variant="tonal"
              :disabled="!newFeature.trim()"
              @click="addFeature"
            >
              {{ t('common.add') }}
            </VBtn>
          </div>

          <VBtn
            :loading="featuresLoading"
            @click="saveFeatures"
          >
            {{ t('plans.saveFeatures') }}
          </VBtn>
        </VCardText>
      </VCard>

      <!-- Section 3: Technical Limits -->
      <VExpansionPanels class="mb-6">
        <VExpansionPanel>
          <VExpansionPanelTitle>
            <VIcon
              icon="tabler-settings"
              class="me-2"
            />
            {{ t('plans.technicalLimits') }}
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <VAlert
              type="warning"
              variant="tonal"
              class="mb-4"
            >
              {{ t('plans.technicalLimitsWarning') }}
            </VAlert>

            <VRow>
              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model.number="memberLimit"
                  :label="t('plans.memberLimit')"
                  type="number"
                  min="1"
                  :hint="t('plans.memberLimitHint')"
                  persistent-hint
                  clearable
                />
              </VCol>

              <VCol
                cols="12"
                md="6"
              >
                <AppTextField
                  v-model.number="storageQuotaLimit"
                  :label="t('plans.storageQuotaLimit')"
                  type="number"
                  min="1"
                  :hint="t('plans.storageQuotaLimitHint')"
                  persistent-hint
                  clearable
                />
              </VCol>

              <VCol cols="12">
                <VBtn
                  :loading="limitsLoading"
                  @click="saveLimits"
                >
                  {{ t('plans.saveLimits') }}
                </VBtn>
              </VCol>
            </VRow>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>

      <!-- Section 4: Companies on this Plan -->
      <VCard>
        <VCardTitle>
          <VIcon
            icon="tabler-building"
            class="me-2"
          />
          {{ t('plans.companiesOnPlan') }}
          <VChip
            v-if="plansStore.currentPlan?.companies_count"
            size="small"
            color="primary"
            variant="tonal"
            class="ms-2"
          >
            {{ plansStore.currentPlan.companies_count }}
          </VChip>
        </VCardTitle>

        <VDataTable
          :headers="[
            { title: t('payments.company'), key: 'name' },
            { title: t('plans.subscription'), key: 'subscription_status', align: 'center', width: '160px' },
            { title: t('plans.periodStart'), key: 'period_start', width: '140px' },
            { title: t('plans.periodEnd'), key: 'period_end', width: '140px' },
            { title: '', key: 'actions', align: 'center', width: '100px', sortable: false },
          ]"
          :items="plansStore.planCompanies"
          :items-per-page="-1"
          hide-default-footer
          hover
        >
          <template #item.name="{ item }">
            {{ item.name }}
          </template>

          <template #item.subscription_status="{ item }">
            <VChip
              v-if="item.subscriptions?.length"
              :color="statusColor(item.subscriptions[0].status)"
              size="small"
            >
              {{ statusLabel(item.subscriptions[0].status) }}
            </VChip>
            <span
              v-else
              class="text-disabled"
            >—</span>
          </template>

          <template #item.period_start="{ item }">
            {{ item.subscriptions?.length ? fmtDate(item.subscriptions[0].current_period_start) : '—' }}
          </template>

          <template #item.period_end="{ item }">
            {{ item.subscriptions?.length ? fmtDate(item.subscriptions[0].current_period_end) : '—' }}
          </template>

          <template #item.actions="{ item }">
            <VBtn
              variant="tonal"
              size="small"
              :to="{ name: 'platform-companies-id', params: { id: item.id } }"
            >
              {{ t('common.view') }}
            </VBtn>
          </template>

          <template #no-data>
            <div class="text-center pa-6 text-disabled">
              <VIcon
                icon="tabler-building-off"
                size="48"
                class="mb-2"
              />
              <p class="text-body-1">
                {{ t('plans.noCompaniesOnPlan') }}
              </p>
            </div>
          </template>
        </VDataTable>

        <VCardText
          v-if="plansStore.planCompaniesPagination.last_page > 1"
          class="d-flex justify-center"
        >
          <VPagination
            :model-value="plansStore.planCompaniesPagination.current_page"
            :length="plansStore.planCompaniesPagination.last_page"
            @update:model-value="onCompaniesPageChange"
          />
        </VCardText>
      </VCard>
    </template>

    <!-- ADR-588: public price-change governance confirmation -->
    <VDialog
      v-model="pricingDialog"
      max-width="560"
      data-test="price-governance-dialog"
    >
      <VCard>
        <VCardItem>
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="tabler-report-money" color="warning" />
            {{ t('plans.pricing.confirmTitle') }}
          </VCardTitle>
        </VCardItem>
        <VDivider />
        <VCardText>
          <VSkeletonLoader v-if="pricingLoading" type="paragraph" />
          <template v-else-if="pricingImpact">
            <div class="d-flex align-center gap-3 mb-4">
              <div>
                <div class="text-caption text-medium-emphasis">{{ t('plans.pricing.current') }}</div>
                <div class="text-h6" data-test="pg-current">{{ fmtCents(pricingImpact.current_public_cents) }}</div>
              </div>
              <VIcon icon="tabler-arrow-right" class="text-disabled" />
              <div>
                <div class="text-caption text-medium-emphasis">{{ t('plans.pricing.proposed') }}</div>
                <div class="text-h6 text-primary" data-test="pg-proposed">{{ fmtCents(pricingImpact.proposed_cents) }}</div>
              </div>
            </div>

            <VAlert
              type="info"
              variant="tonal"
              density="comfortable"
              data-test="pg-impact"
            >
              {{ t('plans.pricing.impactMessage', { count: pricingImpact.affected_subscriptions }) }}
            </VAlert>

            <div class="d-flex gap-2 mt-3 flex-wrap">
              <VChip size="small" color="primary" variant="tonal">{{ t('plans.pricing.tagPublic') }}</VChip>
              <VChip size="small" color="success" variant="tonal">{{ t('plans.pricing.tagContracted') }}</VChip>
            </div>
          </template>
          <VAlert v-else type="error" variant="tonal">{{ t('common.loadError') }}</VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="justify-end">
          <VBtn variant="text" @click="pricingDialog = false">{{ t('common.cancel') }}</VBtn>
          <VBtn
            color="primary"
            :loading="saveLoading"
            data-test="pg-confirm"
            @click="confirmPriceChange"
          >
            {{ t('plans.pricing.confirmPublish') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
