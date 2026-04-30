<script setup>
import { usePlatformPlansStore } from '@/modules/platform-admin/plans/plans.store'
import { useAppToast } from '@/composables/useAppToast'
import { formatMoney } from '@/utils/money'

// Sub-component — no definePage — hub: platform/catalog/[tab].vue

const { t } = useI18n()
const router = useRouter()
const plansStore = usePlatformPlansStore()
const { toast } = useAppToast()

const isLoading = ref(true)

// Create dialog
const isCreateDialogOpen = ref(false)
const createLoading = ref(false)
const createForm = ref({ key: '', name: '' })

onMounted(async () => {
  try {
    await plansStore.fetchPlans()
  }
  finally {
    isLoading.value = false
  }
})

const formatPrice = plan => {
  const cents = plan.price_monthly_dollars != null
    ? plan.price_monthly_dollars * 100
    : plan.price_monthly
  if (cents === 0)
    return t('common.free')

  return formatMoney(cents)
}

const formatYearlyPrice = plan => {
  const cents = plan.price_yearly_dollars != null
    ? plan.price_yearly_dollars * 100
    : plan.price_yearly
  if (cents === 0)
    return t('common.free')

  return `${formatMoney(cents)}${t('common.perYear')}`
}

const priceIsPositive = plan => {
  const cents = plan.price_monthly_dollars != null
    ? plan.price_monthly_dollars * 100
    : plan.price_monthly

  return cents > 0
}

const yearlyPriceIsPositive = plan => {
  const cents = plan.price_yearly_dollars != null
    ? plan.price_yearly_dollars * 100
    : plan.price_yearly

  return cents > 0
}

const openCreateDialog = () => {
  createForm.value = { key: '', name: '' }
  isCreateDialogOpen.value = true
}

const handleCreate = async () => {
  createLoading.value = true

  try {
    const data = await plansStore.createPlan({
      key: createForm.value.key,
      name: createForm.value.name,
      description: '',
      level: 0,
      price_monthly: 0,
      price_yearly: 0,
      is_popular: false,
      feature_labels: [],
      limits: {},
    })

    toast(data.message, 'success')
    isCreateDialogOpen.value = false
    router.push({ name: 'platform-plans-key', params: { key: data.plan.key } })
  }
  catch (error) {
    toast(error?.data?.message || t('plans.failedToCreate'), 'error')
  }
  finally {
    createLoading.value = false
  }
}

