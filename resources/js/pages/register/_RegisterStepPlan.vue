<script setup>
import { formatMoney } from '@/utils/money'

const { t } = useI18n()

defineProps({
  plans: { type: Array, required: true },
  previewModules: { type: Array, default: () => [] },
})

const selectedPlan = defineModel('selectedPlan', { type: String })
const annualToggle = defineModel('annualToggle', { type: Boolean })
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('register.choosePlan') }}
  </h4>
  <p class="text-body-1 mb-4">
    {{ t('register.allPlansInclude') }}
  </p>

  <!-- Monthly/Annual toggle -->
  <div class="d-flex font-weight-medium text-body-1 align-center justify-center mb-6">
    <VLabel
      for="plan-toggle"
      class="me-3"
    >
      {{ t('common.monthly') }}
    </VLabel>
    <VSwitch
      id="plan-toggle"
      v-model="annualToggle"
    >
      <template #label>
        <div class="text-body-1 font-weight-medium">
          {{ t('common.annually') }}
        </div>
      </template>
    </VSwitch>
  </div>

  <VRow>
    <VCol
      v-for="plan in plans"
      :key="plan.key"
      cols="12"
      sm="4"
    >
      <VCard
        border
        :class="[
          'cursor-pointer plan-card',
          selectedPlan === plan.key ? 'border-primary border-opacity-100' : '',
          plan.is_popular ? 'plan-card--popular' : '',
        ]"
        :elevation="plan.is_popular ? 8 : 0"
        :flat="!plan.is_popular"
        @click="selectedPlan = plan.key"
      >
        <VCardText
          style="block-size: 2.5rem;"
          class="text-end"
        >
          <VChip
            v-if="plan.is_popular"
            label
            color="primary"
            variant="elevated"
          >
            {{ t('register.recommended') }}
          </VChip>
        </VCardText>

        <VCardText class="text-center">
          <h5 class="text-h5 mb-1">
            {{ plan.name }}
          </h5>
          <p class="text-body-2 mb-4">
            {{ plan.description }}
          </p>

          <div class="d-flex justify-center align-baseline pb-2">
            <span class="text-h3 font-weight-medium text-primary">
              {{ formatMoney(annualToggle ? Math.round(plan.price_yearly / 12 * 100) : plan.price_monthly * 100) }}
            </span>
            <span class="text-body-1 font-weight-medium">{{ t('common.perMonth') }}</span>
            <span class="text-caption text-disabled ms-1">{{ t('common.exclTax') }}</span>
          </div>

          <!-- Annual savings badge -->
          <VChip
            v-if="annualToggle && plan.price_yearly > 0 && plan.price_monthly > 0"
            color="success"
            variant="tonal"
            size="small"
            class="mb-3"
          >
            {{ t('register.annualSaving', { percent: Math.round((1 - plan.price_yearly / (plan.price_monthly * 12)) * 100) }) }}
          </VChip>
          <div
            v-else
            class="mb-3"
          />

          <VList
            density="compact"
            class="card-list"
          >
            <VListItem
              v-for="feature in plan.feature_labels"
              :key="feature"
            >
              <template #prepend>
                <VIcon
                  size="14"
                  icon="tabler-check"
                  color="success"
                />
              </template>
              <VListItemTitle class="text-body-2">
                {{ feature }}
              </VListItemTitle>
            </VListItem>
          </VList>

          <!-- ADR-287: Trial badge — prominent -->
          <VAlert
            v-if="plan.trial_days > 0"
            type="success"
            variant="tonal"
            density="compact"
            class="mt-3"
          >
            <template #prepend>
              <VIcon
                icon="tabler-gift"
                size="18"
              />
            </template>
            {{ t('companyPlan.freeTrialDays', { days: plan.trial_days }) }}
          </VAlert>
        </VCardText>
      </VCard>
    </VCol>
  </VRow>

  <!-- Module preview -->
  <div
    v-if="previewModules.length > 0"
    class="mt-4"
  >
    <p class="text-body-2 font-weight-medium mb-2">
      {{ t('register.includedModules') }}
    </p>
    <div class="d-flex flex-wrap gap-2">
      <VChip
        v-for="mod in previewModules"
        :key="mod.key"
        size="small"
        :color="mod.source === 'core' ? 'secondary' : 'primary'"
      >
        {{ mod.name }}
      </VChip>
    </div>
  </div>
</template>
