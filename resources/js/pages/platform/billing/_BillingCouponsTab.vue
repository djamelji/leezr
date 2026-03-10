<script setup>
import EmptyState from '@/core/components/EmptyState.vue'
import { usePlatformPlansStore } from '@/modules/platform-admin/plans/plans.store'
import { usePlatformSettingsStore } from '@/modules/platform-admin/settings/settings.store'
import { formatMoney } from '@/utils/money'
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()
const { toast } = useAppToast()

const plansStore = usePlatformPlansStore()
const settingsStore = usePlatformSettingsStore()

const coupons = ref([])
const isLoading = ref(true)
const loadError = ref(false)
const isDrawerOpen = ref(false)
const editingCoupon = ref(null)
const isSaving = ref(false)
const isDeleting = ref(null)

const defaultForm = {
  code: '',
  name: '',
  description: '',
  type: 'percentage',
  value: null,
  duration_months: null,
  max_uses: null,
  max_uses_per_company: null,
  applicable_plan_keys: [],
  applicable_billing_cycles: [],
  applicable_addon_keys: [],
  addon_mode: null,
  first_purchase_only: false,
  starts_at: null,
  expires_at: null,
  is_active: true,
}

const form = ref({ ...defaultForm })

const headers = computed(() => [
  { title: t('coupons.code'), key: 'code', width: '140px' },
  { title: t('coupons.name'), key: 'name' },
  { title: t('coupons.type'), key: 'type', width: '130px' },
  { title: t('coupons.value'), key: 'value', align: 'end', width: '130px' },
  { title: t('coupons.usage'), key: 'usage', align: 'center', width: '120px' },
  { title: t('coupons.status'), key: 'status', align: 'center', width: '120px' },
  { title: t('coupons.expiresAt'), key: 'expires_at', width: '140px' },
  { title: t('coupons.actions'), key: 'actions', sortable: false, align: 'center', width: '100px' },
])

const typeOptions = computed(() => [
  { title: t('coupons.typePercentage'), value: 'percentage' },
  { title: t('coupons.typeFixedAmount'), value: 'fixed_amount' },
])

const durationOptions = computed(() => [
  { title: t('coupons.durationOnce'), value: null },
  { title: t('coupons.durationForever'), value: 0 },
  { title: t('coupons.durationNMonths', { n: 3 }), value: 3 },
  { title: t('coupons.durationNMonths', { n: 6 }), value: 6 },
  { title: t('coupons.durationNMonths', { n: 12 }), value: 12 },
])

const addonModeOptions = computed(() => [
  { title: t('coupons.addonModeNone'), value: null },
  { title: t('coupons.addonModeInclude'), value: 'include' },
  { title: t('coupons.addonModeExclude'), value: 'exclude' },
])

const planOptions = computed(() =>
  plansStore.plans.map(p => ({
    title: p.name,
    value: p.key,
  })),
)

const addonOptions = computed(() =>
  settingsStore.modules
    .filter(m => m.addon_pricing !== null && m.addon_pricing !== undefined)
    .map(m => ({
      title: m.name,
      value: m.key,
    })),
)

const couponStatus = coupon => {
  if (!coupon.is_active) return 'inactive'
  if (coupon.expires_at && new Date(coupon.expires_at) < new Date()) return 'expired'
  if (coupon.max_uses && coupon.used_count >= coupon.max_uses) return 'exhausted'
  if (coupon.starts_at && new Date(coupon.starts_at) > new Date()) return 'scheduled'

  return 'active'
}

const statusColorMap = {
  active: 'success',
  inactive: 'secondary',
  expired: 'warning',
  exhausted: 'error',
  scheduled: 'info',
}

const formatValue = coupon => {
  if (coupon.type === 'percentage') return `${(coupon.value / 100).toFixed(0)}%`

  return formatMoney(coupon.value)
}