const navigateToPlan = key => {
  router.push({ name: 'platform-plans-key', params: { key } })
}
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('plans.title') }}
        </h4>
        <p class="text-body-1 mb-0">
          {{ t('plans.subtitle') }}
        </p>
      </div>

      <VBtn
        prepend-icon="tabler-plus"
        @click="openCreateDialog"
      >
        {{ t('plans.addPlan') }}
      </VBtn>
    </div>

    <VSkeletonLoader
      v-if="isLoading"
      type="card, card, card"
    />

    <VRow v-else>
      <VCol
        v-for="plan in plansStore.plans"
        :key="plan.id"
        cols="12"
        sm="6"
        md="4"
      >
        <VCard
          :style="plan.is_popular ? 'border: 2px solid rgb(var(--v-theme-primary))' : ''"
          class="plan-card"
        >
          <VCardText class="pa-6">
            <!-- Status + Popular badges -->
            <div class="d-flex justify-space-between align-center mb-4">
              <VChip
                v-if="plan.is_popular"
                color="primary"
                size="small"
                label
              >
                {{ t('common.popular') }}
              </VChip>
              <span v-else />

              <VChip
                :color="plan.is_active ? 'success' : 'secondary'"
                size="small"
                variant="tonal"
              >
                {{ plan.is_active ? t('common.active') : t('common.hidden') }}
              </VChip>
            </div>

            <!-- Plan name -->
            <h4 class="text-h4 text-center mb-2">
              {{ plan.name }}
            </h4>

            <!-- Price -->
            <div class="d-flex justify-center mb-2">
              <div class="d-flex align-end">
                <div class="pricing-title text-primary me-1">
                  {{ formatPrice(plan) }}
                </div>
                <span
                  v-if="priceIsPositive(plan)"
                  class="text-disabled mb-2"
                >{{ t('common.perMonth') }} <span class="text-caption">{{ t('common.exclTax') }}</span></span>
              </div>
            </div>

            <p
              v-if="yearlyPriceIsPositive(plan)"
              class="text-center text-disabled text-body-2 mb-4"
            >
              {{ formatYearlyPrice(plan) }} <span class="text-caption">{{ t('common.exclTax') }}</span>
            </p>
            <div
              v-else
              class="mb-4"
            />

            <!-- Features -->
            <VList
              v-if="plan.feature_labels?.length"
              class="card-list mb-4"
            >
              <VListItem
                v-for="(feature, i) in plan.feature_labels"
                :key="i"
                class="px-0"
              >
                <template #prepend>
                  <VAvatar
                    size="16"
                    :variant="plan.is_popular ? 'elevated' : 'tonal'"
                    color="primary"
                    class="me-3"
                  >
                    <VIcon
                      icon="tabler-check"
                      size="12"
                      :color="plan.is_popular ? 'white' : 'primary'"
                    />
                  </VAvatar>
                </template>
                <VListItemTitle class="text-body-2">
                  {{ feature }}
                </VListItemTitle>
              </VListItem>
            </VList>

            <div
              v-else
              class="text-center text-disabled text-body-2 mb-4 py-4"
            >
              {{ t('plans.noFeaturesConfigured') }}
            </div>

            <!-- Companies count -->
            <p class="text-center text-disabled text-body-2 mb-4">
              {{ plan.companies_count ?? 0 }} {{ (plan.companies_count ?? 0) === 1 ? 'company' : 'companies' }}
            </p>

            <!-- Configure button -->
            <VBtn
              block
              :variant="plan.is_popular ? 'elevated' : 'tonal'"
              @click="navigateToPlan(plan.key)"
            >
              {{ t('common.configure') }}
            </VBtn>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Empty state -->
      <VCol
        v-if="!plansStore.plans.length"
        cols="12"
      >
        <VCard>
          <VCardText class="text-center pa-8 text-disabled">
            <VIcon
              icon="tabler-chart-bar-off"
              size="48"
              class="mb-2"
            />
            <p class="text-body-1">
              {{ t('plans.noPlansConfigure') }}
            </p>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Create Dialog -->
    <VDialog
      v-model="isCreateDialogOpen"
      max-width="460"
    >
      <VCard :title="t('plans.createNewPlan')">
        <VCardText>
          <VForm @submit.prevent="handleCreate">
            <VRow>
              <VCol cols="12">
                <AppTextField
                  v-model="createForm.key"
                  :label="t('plans.keyLabel')"
                  :placeholder="t('plans.keyPlaceholder')"
                  :hint="t('plans.keyHint')"
                  persistent-hint
                />
              </VCol>

              <VCol cols="12">
                <AppTextField
                  v-model="createForm.name"
                  :label="t('common.name')"
                  :placeholder="t('plans.namePlaceholder')"
                />
              </VCol>

              <VCol cols="12">
                <div class="d-flex gap-3 justify-end">
                  <VBtn
                    variant="tonal"
                    color="secondary"
                    @click="isCreateDialogOpen = false"
                  >
                    {{ t('common.cancel') }}
                  </VBtn>
                  <VBtn
                    type="submit"
                    :loading="createLoading"
                    :disabled="!createForm.key || !createForm.name"
                  >
                    {{ t('plans.createAndConfigure') }}
                  </VBtn>
                </div>
              </VCol>
            </VRow>
          </VForm>
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>

<style lang="scss" scoped>
.card-list {
  --v-card-list-gap: 8px;
}

.pricing-title {
  font-size: 32px;
  font-weight: 800;
  line-height: 44px;
}

.plan-card {
  transition: box-shadow 0.2s ease;

  &:hover {
    box-shadow: 0 4px 18px rgba(var(--v-shadow-key-umbra-color), 0.14);
  }
}
</style>