const summaryText = computed(() => {
  const f = form.value
  if (!f.value || f.value <= 0) return ''

  const parts = [t('coupons.summaryPrefix')]

  if (f.type === 'percentage') {
    parts.push(t('coupons.summaryPercent', { value: (f.value / 100).toFixed(0) }))
  }
  else {
    parts.push(t('coupons.summaryFixed', { value: formatMoney(f.value) }))
  }

  if (f.applicable_plan_keys?.length > 0) {
    parts.push(t('coupons.summarySpecificPlans', { plans: f.applicable_plan_keys.join(', ') }))
  }
  else {
    parts.push(t('coupons.summaryAllPlans'))
  }

  if (f.duration_months === 0) {
    parts.push(t('coupons.summaryForever'))
  }
  else if (f.duration_months && f.duration_months > 0) {
    parts.push(t('coupons.summaryMonths', { n: f.duration_months }))
  }
  else {
    parts.push(t('coupons.summaryOnce'))
  }

  return parts.join(' ')
})

const fetchCoupons = async () => {
  isLoading.value = true
  loadError.value = false
  try {
    const data = await $platformApi('/billing/coupons')

    coupons.value = data.coupons
  }
  catch {
    loadError.value = true
    toast(t('common.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const openCreate = () => {
  editingCoupon.value = null
  form.value = { ...defaultForm, applicable_plan_keys: [], applicable_billing_cycles: [], applicable_addon_keys: [] }
  isDrawerOpen.value = true
}

const openEdit = coupon => {
  editingCoupon.value = coupon
  form.value = {
    code: coupon.code,
    name: coupon.name,
    description: coupon.description || '',
    type: coupon.type,
    value: coupon.value,
    duration_months: coupon.duration_months,
    max_uses: coupon.max_uses,
    max_uses_per_company: coupon.max_uses_per_company,
    applicable_plan_keys: coupon.applicable_plan_keys || [],
    applicable_billing_cycles: coupon.applicable_billing_cycles || [],
    applicable_addon_keys: coupon.applicable_addon_keys || [],
    addon_mode: coupon.addon_mode,
    first_purchase_only: coupon.first_purchase_only || false,
    starts_at: coupon.starts_at?.slice(0, 10) || null,
    expires_at: coupon.expires_at?.slice(0, 10) || null,
    is_active: coupon.is_active,
  }
  isDrawerOpen.value = true
}

const saveCoupon = async () => {
  isSaving.value = true
  try {
    if (editingCoupon.value) {
      await $platformApi(`/billing/coupons/${editingCoupon.value.id}`, {
        method: 'PUT',
        body: form.value,
      })
    }
    else {
      await $platformApi('/billing/coupons', {
        method: 'POST',
        body: form.value,
      })
    }
    isDrawerOpen.value = false
    toast(editingCoupon.value ? t('coupons.updated') : t('coupons.created'), 'success')
    await fetchCoupons()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const deleteCoupon = async coupon => {
  if (!confirm(t('coupons.confirmDelete'))) return

  isDeleting.value = coupon.id
  try {
    await $platformApi(`/billing/coupons/${coupon.id}`, { method: 'DELETE' })
    toast(t('coupons.deleted'), 'success')
    await fetchCoupons()
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    isDeleting.value = null
  }
}

onMounted(async () => {
  await Promise.all([
    fetchCoupons(),
    plansStore.fetchPlans(),
    settingsStore.fetchModules(),
  ])
})
</script>

<template>
  <VCard>
    <VCardText>
      <div class="d-flex align-center justify-space-between mb-4">
        <h6 class="text-h6">
          {{ t('coupons.title') }}
        </h6>
        <VBtn
          color="primary"
          prepend-icon="tabler-plus"
          @click="openCreate"
        >
          {{ t('coupons.create') }}
        </VBtn>
      </div>

      <VProgressLinear
        v-if="isLoading"
        indeterminate
        class="mb-4"
      />

      <VAlert
        v-else-if="loadError"
        type="error"
        variant="tonal"
        class="mb-4"
      >
        {{ t('common.loadError') }}
        <template #append>
          <VBtn
            variant="text"
            size="small"
            @click="fetchCoupons"
          >
            {{ t('common.retry') }}
          </VBtn>
        </template>
      </VAlert>

      <EmptyState
        v-else-if="coupons.length === 0"
        icon="tabler-ticket"
        :title="t('coupons.emptyTitle')"
        :description="t('coupons.emptyDesc')"
        :cta-label="t('coupons.create')"
        @click="openCreate"
      />

      <VDataTable
        v-else
        :headers="headers"
        :items="coupons"
        :items-per-page="-1"
        density="comfortable"
        hover
      >
        <template #item.code="{ item }">
          <code class="text-body-2 font-weight-bold">{{ item.code }}</code>
        </template>

        <template #item.value="{ item }">
          {{ formatValue(item) }}
        </template>

        <template #item.usage="{ item }">
          {{ item.used_count }}{{ item.max_uses ? ` / ${item.max_uses}` : '' }}
        </template>

        <template #item.status="{ item }">
          <VChip
            :color="statusColorMap[couponStatus(item)]"
            size="small"
          >
            {{ t(`coupons.status_${couponStatus(item)}`) }}
          </VChip>
        </template>

        <template #item.expires_at="{ item }">
          {{ item.expires_at ? new Date(item.expires_at).toLocaleDateString() : '—' }}
        </template>

        <template #item.actions="{ item }">
          <VBtn
            icon
            variant="text"
            size="small"
            @click="openEdit(item)"
          >
            <VIcon icon="tabler-pencil" />
          </VBtn>
          <VBtn
            icon
            variant="text"
            size="small"
            color="error"
            :loading="isDeleting === item.id"
            @click="deleteCoupon(item)"
          >
            <VIcon icon="tabler-trash" />
          </VBtn>
        </template>
      </VDataTable>
    </VCardText>
  </VCard>

  <!-- Drawer create/edit — 6 sections -->
  <Teleport to="body">
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="420"
    >
      <VCard
        flat
        class="d-flex flex-column"
        style="block-size: 100%"
      >
        <VCardTitle class="d-flex align-center justify-space-between">
          <span>{{ editingCoupon ? t('coupons.edit') : t('coupons.create') }}</span>
          <VBtn
            icon
            variant="text"
            size="small"
            @click="isDrawerOpen = false"
          >
            <VIcon icon="tabler-x" />
          </VBtn>
        </VCardTitle>

        <VCardText style="overflow-y: auto; flex: 1">
          <VForm @submit.prevent="saveCoupon">
            <!-- Section 1: Information -->
            <h6 class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center gap-2">
              <VIcon
                icon="tabler-info-circle"
                size="20"
              />
              {{ t('coupons.sectionInfo') }}
            </h6>

            <AppTextField
              v-model="form.code"
              :label="t('coupons.code')"
              :disabled="!!editingCoupon"
              :rules="[v => !!v || t('validation.required')]"
              class="mb-4"
            />

            <AppTextField
              v-model="form.name"
              :label="t('coupons.name')"
              :rules="[v => !!v || t('validation.required')]"
              class="mb-4"
            />

            <AppTextarea
              v-model="form.description"
              :label="t('coupons.description')"
              :hint="t('coupons.descriptionHint')"
              rows="2"
              class="mb-4"
            />

            <VDivider class="mb-4" />

            <!-- Section 2: Discount -->
            <h6 class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center gap-2">
              <VIcon
                icon="tabler-discount-2"
                size="20"
              />
              {{ t('coupons.sectionDiscount') }}
            </h6>

            <VRadioGroup
              v-model="form.type"
              :disabled="!!editingCoupon"
              inline
              class="mb-4"
            >
              <VRadio
                :label="t('coupons.typePercentage')"
                value="percentage"
              />
              <VRadio
                :label="t('coupons.typeFixedAmount')"
                value="fixed_amount"
              />
            </VRadioGroup>

            <AppTextField
              v-model.number="form.value"
              :label="form.type === 'percentage' ? t('coupons.valueBps') : t('coupons.valueCents')"
              type="number"
              :rules="[v => (v !== null && v > 0) || t('validation.required')]"
              class="mb-4"
            />

            <AppSelect
              v-model="form.duration_months"
              :label="t('coupons.durationMonths')"
              :items="durationOptions"
              class="mb-4"
            />

            <VDivider class="mb-4" />

            <!-- Section 3: Validity -->
            <h6 class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center gap-2">
              <VIcon
                icon="tabler-calendar-check"
                size="20"
              />
              {{ t('coupons.sectionValidity') }}
            </h6>

            <AppDateTimePicker
              v-model="form.starts_at"
              :label="t('coupons.startsAt')"
              class="mb-4"
            />

            <AppDateTimePicker
              v-model="form.expires_at"
              :label="t('coupons.expiresAt')"
              class="mb-4"
            />

            <VSwitch
              v-model="form.is_active"
              :label="t('coupons.isActive')"
              class="mb-4"
            />

            <VDivider class="mb-4" />

            <!-- Section 4: Applicability -->
            <h6 class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center gap-2">
              <VIcon
                icon="tabler-target"
                size="20"
              />
              {{ t('coupons.sectionApplicability') }}
            </h6>

            <AppSelect
              v-model="form.applicable_plan_keys"
              :label="t('coupons.applicablePlans')"
              :placeholder="t('coupons.allPlans')"
              :items="planOptions"
              multiple
              chips
              closable-chips
              class="mb-4"
            />

            <div class="mb-4">
              <label class="text-body-2 font-weight-medium d-block mb-1">{{ t('coupons.billingCycles') }}</label>
              <VChipGroup
                v-model="form.applicable_billing_cycles"
                multiple
                selected-class="text-primary"
              >
                <VChip
                  value="monthly"
                  variant="outlined"
                >
                  {{ t('coupons.monthlyOnly') }}
                </VChip>
                <VChip
                  value="yearly"
                  variant="outlined"
                >
                  {{ t('coupons.yearlyOnly') }}
                </VChip>
              </VChipGroup>
              <span
                v-if="!form.applicable_billing_cycles?.length"
                class="text-caption text-medium-emphasis"
              >{{ t('coupons.allCycles') }}</span>
            </div>

            <AppSelect
              v-model="form.addon_mode"
              :label="t('coupons.addonMode')"
              :items="addonModeOptions"
              clearable
              class="mb-4"
            />

            <AppAutocomplete
              v-if="form.addon_mode"
              v-model="form.applicable_addon_keys"
              :label="t('coupons.applicableAddons')"
              :items="addonOptions"
              :menu-props="{ openOnClick: false }"
              multiple
              chips
              closable-chips
              class="mb-4"
            />

            <VDivider class="mb-4" />

            <!-- Section 5: Limits -->
            <h6 class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center gap-2">
              <VIcon
                icon="tabler-lock"
                size="20"
              />
              {{ t('coupons.sectionLimits') }}
            </h6>

            <AppTextField
              v-model.number="form.max_uses"
              :label="t('coupons.maxUses')"
              type="number"
              :placeholder="t('coupons.unlimited')"
              class="mb-4"
            />

            <AppTextField
              v-model.number="form.max_uses_per_company"
              :label="t('coupons.maxUsesPerCompany')"
              type="number"
              :hint="t('coupons.maxUsesPerCompanyHint')"
              class="mb-4"
            />

            <VSwitch
              v-model="form.first_purchase_only"
              :label="t('coupons.firstPurchaseOnly')"
              :hint="t('coupons.firstPurchaseOnlyHint')"
              persistent-hint
              class="mb-4"
            />

            <VDivider class="mb-4" />

            <!-- Section 6: Summary -->
            <h6 class="text-subtitle-1 font-weight-bold mb-3 d-flex align-center gap-2">
              <VIcon
                icon="tabler-clipboard-check"
                size="20"
              />
              {{ t('coupons.sectionSummary') }}
            </h6>

            <VAlert
              v-if="summaryText"
              type="info"
              variant="tonal"
              class="mb-4"
            >
              {{ summaryText }}
            </VAlert>

            <VBtn
              type="submit"
              block
              color="primary"
              :loading="isSaving"
            >
              {{ editingCoupon ? t('coupons.save') : t('coupons.create') }}
            </VBtn>
          </VForm>
        </VCardText>
      </VCard>
    </VNavigationDrawer>
  </Teleport>
</template>
